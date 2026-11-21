<?php

namespace YetiSearch\Utils;

/**
 * Jaro-Winkler distance calculator for fuzzy string matching
 *
 * The Jaro-Winkler algorithm is particularly effective for:
 * - Short strings (names, titles)
 * - Matching strings with common prefixes
 * - Fast similarity calculations (O(n) complexity)
 */
class JaroWinkler
{
    /**
     * Default prefix scaling factor for Winkler modification
     */
    private const DEFAULT_PREFIX_SCALE = 0.1;

    /**
     * Maximum prefix length to consider for Winkler bonus
     */
    private const MAX_PREFIX_LENGTH = 4;

    /**
     * Calculate Jaro similarity between two strings
     *
     * @param string $str1
     * @param string $str2
     * @return float Similarity score from 0.0 to 1.0
     */
    public static function jaro(string $str1, string $str2): float
    {
        if ($str1 === $str2) {
            return 1.0;
        }
        if ($str1 === '' || $str2 === '') {
            return 0.0;
        }

        return self::jaroOfChars(self::chars($str1), self::chars($str2));
    }

    /**
     * Calculate Jaro-Winkler similarity between two strings
     *
     * @param string $str1
     * @param string $str2
     * @param float $prefixScale Scaling factor for prefix bonus (0.0 to 0.25)
     * @return float Similarity score from 0.0 to 1.0
     */
    public static function similarity(string $str1, string $str2, float $prefixScale = self::DEFAULT_PREFIX_SCALE): float
    {
        if ($str1 === $str2) {
            return 1.0;
        }
        if ($str1 === '' || $str2 === '') {
            return 0.0;
        }

        $a = self::chars($str1);
        $b = self::chars($str2);
        $jaro = self::jaroOfChars($a, $b);

        // Only apply Winkler bonus if Jaro similarity is above threshold
        if ($jaro < 0.7) {
            return $jaro;
        }

        // Calculate common prefix length (up to MAX_PREFIX_LENGTH)
        $prefixLen = 0;
        $maxPrefix = min(count($a), count($b), self::MAX_PREFIX_LENGTH);
        while ($prefixLen < $maxPrefix && $a[$prefixLen] === $b[$prefixLen]) {
            $prefixLen++;
        }

        // Ensure prefix scale is within valid range
        $prefixScale = min(0.25, max(0.0, $prefixScale));

        // Calculate Jaro-Winkler similarity
        return $jaro + ($prefixLen * $prefixScale * (1.0 - $jaro));
    }

    /**
     * The characters of a string: its bytes when it is ASCII
     *
     * @return string[]
     */
    private static function chars(string $str): array
    {
        return preg_match('/[\x80-\xFF]/', $str) ? mb_str_split($str) : str_split($str);
    }

    /**
     * Jaro similarity of two non-empty character lists
     *
     * @param string[] $a
     * @param string[] $b
     */
    private static function jaroOfChars(array $a, array $b): float
    {
        $len1 = count($a);
        $len2 = count($b);

        // Calculate the match window
        $matchWindow = max(1, (int) (max($len1, $len2) / 2) - 1);

        // Find matches: the positions of $b each character of $a matched, in order of $a
        $matched2 = [];
        $order1 = [];
        for ($i = 0; $i < $len1; $i++) {
            for ($j = max(0, $i - $matchWindow), $end = min($i + $matchWindow + 1, $len2); $j < $end; $j++) {
                if (!isset($matched2[$j]) && $a[$i] === $b[$j]) {
                    $matched2[$j] = true;
                    $order1[] = $a[$i];
                    break;
                }
            }
        }

        $matches = count($order1);
        if ($matches === 0) {
            return 0.0;
        }

        // Count transpositions: matched characters that are not in the same order in both
        ksort($matched2);
        $transpositions = 0;
        $k = 0;
        foreach ($matched2 as $j => $unused) {
            if ($order1[$k++] !== $b[$j]) {
                $transpositions++;
            }
        }

        return ($matches / $len1 + $matches / $len2 + ($matches - $transpositions / 2) / $matches) / 3.0;
    }

    /**
     * Calculate Jaro-Winkler distance (inverse of similarity)
     *
     * @param string $str1
     * @param string $str2
     * @param float $prefixScale Scaling factor for prefix bonus
     * @return float Distance from 0.0 to 1.0 where 0 = identical
     */
    public static function distance(string $str1, string $str2, float $prefixScale = self::DEFAULT_PREFIX_SCALE): float
    {
        return 1.0 - self::similarity($str1, $str2, $prefixScale);
    }

    /**
     * Check if two strings meet a similarity threshold
     *
     * @param string $str1
     * @param string $str2
     * @param float $threshold Minimum similarity (0.0 to 1.0)
     * @param float $prefixScale Scaling factor for prefix bonus
     * @return bool
     */
    public static function meetsThreshold(string $str1, string $str2, float $threshold, float $prefixScale = self::DEFAULT_PREFIX_SCALE): bool
    {
        // Early termination for very different lengths
        $len1 = mb_strlen($str1);
        $len2 = mb_strlen($str2);
        $lengthRatio = $len1 > 0 ? min($len1, $len2) / max($len1, $len2) : 0;

        // If length ratio is too low, strings can't be similar enough
        if ($lengthRatio < $threshold * 0.8) {
            return false;
        }

        return self::similarity($str1, $str2, $prefixScale) >= $threshold;
    }

    /**
     * Find best matches from a list of candidates
     *
     * @param string $search Search term
     * @param array $candidates Array of candidate strings
     * @param float $threshold Minimum similarity threshold
     * @param int $maxResults Maximum number of results to return
     * @param float $prefixScale Scaling factor for prefix bonus
     * @return array Array of [string, similarity] sorted by similarity
     */
    public static function findBestMatches(string $search, array $candidates, float $threshold = 0.7, int $maxResults = 10, float $prefixScale = self::DEFAULT_PREFIX_SCALE): array
    {
        $matches = [];

        foreach ($candidates as $candidate) {
            $similarity = self::similarity($search, $candidate, $prefixScale);
            if ($similarity >= $threshold) {
                $matches[] = [$candidate, $similarity];
            }
        }

        // Sort by similarity (descending)
        usort($matches, function ($a, $b) {
            return $b[1] <=> $a[1];
        });

        // Limit results
        return array_slice($matches, 0, $maxResults);
    }
}
