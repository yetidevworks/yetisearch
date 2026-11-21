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
        $key1 = mb_strtolower($key1);
        $key2 = mb_strtolower($key2);

        if ($key1 === $key2) {
            return 0.0;
        }
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
     * Calculate average keyboard distance between two strings, character by character
     */
    public static function stringDistance(string $str1, string $str2): float
    {
        return self::averageKeyDistance(self::chars($str1), self::chars($str2));
    }

    /**
     * Check if a typo is likely due to keyboard proximity
     */
    public static function isLikelyKeyboardTypo(string $original, string $correction): bool
    {
        return self::likelyTypo(self::chars($original), self::chars($correction));
    }

    /**
     * Get keyboard proximity score (0-1, higher is better)
     */
    public static function proximityScore(string $original, string $correction): float
    {
        if ($original === $correction) {
            return 1.0;
        }

        $a = self::chars($original);
        $b = self::chars($correction);
        $lenDiff = abs(count($a) - count($b));

        if ($lenDiff === 0) {
            // Convert distance to score (closer = higher score)
            return max(0.0, 1.0 - (self::averageKeyDistance($a, $b) / 3.0));
        }

        // For a length difference of one, a simple insertion or deletion
        return $lenDiff === 1 && self::likelyTypo($a, $b) ? 0.8 : 0.0;
    }

    /**
     * @return string[]
     */
    private static function chars(string $str): array
    {
        return preg_match('/[\x80-\xFF]/', $str) ? mb_str_split($str) : str_split($str);
    }

    /**
     * @param string[] $a
     * @param string[] $b
     */
    private static function averageKeyDistance(array $a, array $b): float
    {
        if (count($a) !== count($b) || $a === []) {
            return 10.0; // Different lengths can't be simple keyboard typos
        }

        $totalDistance = 0.0;
        foreach ($a as $i => $char) {
            $totalDistance += self::keyDistance($char, $b[$i]);
        }

        return $totalDistance / count($a);
    }

    /**
     * Same length with keys close on average, or one character missing or extra
     *
     * @param string[] $a
     * @param string[] $b
     */
    private static function likelyTypo(array $a, array $b): bool
    {
        $lenDiff = count($a) - count($b);
        if ($lenDiff === 0) {
            return self::averageKeyDistance($a, $b) <= 1.5; // Average distance <= 1.5 keys
        }
        if (abs($lenDiff) > 1) {
            return false;
        }

        // The shorter one is the longer one with one character left out
        [$shorter, $longer] = $lenDiff < 0 ? [$a, $b] : [$b, $a];
        $skipped = 0;
        for ($i = 0, $j = 0; $i < count($shorter); $j++) {
            if ($shorter[$i] === $longer[$j]) {
                $i++;
            } elseif (++$skipped > 1) {
                return false;
            }
        }

        return true;
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
