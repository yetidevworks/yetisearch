<?php

namespace YetiSearch\Utils;

/**
 * Levenshtein distance calculator for fuzzy string matching
 */
class Levenshtein
{
    /**
     * Calculate Levenshtein distance between two strings, in characters
     *
     * @param string $str1
     * @param string $str2
     * @return int The edit distance
     */
    public static function distance(string $str1, string $str2): int
    {
        if ($str1 === $str2) {
            return 0;
        }

        // ASCII strings take PHP's own implementation, which counts bytes (and before PHP 8
        // takes at most 255 of them)
        if (strlen($str1) <= 255 && strlen($str2) <= 255 && !preg_match('/[\x80-\xFF]/', $str1 . $str2)) {
            return levenshtein($str1, $str2);
        }

        $a = mb_str_split($str1);
        $b = mb_str_split($str2);
        $len2 = count($b);
        if ($a === [] || $len2 === 0) {
            return max(count($a), $len2);
        }

        // Two rows of the matrix are enough
        $previous = range(0, $len2);
        foreach ($a as $i => $char) {
            $current = [$i + 1];
            foreach ($b as $j => $other) {
                $current[$j + 1] = min(
                    $previous[$j + 1] + 1,                     // deletion
                    $current[$j] + 1,                          // insertion
                    $previous[$j] + ($char === $other ? 0 : 1) // substitution
                );
            }
            $previous = $current;
        }

        return $previous[$len2];
    }

    /**
     * Calculate normalized Levenshtein distance (0.0 to 1.0)
     *
     * @param string $str1
     * @param string $str2
     * @return float Normalized distance where 0 = identical, 1 = completely different
     */
    public static function normalizedDistance(string $str1, string $str2): float
    {
        $maxLen = max(mb_strlen($str1), mb_strlen($str2));
        if ($maxLen === 0) {
            return 0.0;
        }

        return self::distance($str1, $str2) / $maxLen;
    }

    /**
     * Calculate similarity score based on Levenshtein distance
     *
     * @param string $str1
     * @param string $str2
     * @return float Similarity score from 0.0 to 1.0 where 1.0 = identical
     */
    public static function similarity(string $str1, string $str2): float
    {
        return 1.0 - self::normalizedDistance($str1, $str2);
    }

    /**
     * Check if two strings are within a given edit distance
     *
     * @param string $str1
     * @param string $str2
     * @param int $maxDistance
     * @return bool
     */
    public static function isWithinDistance(string $str1, string $str2, int $maxDistance): bool
    {
        return abs(mb_strlen($str1) - mb_strlen($str2)) <= $maxDistance
            && self::distance($str1, $str2) <= $maxDistance;
    }
}
