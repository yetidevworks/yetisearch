<?php
namespace YetiSearch\Utils;

/**
 * Levenshtein distance calculator for fuzzy string matching
 */
class Levenshtein
{
    /**
     * Calculate Levenshtein distance between two strings
     * 
     * @param string $str1
     * @param string $str2
     * @return int The edit distance
     */
    public static function distance(string $str1, string $str2): int
    {
        $len1 = mb_strlen($str1);
        $len2 = mb_strlen($str2);
        
        // Special cases
        if ($len1 === 0) return $len2;
        if ($len2 === 0) return $len1;
        if ($str1 === $str2) return 0;
        
        // Initialize matrix
        $matrix = [];
        for ($i = 0; $i <= $len1; $i++) {
            $matrix[$i][0] = $i;
        }
        for ($j = 0; $j <= $len2; $j++) {
            $matrix[0][$j] = $j;
        }
        
        // Calculate distances
        for ($i = 1; $i <= $len1; $i++) {
            for ($j = 1; $j <= $len2; $j++) {
                $cost = (mb_substr($str1, $i - 1, 1) === mb_substr($str2, $j - 1, 1)) ? 0 : 1;
                
                $matrix[$i][$j] = min(
                    $matrix[$i - 1][$j] + 1,     // deletion
                    $matrix[$i][$j - 1] + 1,     // insertion
                    $matrix[$i - 1][$j - 1] + $cost // substitution
                );
            }
        }
        
        return $matrix[$len1][$len2];
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
        if ($maxLen === 0) return 0.0;
        
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
        // Early termination optimization
        $lenDiff = abs(mb_strlen($str1) - mb_strlen($str2));
        if ($lenDiff > $maxDistance) {
            return false;
        }
        
        return self::distance($str1, $str2) <= $maxDistance;
    }
}