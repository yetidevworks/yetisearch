<?php
namespace YetiSearch\Utils;

/**
 * Trigram (n-gram) similarity calculator for fuzzy string matching
 * 
 * Trigram matching is effective for:
 * - Fast similarity calculations with proper indexing
 * - Partial string matching
 * - Language-agnostic fuzzy matching
 * - Integration with search systems like SQLite FTS
 */
class Trigram
{
    /**
     * Default n-gram size (3 for trigrams)
     */
    private const DEFAULT_N = 3;
    
    /**
     * Padding character for boundary n-grams
     */
    private const PADDING_CHAR = ' ';
    
    /**
     * Generate n-grams from a string
     * 
     * @param string $str Input string
     * @param int $n N-gram size (default 3 for trigrams)
     * @param bool $padding Add padding for boundary n-grams
     * @return array Array of n-grams
     */
    public static function generateNgrams(string $str, int $n = self::DEFAULT_N, bool $padding = true): array
    {
        if (mb_strlen($str) === 0) {
            return [];
        }
        
        // Convert to lowercase for consistency
        $str = mb_strtolower($str);
        
        // Add padding if requested
        if ($padding) {
            $pad = str_repeat(self::PADDING_CHAR, $n - 1);
            $str = $pad . $str . $pad;
        }
        
        $ngrams = [];
        $length = mb_strlen($str);
        
        // Generate n-grams
        for ($i = 0; $i <= $length - $n; $i++) {
            $ngram = mb_substr($str, $i, $n);
            $ngrams[] = $ngram;
        }
        
        return $ngrams;
    }
    
    /**
     * Calculate Jaccard similarity between two strings using n-grams
     * 
     * @param string $str1
     * @param string $str2
     * @param int $n N-gram size
     * @return float Similarity score from 0.0 to 1.0
     */
    public static function similarity(string $str1, string $str2, int $n = self::DEFAULT_N): float
    {
        if ($str1 === $str2) {
            return 1.0;
        }
        
        $ngrams1 = self::generateNgrams($str1, $n);
        $ngrams2 = self::generateNgrams($str2, $n);
        
        if (empty($ngrams1) || empty($ngrams2)) {
            return 0.0;
        }
        
        // Calculate intersection and union
        $freq1 = array_count_values($ngrams1);
        $freq2 = array_count_values($ngrams2);
        
        $intersection = 0;
        foreach ($freq1 as $ngram => $count1) {
            if (isset($freq2[$ngram])) {
                $intersection += min($count1, $freq2[$ngram]);
            }
        }
        
        $union = count($ngrams1) + count($ngrams2) - $intersection;
        
        return $union > 0 ? $intersection / $union : 0.0;
    }
    
    /**
     * Calculate Dice coefficient (alternative similarity metric)
     * 
     * @param string $str1
     * @param string $str2
     * @param int $n N-gram size
     * @return float Similarity score from 0.0 to 1.0
     */
    public static function dice(string $str1, string $str2, int $n = self::DEFAULT_N): float
    {
        if ($str1 === $str2) {
            return 1.0;
        }
        
        $ngrams1 = self::generateNgrams($str1, $n);
        $ngrams2 = self::generateNgrams($str2, $n);
        
        if (empty($ngrams1) && empty($ngrams2)) {
            return 1.0;
        }
        
        if (empty($ngrams1) || empty($ngrams2)) {
            return 0.0;
        }
        
        // Calculate intersection
        $set1 = array_flip($ngrams1);
        $set2 = array_flip($ngrams2);
        $intersection = count(array_intersect_key($set1, $set2));
        
        // Dice coefficient: 2 * |intersection| / (|set1| + |set2|)
        return (2.0 * $intersection) / (count($set1) + count($set2));
    }
    
    /**
     * Find best matches from a list of candidates using trigram similarity
     * 
     * @param string $search Search term
     * @param array $candidates Array of candidate strings
     * @param float $threshold Minimum similarity threshold
     * @param int $maxResults Maximum number of results to return
     * @param int $n N-gram size
     * @return array Array of [string, similarity] sorted by similarity
     */
    public static function findBestMatches(string $search, array $candidates, float $threshold = 0.3, int $maxResults = 10, int $n = self::DEFAULT_N): array
    {
        $matches = [];
        
        // Pre-generate search n-grams for efficiency
        $searchNgrams = self::generateNgrams($search, $n);
        if (empty($searchNgrams)) {
            return [];
        }
        
        foreach ($candidates as $candidate) {
            $similarity = self::similarity($search, $candidate, $n);
            if ($similarity >= $threshold) {
                $matches[] = [$candidate, $similarity];
            }
        }
        
        // Sort by similarity (descending)
        usort($matches, function($a, $b) {
            return $b[1] <=> $a[1];
        });
        
        // Limit results
        return array_slice($matches, 0, $maxResults);
    }
    
    /**
     * Generate a trigram index key for SQLite FTS or similar systems
     * 
     * @param string $str Input string
     * @param int $n N-gram size
     * @return string Space-separated n-grams suitable for indexing
     */
    public static function generateIndexKey(string $str, int $n = self::DEFAULT_N): string
    {
        $ngrams = self::generateNgrams($str, $n, false); // No padding for index
        return implode(' ', array_unique($ngrams));
    }
    
    /**
     * Check if two strings meet a similarity threshold
     * 
     * @param string $str1
     * @param string $str2
     * @param float $threshold Minimum similarity (0.0 to 1.0)
     * @param int $n N-gram size
     * @return bool
     */
    public static function meetsThreshold(string $str1, string $str2, float $threshold, int $n = self::DEFAULT_N): bool
    {
        // Early termination for very different lengths
        $len1 = mb_strlen($str1);
        $len2 = mb_strlen($str2);
        
        // If strings differ too much in length, they can't be similar enough
        if ($len1 > 0 && $len2 > 0) {
            $lengthRatio = min($len1, $len2) / max($len1, $len2);
            if ($lengthRatio < $threshold * 0.5) {
                return false;
            }
        }
        
        return self::similarity($str1, $str2, $n) >= $threshold;
    }
}