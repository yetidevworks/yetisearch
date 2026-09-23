<?php

namespace YetiSearch\Semantic;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use YetiSearch\Contracts\EmbeddingProviderInterface;
use YetiSearch\Exceptions\EmbeddingException;
use YetiSearch\Storage\SqliteStorage;

/**
 * Embeds an index's documents and ranks them against a query by meaning.
 *
 * Embedding is a separate step from indexing, so a slow or failing provider
 * never holds up or breaks an index run: index as usual, then call
 * embedPending() until nothing is left. Only documents whose text changed
 * since their last embedding are sent to the provider.
 */
class SemanticSearch
{
    private EmbeddingProviderInterface $provider;
    private SqliteStorage $storage;
    private array $config;
    private LoggerInterface $logger;
    private CalibrationStore $calibrationStore;

    public function __construct(
        EmbeddingProviderInterface $provider,
        SqliteStorage $storage,
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->provider = $provider;
        $this->storage = $storage;
        $this->config = array_merge(self::defaults(), $config);
        $this->logger = $logger ?? new NullLogger();
        $store = $this->config['calibration_store'] ?? null;
        $this->calibrationStore = $store instanceof CalibrationStore ? $store : $storage;
    }

    public static function defaults(): array
    {
        return [
            // Share of the final ranking that comes from meaning rather than
            // keywords: 0 is keyword search, 1 is semantic search alone.
            'weight' => 0.5,
            // Reciprocal rank fusion constant. Higher flattens the difference
            // between neighbouring ranks.
            'rrf_k' => 60,
            // How many results each side contributes before fusion.
            'candidates' => 100,
            // Documents less similar than this never enter the results on
            // meaning alone. Where related text lands depends on the model:
            // around 0.3 to 0.6 for OpenAI's text-embedding-3, higher for
            // nomic-embed-text.
            'min_similarity' => 0.25,
            // How far the best match must stand above the median document
            // before meaning adds any results. A query that means nothing in
            // particular ("asdf", a typo storm) is about as similar to every
            // document; a real one stands out. Measured with
            // text-embedding-3-small: 0.23 to 0.47 for real queries, 0.12 to
            // 0.14 for noise. Applies from 10 candidate documents up, where a
            // median means something.
            'min_margin' => 0.15,
            // Documents must be at least this share as similar as the best
            // match, which trims the long tail of loosely related ones.
            'relative_similarity' => 0.6,
            // Document fields that make up the embedded text.
            'fields' => ['title', 'content'],
            // Characters of text per document. Keeps every document inside
            // the model's input limit.
            'max_chars' => 6000,
            // Documents per provider call in embedPending().
            'batch_size' => 32,
            // Query embeddings kept in the database, so a repeated search
            // costs no provider call.
            'query_cache_size' => 5000,
            // 'auto' gates on the min_margin measured for the index (see
            // calibrate() and NoiseCalibration) while that measurement still
            // describes it, and on min_margin above otherwise. 'off' always
            // uses min_margin, exactly as 2.4.0 did.
            'calibration' => NoiseCalibration::MODE_AUTO,
            // Filters that pick the documents the noise gate compares a
            // query with: its best match and median are taken over these
            // alone, while every document is still ranked. Empty means all.
            'gate_filters' => [],
            // A sentence a query is put in before it is embedded, holding
            // {query}: 'a {query}' sends `hat` as `a hat`, which a model
            // reads as a thing rather than a word. Documents are not framed.
            'query_frame' => null,
            // Where calibrations and probe vectors are kept: a
            // CalibrationStore, or null for the index's own database.
            'calibration_store' => null,
        ];
    }

    public function getProvider(): EmbeddingProviderInterface
    {
        return $this->provider;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Embed up to $limit documents that have no vector, or whose text or
     * model changed since they got one.
     *
     * @return array{embedded:int, pending:int, total:int, pruned:int, error:?string,
     *                calibrated:bool, calibration_error:?string}
     *         pending is what is still left after this call. calibrated is
     *         true when the run finished the index and measured its noise
     *         gate again (see calibrate()).
     */
    public function embedPending(string $index, int $limit = 200): array
    {
        $pruned = $this->storage->pruneVectors($index);
        $scan = $this->scan($index, max(0, $limit));
        $model = $this->provider->modelId();

        $embedded = 0;
        $error = null;
        foreach (array_chunk($scan['queue'], max(1, (int)$this->config['batch_size'])) as $batch) {
            try {
                $vectors = $this->provider->embed(
                    array_column($batch, 'text'),
                    EmbeddingProviderInterface::PURPOSE_DOCUMENT
                );
            } catch (\Throwable $e) {
                // Keep what earlier batches stored; the rest stays pending.
                $error = $e->getMessage();
                $this->logger->warning('Embedding failed; documents stay pending', [
                    'index' => $index,
                    'error' => $error,
                ]);
                break;
            }

            $rows = [];
            foreach ($batch as $i => $doc) {
                $rows[] = [
                    'id' => $doc['id'],
                    'hash' => $doc['hash'],
                    'model' => $model,
                    'vector' => VectorMath::normalize($vectors[$i]),
                ];
            }
            $this->storage->upsertVectors($index, $rows);
            $embedded += count($rows);
        }

        $result = [
            'embedded' => $embedded,
            'pending' => $scan['pending'] - $embedded,
            'total' => $scan['total'],
            'pruned' => $pruned,
            'error' => $error,
            'calibrated' => false,
            'calibration_error' => null,
        ];

        // Once everything is embedded, measure the noise gate again if what
        // was measured no longer describes the index. Rarely: only for a new
        // model, frame or gate subset, or a quarter more or fewer documents.
        if ($error === null && $result['pending'] === 0 && $this->calibrationStale($index)) {
            try {
                $result['calibrated'] = $this->calibrate($index) !== null;
            } catch (\Throwable $e) {
                $result['calibration_error'] = $e->getMessage();
                $this->logger->warning('Noise calibration failed; the gate uses min_margin', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Measure the noise gate on an index and store the result: embed
     * NoiseCalibration::PROBES as queries are embedded (from the probe cache
     * where they are there, in one provider call for the rest), ask each the
     * gate's question against the gate's documents, and set min_margin at the
     * mean probe margin plus two standard deviations.
     *
     * Only the probes the cache lacks cost a provider call, so run it where a
     * paid call is fine, never while somebody waits on a search. Null, with
     * nothing stored, when fewer than ten of the gate's documents hold a
     * vector, since the gate applies no margin below that.
     *
     * @param array|null $gateFilters The documents to measure over; null for
     *        the configured gate_filters. The gate only uses a calibration
     *        measured with the filters it is configured with.
     * @throws EmbeddingException When the provider fails
     */
    public function calibrate(string $index, ?array $gateFilters = null, ?int $now = null): ?NoiseCalibration
    {
        $filters = array_values($gateFilters ?? $this->gateFilters());
        $model = $this->provider->modelId();

        // Counted first, so an index too small for the gate costs no provider call.
        if ($this->storage->countVectors($index, $model, $filters) < NoiseCalibration::MIN_DOCUMENTS) {
            return null;
        }

        $probeVectors = $this->probeVectors();
        $measured = NoiseCalibration::measure(
            $probeVectors,
            $this->storage->iterateVectors($index, $model, $filters),
            $documents
        );
        if ($documents < NoiseCalibration::MIN_DOCUMENTS) {
            return null;
        }

        $first = reset($probeVectors);
        $calibration = NoiseCalibration::fromMeasurement(
            $model,
            is_array($first) ? count($first) : 0,
            (int)$documents,
            $this->frame(),
            $filters,
            $measured,
            $now
        );
        $this->calibrationStore->saveCalibration($index, $calibration->toArray());

        return $calibration;
    }

    /**
     * The calibration last stored for an index, whatever it was measured
     * with, or null. gate() says whether it applies.
     */
    public function calibration(string $index): ?NoiseCalibration
    {
        try {
            $data = $this->calibrationStore->loadCalibration($index);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not read the noise calibration', [
                'index' => $index,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return is_array($data) ? NoiseCalibration::fromArray($data) : null;
    }

    /**
     * The min_margin the gate uses on an index and where it comes from:
     * 'calibrated' while calibration is 'auto' and the stored calibration was
     * measured with the current model, dimensions, frame, gate filters and
     * probe set over a number of documents within a quarter of today's,
     * 'configured' otherwise.
     *
     * @param int|null $documents The gate's documents holding a vector, when
     *        the caller already knows; counted otherwise
     * @return array{min_margin:float, source:string, configured:float, calibration:?NoiseCalibration}
     */
    public function gate(string $index, ?int $documents = null): array
    {
        $configured = (float)$this->config['min_margin'];
        $gate = [
            'min_margin' => $configured,
            'source' => 'configured',
            'configured' => $configured,
            'calibration' => null,
        ];
        if ($this->calibrationMode() !== NoiseCalibration::MODE_AUTO) {
            return $gate;
        }

        $calibration = $this->calibration($index);
        $gate['calibration'] = $calibration;
        if ($calibration === null) {
            return $gate;
        }

        $model = $this->provider->modelId();
        if (!$calibration->matches($model, $this->provider->dimensions(), $this->frame(), $this->gateFilters())) {
            return $gate;
        }
        try {
            $documents = $documents ?? $this->storage->countVectors($index, $model, $this->gateFilters());
        } catch (\Throwable $e) {
            return $gate;
        }
        if (!$calibration->drifted($documents)) {
            $gate['min_margin'] = $calibration->minMargin();
            $gate['source'] = 'calibrated';
        }

        return $gate;
    }

    /**
     * Whether calibration is 'auto' and the stored calibration is missing or
     * no longer applies (gate() would use the configured min_margin).
     */
    public function calibrationStale(string $index): bool
    {
        if ($this->calibrationMode() !== NoiseCalibration::MODE_AUTO) {
            return false;
        }

        return $this->gate($index)['source'] !== 'calibrated';
    }

    public function calibrationMode(): string
    {
        return ($this->config['calibration'] ?? NoiseCalibration::MODE_AUTO) === NoiseCalibration::MODE_OFF
            ? NoiseCalibration::MODE_OFF
            : NoiseCalibration::MODE_AUTO;
    }

    /**
     * The probes' vectors under this provider and frame, from the cache where
     * it has them and from one provider call for the rest, which are cached.
     *
     * @return array<string, float[]> probe => normalized vector
     * @throws EmbeddingException
     */
    public function probeVectors(): array
    {
        $model = $this->provider->modelId();
        $frame = $this->frame();
        $cached = $this->calibrationStore->loadProbeVectors($model, $frame);

        $missing = [];
        foreach (NoiseCalibration::PROBES as $probe) {
            $text = $this->queryText($probe);
            if (empty($cached[$text])) {
                $missing[$text] = true;
            }
        }

        if (!empty($missing)) {
            // array_keys() turns a numeric-string key such as '1234567' into an
            // int, which an OpenAI-compatible API rejects inside `input`.
            $texts = array_map('strval', array_keys($missing));
            $embedded = $this->provider->embed($texts, EmbeddingProviderInterface::PURPOSE_QUERY);
            $new = [];
            foreach ($texts as $i => $text) {
                if (empty($embedded[$i])) {
                    throw new EmbeddingException('The provider returned no vector for a calibration probe');
                }
                $new[(string)$text] = VectorMath::normalize(array_map('floatval', $embedded[$i]));
            }
            $this->calibrationStore->saveProbeVectors($model, $frame, $new);
            $cached = $new + $cached;
        }

        $out = [];
        foreach (NoiseCalibration::PROBES as $probe) {
            $out[$probe] = array_values($cached[$this->queryText($probe)]);
        }

        return $out;
    }

    /**
     * @return array{model:string, total:int, embedded:int, pending:int}
     */
    public function stats(string $index): array
    {
        $scan = $this->scan($index, 0);

        return [
            'model' => $this->provider->modelId(),
            'total' => $scan['total'],
            'embedded' => $scan['total'] - $scan['pending'],
            'pending' => $scan['pending'],
        ];
    }

    public function clear(string $index): void
    {
        $this->storage->clearVectors($index);
        if ($this->calibrationStore !== $this->storage) {
            $this->calibrationStore->deleteCalibration($index);
        }
    }

    public function hasVectors(string $index): bool
    {
        return $this->storage->hasVectors($index, $this->provider->modelId());
    }

    /**
     * The query as a normalized vector, from the cache when it was embedded
     * before.
     *
     * @return float[]
     * @throws EmbeddingException
     */
    public function embedQuery(string $query): array
    {
        $text = $this->queryText($query);
        $key = sha1($this->provider->modelId() . "\0" . $text);

        $cached = $this->storage->getCachedQueryEmbedding($key);
        if ($cached !== null) {
            return $cached;
        }

        $vectors = $this->provider->embed([$text], EmbeddingProviderInterface::PURPOSE_QUERY);
        if (empty($vectors[0])) {
            throw new EmbeddingException('The provider returned no vector for the query');
        }
        $vector = VectorMath::normalize($vectors[0]);
        $this->storage->cacheQueryEmbedding($key, $vector, (int)$this->config['query_cache_size']);

        return $vector;
    }

    /**
     * The documents closest to the query, after the noise gate.
     *
     * The gate asks how far the best match stands above the median document,
     * both taken over the gate_filters subset when it is set (the documents
     * that say what a thing is, product cards say) and over every candidate
     * otherwise, in the same pass that ranks every candidate. With a subset,
     * its best match must also clear min_similarity. The margin applies from
     * ten gate documents up. A calibrated min_margin is the noise ceiling, so
     * a query must stand above it; the configured one is a floor to reach, as
     * in 2.4.0.
     *
     * @param float[] $queryVector
     * @return array<string, float> Document id => similarity, best first
     */
    public function nearest(string $index, array $queryVector, array $filters, ?string $language, int $k): array
    {
        $stats = null;
        $gateFilters = $this->gateFilters();
        $nearest = $this->storage->nearestVectors(
            $index,
            $queryVector,
            $this->provider->modelId(),
            $filters,
            $language,
            $k,
            (float)$this->config['min_similarity'],
            $stats,
            $gateFilters
        );
        if (empty($nearest)) {
            return [];
        }

        $best = reset($nearest);
        $count = (int)($stats['count'] ?? 0);
        $gateBest = $stats['best'] ?? $best;
        if (!empty($gateFilters) && $count > 0 && $gateBest < (float)$this->config['min_similarity']) {
            return [];
        }

        if ($count >= 10) {
            // Without search filters the gate's candidates are all of its
            // documents, so their count needs no query of its own.
            $gate = $this->gate($index, empty($filters) && !$language ? $count : null);
            $margin = $gateBest - (float)($stats['median'] ?? 0.0);
            $noise = $gate['source'] === 'calibrated'
                ? $margin <= $gate['min_margin']
                : $margin < $gate['min_margin'];
            if ($noise) {
                return [];
            }
        }

        $cutoff = $best * (float)$this->config['relative_similarity'];

        return array_filter($nearest, function ($similarity) use ($cutoff) {
            return $similarity >= $cutoff;
        });
    }

    /**
     * Document rows in the form a keyword search returns them.
     *
     * @param string[] $ids
     * @return array<string, array>
     */
    public function getSearchRows(string $index, array $ids): array
    {
        return $this->storage->getSearchRows($index, $ids);
    }

    /**
     * Merge a keyword ranking and a vector ranking with reciprocal rank
     * fusion. A document scores weight/(k + rank) from each list it is in,
     * so agreement between the two lifts it and BM25 scores never have to be
     * compared with similarities.
     *
     * The vector share is also scaled by the document's similarity relative
     * to the best match. Plain rank fusion would score the second-closest
     * document almost as high as the closest however far apart they are
     * (0.55 and 0.25 would show as 100 and 98); this keeps that gap visible
     * without changing the order within the vector list.
     *
     * @param string[] $keywordIds Best first
     * @param array<string, float> $vectorScores Id => similarity, best first
     * @return array<string, float> Id => score, best first, scaled so a
     *         document ranked first in both lists scores 100
     */
    public static function fuse(array $keywordIds, array $vectorScores, float $weight, int $k = 60): array
    {
        $weight = max(0.0, min(1.0, $weight));
        $k = max(1, $k);

        $scores = [];
        foreach (array_values($keywordIds) as $rank => $id) {
            $scores[$id] = (1.0 - $weight) / ($k + $rank + 1);
        }
        $best = empty($vectorScores) ? 0.0 : max($vectorScores);
        $rank = 0;
        foreach ($vectorScores as $id => $similarity) {
            $relative = $best > 0.0 ? max(0.0, $similarity) / $best : 1.0;
            $scores[$id] = ($scores[$id] ?? 0.0) + $weight / ($k + ++$rank) * $relative;
        }

        $best = 1.0 / ($k + 1);
        foreach ($scores as $id => $score) {
            $scores[$id] = $score / $best * 100.0;
        }

        // Stable for ties: array order (keyword results first) decides.
        $order = array_flip(array_keys($scores));
        uksort($scores, function ($a, $b) use ($scores, $order) {
            return ($scores[$b] <=> $scores[$a]) ?: ($order[$a] <=> $order[$b]);
        });

        return $scores;
    }

    /**
     * The text a document is embedded as: its configured fields, as plain
     * text, cut to max_chars.
     */
    public function documentText(array $content): string
    {
        $parts = [];
        foreach ((array)$this->config['fields'] as $field) {
            $value = $content[$field] ?? null;
            if (is_array($value)) {
                $value = implode(', ', array_filter($value, 'is_scalar'));
            }
            if (!is_scalar($value)) {
                continue;
            }
            $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim((string)preg_replace('/\s+/u', ' ', $text));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        $text = implode("\n\n", $parts);
        $max = (int)$this->config['max_chars'];
        if ($max > 0 && mb_strlen($text, 'UTF-8') > $max) {
            $text = mb_substr($text, 0, $max, 'UTF-8');
        }

        return $text;
    }

    public static function normalizeQuery(string $query): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', mb_strtolower($query, 'UTF-8')));
    }

    /**
     * The text a query is embedded as: normalized, then put in query_frame
     * when one is set.
     */
    public function queryText(string $query): string
    {
        $text = self::normalizeQuery($query);
        $frame = $this->frame();

        return $frame === '' ? $text : str_replace('{query}', $text, $frame);
    }

    /**
     * The query frame in use: '' when none is set or it would change nothing
     * (blank, `{query}` alone, or no `{query}` in it).
     */
    public function frame(): string
    {
        $frame = trim((string)($this->config['query_frame'] ?? ''));
        if ($frame === '{query}' || strpos($frame, '{query}') === false) {
            return '';
        }

        return $frame;
    }

    /**
     * The configured gate_filters, as a list.
     */
    public function gateFilters(): array
    {
        return array_values((array)($this->config['gate_filters'] ?? []));
    }

    public function getCalibrationStore(): CalibrationStore
    {
        return $this->calibrationStore;
    }

    /**
     * Walk the index once, counting documents that need a vector and queuing
     * up to $limit of them.
     *
     * @return array{total:int, pending:int, queue:array<int, array{id:string, hash:string, text:string}>}
     */
    private function scan(string $index, int $limit): array
    {
        $model = $this->provider->modelId();
        $total = 0;
        $pending = 0;
        $queue = [];

        foreach ($this->storage->iterateDocumentsForEmbedding($index) as $doc) {
            // A chunked document's own row repeats the text its chunks carry;
            // the chunks are what get embedded. Callers that pre-chunk can
            // mark a short document chunked with zero chunks, and that one
            // has only its own row to embed.
            $meta = $doc['metadata'];
            if (!empty($meta['chunked']) && empty($meta['is_chunk']) && (int)($meta['chunks'] ?? 1) > 0) {
                continue;
            }

            $text = $this->documentText($doc['content']);
            if ($text === '') {
                continue;
            }

            $total++;
            $hash = sha1($text);
            if ($doc['hash'] === $hash && $doc['model'] === $model) {
                continue;
            }

            $pending++;
            if (count($queue) < $limit) {
                $queue[] = ['id' => $doc['id'], 'hash' => $hash, 'text' => $text];
            }
        }

        return ['total' => $total, 'pending' => $pending, 'queue' => $queue];
    }
}
