<?php

namespace YetiSearch\Semantic;

/**
 * Vectors are stored normalized to unit length as packed little-endian
 * float32, so cosine similarity is a plain dot product and a 512-dimension
 * vector takes 2 KB.
 */
final class VectorMath
{
    /**
     * @param float[] $vector
     * @return float[]
     */
    public static function normalize(array $vector): array
    {
        $sum = 0.0;
        foreach ($vector as $v) {
            $sum += $v * $v;
        }
        if ($sum <= 0.0) {
            return $vector;
        }

        $norm = sqrt($sum);
        foreach ($vector as $i => $v) {
            $vector[$i] = $v / $norm;
        }

        return $vector;
    }

    /**
     * @param float[] $vector
     */
    public static function pack(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /**
     * @return float[] Zero-indexed
     */
    public static function unpack(string $blob): array
    {
        $values = unpack('g*', $blob);

        return $values === false ? [] : array_values($values);
    }

    /**
     * Dot product of a query vector (zero-indexed) and a stored vector as
     * unpack() returns it before re-indexing (one-indexed). Skipping the
     * array_values() copy matters when this runs once per stored vector.
     *
     * @param float[] $query
     * @param array<int, float> $stored
     */
    public static function dotOneIndexed(array $query, array $stored): float
    {
        $sum = 0.0;
        $i = 1;
        foreach ($query as $q) {
            $sum += $q * $stored[$i++];
        }

        return $sum;
    }
}
