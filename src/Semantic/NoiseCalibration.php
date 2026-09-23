<?php

namespace YetiSearch\Semantic;

/**
 * The semantic noise gate, measured on one index: the `min_margin` at which
 * searches that mean nothing find nothing there.
 *
 * The gate asks how far the best document stands above the median document.
 * What nonsense scores on that question depends on the index as much as on
 * the model: the more documents there are, the more likely one of them lands
 * near any string at all. Measured with text-embedding-3-small at 512
 * dimensions on short product cards, nonsense stood at most 0.060 above the
 * median over 17 documents and real searches at least 0.141, while over 4,415
 * documents nonsense stood 0.124 to 0.196 above it and real searches 0.200 to
 * 0.362. No one number serves both, so each index measures its own.
 *
 * SemanticSearch::calibrate() embeds PROBES (made-up words, keyboard mashes,
 * placeholder Latin and everyday phrases nobody searches for) exactly as a
 * search is embedded, asks each the gate's question against the documents the
 * gate compares with, and this class sets `min_margin` at the mean probe
 * margin plus SPREAD standard deviations: 0.119 over the 17 cards above and
 * 0.200 over the 4,415. Two deviations rather than the highest probe, because
 * a few probes on any index land well above the rest, and the mean and
 * deviation of 88 probes move far less from one probe set to another than a
 * maximum or a high percentile does. The median plus a multiple of the median
 * absolute deviation and the 90th percentile were both tried on three indexes;
 * neither separated real searches from nonsense as well.
 *
 * A probe the index finds by keyword is not noise there (`just testing` on a
 * site full of test pages), so calibrate() leaves those out of the
 * measurement and lists them in excluded(), unless that would leave fewer
 * than MIN_PROBES. The gate uses minMarginAt() with the configured
 * `calibration_strictness`, worked out from the stored probe margins, so
 * changing it needs no new measurement.
 *
 * A calibration applies while it was measured with the current model id,
 * dimensions, query frame, gate filters and probe set (matches()), and while
 * the number of documents it was measured over has not moved by more than
 * DRIFT (drifted()).
 */
final class NoiseCalibration
{
    /** Use the measured value when there is one that applies. */
    public const MODE_AUTO = 'auto';

    /** Use the configured `min_margin` exactly, as 2.4.0 did. */
    public const MODE_OFF = 'off';

    public const MODES = [self::MODE_AUTO, self::MODE_OFF];

    /** `min_margin` is the mean probe margin plus this many standard deviations. */
    public const SPREAD = 2.0;

    /**
     * How far the number of documents may move, as a share of what was
     * measured, before the calibration stops applying.
     */
    public const DRIFT = 0.25;

    /**
     * Probes left after keyword hits are excluded, at the least. With fewer,
     * every probe is measured.
     */
    public const MIN_PROBES = 44;

    /** Part of probeSet(): changing how probes are measured makes every calibration stale. */
    private const METHOD = 'keyword-excluded';

    /** The gate applies the margin from ten documents up; below that there is nothing to calibrate. */
    public const MIN_DOCUMENTS = 10;

    /**
     * Strings that mean nothing to any catalog or knowledge base. None of them
     * is a query worth using to check the result (`asdf`, `qwerty`, `lorem
     * ipsum`, `xyzzy`, `zxcv`, `hjkl`, `blorft`), so those stay an honest
     * test. Changing the list changes probeSet(), which makes every stored
     * calibration stale.
     */
    public const PROBES = [
        // Made-up words.
        'vbrtk', 'plokmij', 'gzqwu', 'fnord', 'wibble', 'xqjzv', 'mrrph', 'blargh', 'snorgle', 'quuxy',
        'zorbex', 'kwyjibo', 'flimflam', 'grenk', 'yubbo', 'trazzle', 'vemply', 'ofnug', 'plimth', 'drubex',
        'skronk', 'glaven', 'jubjub', 'wuzzle', 'frobnitz', 'qazwyx', 'bleep bloop', 'zibber zabber', 'nurf',
        'kleptoflux',
        // Keyboard mashes.
        'sdfghj', 'jkjkjk', 'uiopuiop', 'mnbvcx', 'fjdksla', 'ghghgh', 'rtyu', 'poiuyt', 'dfgdfg', 'wsxedc',
        'lkjhg', 'ewqewq', 'yuiyui', 'bnmbnm', 'rfvtgb', 'ujmik', 'fghfgh', 'kjhkjh', 'cvbcvb', 'tyutyu',
        // Placeholder Latin.
        'dolor sit amet', 'consectetur adipiscing', 'sed do eiusmod', 'tempor incididunt', 'ut labore et dolore',
        'magna aliqua', 'quis nostrud exercitation', 'duis aute irure',
        // Everyday phrases nobody searches a catalog for.
        'hello there', 'how are you', 'thank you very much', 'see you later', 'nothing to see here',
        'please call me back', 'what does this mean', 'just testing', 'placeholder text', 'undefined',
        'aaaaaa', 'xxxxxx', '1234567', 'yes or no', 'i am not sure',
        'good morning', 'never mind', 'is anybody there', 'the end', 'where am i',
        'whatever', 'maybe later', 'lol', 'ok thanks', 'no idea',
        'why is the sky', 'once upon a time', 'to be or not to be', 'error 404', 'null',
    ];

    private string $model;
    private int $dimensions;
    private int $documents;
    private string $probeSet;
    private string $frame;
    private array $gateFilters;
    /** @var array{count:int, min:float, median:float, mean:float, sd:float, p95:float, max:float} */
    private array $noise;
    /** @var array<string, array{best:float, median:float, margin:float}> */
    private array $probes;
    /** @var string[] */
    private array $excluded;
    private float $minMargin;
    private int $measuredAt;

    /**
     * @param array $gateFilters the filters the gate's subset was measured with
     * @param array{count:int, min:float, median:float, mean:float, sd:float, p95:float, max:float} $noise
     * @param array<string, array{best:float, median:float, margin:float}> $probes each probe's gate question
     */
    public function __construct(
        string $model,
        int $dimensions,
        int $documents,
        string $probeSet,
        string $frame,
        array $gateFilters,
        array $noise,
        array $probes,
        float $minMargin,
        int $measuredAt,
        array $excluded = []
    ) {
        $this->model = $model;
        $this->dimensions = $dimensions;
        $this->documents = $documents;
        $this->probeSet = $probeSet;
        $this->frame = $frame;
        $this->gateFilters = array_values($gateFilters);
        $this->noise = $noise;
        $this->probes = $probes;
        $this->minMargin = $minMargin;
        $this->measuredAt = $measuredAt;
        $this->excluded = array_values(array_map('strval', $excluded));
    }

    /**
     * A calibration from each probe's gate question, the rule applied.
     *
     * @param array<string, array{best:float, median:float, margin:float}> $measured NoiseCalibration::measure()
     * @param string[] $excluded probes left out as keyword hits
     */
    public static function fromMeasurement(
        string $model,
        int $dimensions,
        int $documents,
        string $frame,
        array $gateFilters,
        array $measured,
        ?int $now = null,
        array $excluded = []
    ): self {
        $margins = array_column($measured, 'margin');

        return new self(
            $model,
            $dimensions,
            $documents,
            self::probeSet(),
            $frame,
            $gateFilters,
            self::distribution($margins),
            $measured,
            self::ceiling($margins),
            $now ?? time(),
            $excluded
        );
    }

    /** The probe set's version: changing PROBES, SPREAD or how probes are measured makes every calibration stale. */
    public static function probeSet(): string
    {
        return substr(sha1(implode("\n", self::PROBES) . "\0" . self::SPREAD . "\0" . self::METHOD), 0, 12);
    }

    /**
     * The noise ceiling: the mean margin plus $spread standard deviations
     * (SPREAD by default), to four places.
     *
     * @param float[] $margins
     */
    public static function ceiling(array $margins, float $spread = self::SPREAD): float
    {
        // Unrounded, unlike distribution(), so the ceiling carries no rounding.
        $margins = array_values(array_map('floatval', $margins));
        $n = count($margins);
        $mean = $n > 0 ? array_sum($margins) / $n : 0.0;
        $variance = 0.0;
        foreach ($margins as $margin) {
            $variance += ($margin - $mean) ** 2;
        }
        $sd = $n > 0 ? sqrt($variance / $n) : 0.0;

        return round(max(0.0, min(1.0, $mean + $spread * $sd)), 4);
    }

    /**
     * @param float[] $margins
     * @return array{count:int, min:float, median:float, mean:float, sd:float, p95:float, max:float}
     */
    public static function distribution(array $margins): array
    {
        $margins = array_values(array_map('floatval', $margins));
        $n = count($margins);
        if ($n === 0) {
            return [
                'count' => 0, 'min' => 0.0, 'median' => 0.0, 'mean' => 0.0, 'sd' => 0.0, 'p95' => 0.0, 'max' => 0.0,
            ];
        }

        sort($margins);
        $mean = array_sum($margins) / $n;
        $variance = 0.0;
        foreach ($margins as $margin) {
            $variance += ($margin - $mean) ** 2;
        }

        $at = 0.95 * ($n - 1);
        $low = (int)floor($at);
        $high = min($low + 1, $n - 1);

        return [
            'count' => $n,
            'min' => round($margins[0], 4),
            'median' => round($margins[intdiv($n, 2)], 4),
            'mean' => round($mean, 4),
            'sd' => round(sqrt($variance / $n), 4),
            'p95' => round($margins[$low] + ($margins[$high] - $margins[$low]) * ($at - $low), 4),
            'max' => round($margins[$n - 1], 4),
        ];
    }

    /**
     * The gate's question for each probe: the best document, the median
     * document and the margin between them, worked out as
     * SqliteStorage::nearestVectors() does (the median is the middle of the
     * sorted similarities, `intdiv(n, 2)`).
     *
     * One pass over the vectors, each compared with every probe.
     *
     * @param array<string, float[]> $probeVectors probe => normalized vector, zero-indexed
     * @param iterable<float[]> $vectors normalized, zero-indexed
     * @param int|null $documents set to the number of vectors read
     * @return array<string, array{best:float, median:float, margin:float}>
     */
    public static function measure(array $probeVectors, iterable $vectors, ?int &$documents = null): array
    {
        $documents = 0;
        $similarities = array_fill_keys(array_keys($probeVectors), []);
        foreach ($vectors as $vector) {
            $documents++;
            foreach ($probeVectors as $probe => $probeVector) {
                $dot = 0.0;
                foreach ($vector as $i => $x) {
                    $dot += $x * ($probeVector[$i] ?? 0.0);
                }
                $similarities[$probe][] = $dot;
            }
        }

        $out = [];
        foreach ($similarities as $probe => $scores) {
            if ($scores === []) {
                continue;
            }
            sort($scores);
            $best = $scores[count($scores) - 1];
            $median = $scores[intdiv(count($scores), 2)];
            $out[(string)$probe] = [
                'best' => round($best, 4),
                'median' => round($median, 4),
                'margin' => round($best - $median, 4),
            ];
        }

        return $out;
    }

    /**
     * Whether this calibration was measured with this model id, these
     * dimensions (when known), this query frame and gate subset, and the
     * current probe set.
     */
    public function matches(string $model, int $dimensions = 0, string $frame = '', array $gateFilters = []): bool
    {
        return $this->model === $model
            && ($dimensions <= 0 || $this->dimensions <= 0 || $this->dimensions === $dimensions)
            && $this->frame === $frame
            && self::filtersKey($this->gateFilters) === self::filtersKey($gateFilters)
            && $this->probeSet === self::probeSet();
    }

    /** Whether the number of documents has moved more than DRIFT from what was measured. */
    public function drifted(int $documents): bool
    {
        return abs($documents - $this->documents) > self::DRIFT * max(1, $this->documents);
    }

    /** matches() and not drifted(): the gate may use minMargin(). */
    public function applies(string $model, int $dimensions, string $frame, array $gateFilters, int $documents): bool
    {
        return $this->matches($model, $dimensions, $frame, $gateFilters) && !$this->drifted($documents);
    }

    public function model(): string
    {
        return $this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /** How many documents the gate compared the probes with. */
    public function documents(): int
    {
        return $this->documents;
    }

    public function probeSetVersion(): string
    {
        return $this->probeSet;
    }

    public function frame(): string
    {
        return $this->frame;
    }

    public function gateFilters(): array
    {
        return $this->gateFilters;
    }

    /**
     * The probe margins' distribution.
     *
     * @return array{count:int, min:float, median:float, mean:float, sd:float, p95:float, max:float}
     */
    public function stats(): array
    {
        return $this->noise;
    }

    /**
     * Each probe's best document, median document and margin.
     *
     * @return array<string, array{best:float, median:float, margin:float}>
     */
    public function probes(): array
    {
        return $this->probes;
    }

    /** The noise ceiling at SPREAD standard deviations, as measured. */
    public function minMargin(): float
    {
        return $this->minMargin;
    }

    /**
     * The noise ceiling at $spread standard deviations, from the stored probe
     * margins. minMargin() when $spread is SPREAD.
     */
    public function minMarginAt(float $spread): float
    {
        if (abs($spread - self::SPREAD) < 1e-9 || $this->probes === []) {
            return $this->minMargin;
        }

        return self::ceiling(array_column($this->probes, 'margin'), $spread);
    }

    /**
     * Probes left out of the measurement because the index finds them by
     * keyword.
     *
     * @return string[]
     */
    public function excluded(): array
    {
        return $this->excluded;
    }

    public function measuredAt(): int
    {
        return $this->measuredAt;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        if (!isset($data['model'], $data['min_margin'])) {
            return null;
        }

        $probes = [];
        foreach ((array)($data['probes'] ?? []) as $probe => $values) {
            $probes[(string)$probe] = self::numbers((array)$values);
        }

        return new self(
            (string)$data['model'],
            (int)($data['dimensions'] ?? 0),
            (int)($data['documents'] ?? 0),
            (string)($data['probe_set'] ?? ''),
            (string)($data['frame'] ?? ''),
            (array)($data['gate_filters'] ?? []),
            self::numbers((array)($data['stats'] ?? []), ['count']),
            $probes,
            (float)$data['min_margin'],
            (int)($data['measured_at'] ?? 0),
            (array)($data['excluded'] ?? [])
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'dimensions' => $this->dimensions,
            'documents' => $this->documents,
            'probe_set' => $this->probeSet,
            'frame' => $this->frame,
            'gate_filters' => $this->gateFilters,
            'min_margin' => $this->minMargin,
            'measured_at' => $this->measuredAt,
            'stats' => $this->noise,
            'probes' => $this->probes,
            'excluded' => $this->excluded,
        ];
    }

    private static function filtersKey(array $filters): string
    {
        return (string)json_encode(array_values($filters));
    }

    /**
     * JSON writes 0.0 as 0: numbers read back as the floats they were, and
     * the named keys as ints.
     *
     * @param array<string, mixed> $values
     * @param string[] $ints
     * @return array<string, float|int>
     */
    private static function numbers(array $values, array $ints = []): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = in_array($key, $ints, true) ? (int)$value : (float)$value;
        }

        return $values;
    }
}
