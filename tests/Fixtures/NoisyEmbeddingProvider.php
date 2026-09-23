<?php

namespace YetiSearch\Tests\Fixtures;

/**
 * FakeEmbeddingProvider with a deterministic hash of every word spread over
 * extra dimensions. Concept words still pull related texts together, while
 * text with no concept word (nonsense, the calibration probes) lands
 * somewhere of its own instead of on one shared vector, so probe margins
 * have a spread to measure.
 */
class NoisyEmbeddingProvider extends FakeEmbeddingProvider
{
    public const NOISE_DIMS = 16;

    private float $amplitude;

    public function __construct(string $model = 'fake-noisy', float $amplitude = 0.35)
    {
        parent::__construct($model);
        $this->amplitude = $amplitude;
    }

    public function embed(array $texts, string $purpose = self::PURPOSE_DOCUMENT): array
    {
        $vectors = parent::embed($texts, $purpose);
        foreach (array_values($texts) as $i => $text) {
            $noise = array_fill(0, self::NOISE_DIMS, 0.0);
            $words = preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($words as $word) {
                $hash = sha1($word, true);
                for ($d = 0; $d < self::NOISE_DIMS; $d++) {
                    $noise[$d] += (ord($hash[$d]) / 255.0 - 0.5) * $this->amplitude;
                }
            }
            $vectors[$i] = array_merge($vectors[$i], $noise);
        }

        return $vectors;
    }

    public function dimensions(): int
    {
        return parent::dimensions() + self::NOISE_DIMS;
    }
}
