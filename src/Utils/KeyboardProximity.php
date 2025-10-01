<?php
namespace YetiSearch\Utils;

/**
 * Keyboard proximity analysis for typo detection
 * 
 * Analyzes how likely a typo is based on keyboard layout.
 * Common fat-finger errors happen between adjacent keys.
 */
class KeyboardProximity
{
    /**
     * QWERTY keyboard layout (row, column) positions
     */
    private const QWERTY_LAYOUT = [
        '`' => [0, 0], '1' => [0, 1], '2' => [0, 2], '3' => [0, 3], '4' => [0, 4], 
        '5' => [0, 5], '6' => [0, 6], '7' => [0, 7], '8' => [0, 8], '9' => [0, 9], 
        '0' => [0, 10], '-' => [0, 11], '=' => [0, 12],
        
        'q' => [1, 0], 'w' => [1, 1], 'e' => [1, 2], 'r' => [1, 3], 't' => [1, 4], 
        'y' => [1, 5], 'u' => [1, 6], 'i' => [1, 7], 'o' => [1, 8], 'p' => [1, 9], 
        '[' => [1, 10], ']' => [1, 11], '\\' => [1, 12],
        
        'a' => [2, 0], 's' => [2, 1], 'd' => [2, 2], 'f' => [2, 3], 'g' => [2, 4], 
        'h' => [2, 5], 'j' => [2, 6], 'k' => [2, 7], 'l' => [2, 8], ';' => [2, 9], 
        '\'' => [2, 10],
        
        'z' => [3, 0], 'x' => [3, 1], 'c' => [3, 2], 'v' => [3, 3], 'b' => [3, 4], 
        'n' => [3, 5], 'm' => [3, 6], ',' => [3, 7], '.' => [3, 8], '/' => [3, 9],
    ];
    
    /**
     * Calculate Euclidean distance between two keys on keyboard
     */
    public static function keyDistance(string $key1, string $key2): float
    {
        $key1 = strtolower($key1);
        $key2 = strtolower($key2);
        
        if (!isset(self::QWERTY_LAYOUT[$key1]) || !isset(self::QWERTY_LAYOUT[$key2])) {
            return 10.0; // Large distance for unknown keys
        }
        
        $pos1 = self::QWERTY_LAYOUT[$key1];
        $pos2 = self::QWERTY_LAYOUT[$key2];
        
        $dx = $pos1[1] - $pos2[1];
        $dy = $pos1[0] - $pos2[0];
        
        return sqrt($dx * $dx + $dy * $dy);
    }
    
    /**
     * Calculate average keyboard distance between two strings
     */
    public static function stringDistance(string $str1, string $str2): float
    {
        $len1 = strlen($str1);
        $len2 = strlen($str2);
        
        if ($len1 !== $len2) {
            return 10.0; // Different lengths can't be simple keyboard typos
        }
        
        $totalDistance = 0.0;
        for ($i = 0; $i < $len1; $i++) {
            $totalDistance += self::keyDistance($str1[$i], $str2[$i]);
        }
        
        return $totalDistance / $len1;
    }
    
    /**
     * Check if a typo is likely due to keyboard proximity
     */
    public static function isLikelyKeyboardTypo(string $original, string $correction): bool
    {
        // Only check for single-character differences or similar length strings
        $lenDiff = abs(strlen($original) - strlen($correction));
        if ($lenDiff > 1) {
            return false;
        }
        
        // If same length, check average key distance
        if (strlen($original) === strlen($correction)) {
            $avgDistance = self::stringDistance($original, $correction);
            return $avgDistance <= 1.5; // Average distance <= 1.5 keys
        }
        
        // If length differs by 1, check for missing/extra character
        if (strlen($original) < strlen($correction)) {
            return self::isMissingCharTypo($original, $correction);
        } else {
            return self::isExtraCharTypo($original, $correction);
        }
    }
    
    /**
     * Check if shorter string is missing a character from longer string
     */
    private static function isMissingCharTypo(string $shorter, string $longer): bool
    {
        $i = $j = 0;
        $differences = 0;
        
        while ($i < strlen($shorter) && $j < strlen($longer)) {
            if ($shorter[$i] === $longer[$j]) {
                $i++;
                $j++;
            } else {
                $differences++;
                if ($differences > 1) {
                    return false;
                }
                $j++; // Skip character in longer string
            }
        }
        
        return $differences <= 1;
    }
    
    /**
     * Check if longer string has an extra character compared to shorter string
     */
    private static function isExtraCharTypo(string $longer, string $shorter): bool
    {
        return self::isMissingCharTypo($shorter, $longer);
    }
    
    /**
     * Get keyboard proximity score (0-1, higher is better)
     */
    public static function proximityScore(string $original, string $correction): float
    {
        if ($original === $correction) {
            return 1.0;
        }
        
        $lenDiff = abs(strlen($original) - strlen($correction));
        if ($lenDiff > 1) {
            return 0.0;
        }
        
        if (strlen($original) === strlen($correction)) {
            $avgDistance = self::stringDistance($original, $correction);
            // Convert distance to score (closer = higher score)
            return max(0.0, 1.0 - ($avgDistance / 3.0));
        }
        
        // For length differences, check if it's a simple insertion/deletion
        if (self::isLikelyKeyboardTypo($original, $correction)) {
            return 0.8; // Good score for likely insertion/deletion
        }
        
        return 0.0;
    }
    
    /**
     * Common keyboard adjacency typos for quick lookup
     */
    private static array $adjacencyTypos = [
        'q' => ['w', 'a', 's'],
        'w' => ['q', 'e', 'a', 's', 'd'],
        'e' => ['w', 'r', 's', 'd', 'f'],
        'r' => ['e', 't', 'd', 'f', 'g'],
        't' => ['r', 'y', 'f', 'g', 'h'],
        'y' => ['t', 'u', 'g', 'h', 'j'],
        'u' => ['y', 'i', 'h', 'j', 'k'],
        'i' => ['u', 'o', 'j', 'k', 'l'],
        'o' => ['i', 'p', 'k', 'l'],
        'p' => ['o', 'l'],
        
        'a' => ['q', 'w', 's', 'z', 'x'],
        's' => ['q', 'w', 'e', 'a', 'd', 'z', 'x', 'c'],
        'd' => ['w', 'e', 'r', 's', 'f', 'x', 'c', 'v'],
        'f' => ['e', 'r', 't', 'd', 'g', 'c', 'v', 'b'],
        'g' => ['r', 't', 'y', 'f', 'h', 'v', 'b', 'n'],
        'h' => ['t', 'y', 'u', 'g', 'j', 'b', 'n', 'm'],
        'j' => ['y', 'u', 'i', 'h', 'k', 'n', 'm'],
        'k' => ['u', 'i', 'o', 'j', 'l', 'm'],
        'l' => ['i', 'o', 'p', 'k'],
        
        'z' => ['a', 's', 'x'],
        'x' => ['a', 's', 'd', 'z', 'c'],
        'c' => ['s', 'd', 'f', 'x', 'v'],
        'v' => ['d', 'f', 'g', 'c', 'b'],
        'b' => ['f', 'g', 'h', 'v', 'n'],
        'n' => ['g', 'h', 'j', 'b', 'm'],
        'm' => ['h', 'j', 'k', 'n'],
    ];
    
    /**
     * Quick check if character substitution is likely due to keyboard adjacency
     */
    public static function isAdjacentTypo(string $char1, string $char2): bool
    {
        $char1 = strtolower($char1);
        $char2 = strtolower($char2);
        
        return isset(self::$adjacencyTypos[$char1]) && 
               in_array($char2, self::$adjacencyTypos[$char1]);
    }
}