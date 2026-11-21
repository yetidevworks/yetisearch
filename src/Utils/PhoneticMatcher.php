<?php

namespace YetiSearch\Utils;

/**
 * Phonetic matching for improved typo correction
 *
 * Handles common phonetic typos like:
 * - phone/fone
 * - there/their/they're
 * - through/threw
 * - etc.
 */
class PhoneticMatcher
{
    /**
     * What each letter is to Metaphone: 1 a vowel, 2 kept as it is, 4 changed by an H after it,
     * 8 makes C and G soft, 16 keeps GH from F
     */
    private const CODES = [
        'A' => 1, 'B' => 16, 'C' => 4, 'D' => 16, 'E' => 9, 'F' => 2, 'G' => 4, 'H' => 16, 'I' => 9, 'J' => 2,
        'K' => 0, 'L' => 2, 'M' => 2, 'N' => 2, 'O' => 1, 'P' => 4, 'Q' => 0, 'R' => 2, 'S' => 4, 'T' => 4,
        'U' => 1, 'V' => 0, 'W' => 0, 'X' => 0, 'Y' => 8, 'Z' => 0,
    ];

    /**
     * The Metaphone key of a word, as PHP's metaphone() gives it, which PHP 8.6 deprecates: a port
     * of its C code (ext/standard/metaphone.c). It gives the same key for every word of ASCII
     * letters; a byte above 0x7F is no letter, as in the C locale (macOS's metaphone() reads
     * some of them as letters).
     */
    public static function metaphone(string $word): string
    {
        // A NUL byte ends the word, as in C; letters are ASCII letters, whatever the locale
        $nul = strpos($word, "\0");
        if ($nul !== false) {
            $word = substr($word, 0, $nul);
        }
        $word = strtr($word, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
        $length = strlen($word);
        // The letter at $i, '' past either end (C's '\0')
        $at = static function (int $i) use ($word, $length): string {
            return $i >= 0 && $i < $length ? $word[$i] : '';
        };
        $code = static function (string $c): int {
            return self::CODES[$c] ?? 0;
        };
        $key = '';

        // The first letter
        $i = 0;
        while ($i < $length && !isset(self::CODES[$word[$i]])) {
            $i++;
        }
        if ($i === $length) {
            return '';
        }
        $current = $word[$i];
        $next = $at($i + 1);
        switch ($current) {
            case 'A':
                // AE becomes E; a vowel that begins the word is kept
                $key .= $next === 'E' ? 'E' : 'A';
                $i += $next === 'E' ? 2 : 1;
                break;
            case 'G':
            case 'K':
            case 'P':
                // GN, KN and PN become N
                if ($next === 'N') {
                    $key .= 'N';
                    $i += 2;
                }
                break;
            case 'W':
                // WR becomes R, WH and W before a vowel W
                if ($next === 'R') {
                    $key .= 'R';
                    $i += 2;
                } elseif ($next === 'H' || ($code($next) & 1)) {
                    $key .= 'W';
                    $i += 2;
                }
                break;
            case 'X':
                $key .= 'S';
                $i++;
                break;
            case 'E':
            case 'I':
            case 'O':
            case 'U':
                $key .= $current;
                $i++;
                break;
        }

        for (; $i < $length; $i++) {
            $current = $word[$i];
            if (!isset(self::CODES[$current])) {
                continue;
            }
            $previous = $at($i - 1);
            // Doubled letters count once, but for CC
            if ($current === $previous && $current !== 'C') {
                continue;
            }
            $next = $at($i + 1);
            $afterNext = $at($i + 2);
            $skip = 0;
            switch ($current) {
                case 'B':
                    // Silent in MB
                    if ($previous !== 'M') {
                        $key .= 'B';
                    }
                    break;
                case 'C':
                    if ($code($next) & 8) {
                        // CIA is X, SC[EIY] is silent, C[EIY] is S
                        if ($next === 'I' && $afterNext === 'A') {
                            $key .= 'X';
                        } elseif ($previous !== 'S') {
                            $key .= 'S';
                        }
                    } elseif ($next === 'H') {
                        $key .= 'X';
                        $skip++;
                    } else {
                        $key .= 'K';
                    }
                    break;
                case 'D':
                    // DG[EIY] is J
                    if ($next === 'G' && ($code($afterNext) & 8)) {
                        $key .= 'J';
                        $skip++;
                    } else {
                        $key .= 'T';
                    }
                    break;
                case 'G':
                    if ($next === 'H') {
                        // GH is F, but after B, D or H three letters back, or H four back
                        if (!(($code($at($i - 3)) & 16) || $at($i - 4) === 'H')) {
                            $key .= 'F';
                            $skip++;
                        }
                    } elseif ($next === 'N') {
                        // Silent in -GN and -GNED
                        if (!(!isset(self::CODES[$afterNext]) || ($afterNext === 'E' && $at($i + 3) === 'D'))) {
                            $key .= 'K';
                        }
                    } elseif (($code($next) & 8) && $previous !== 'G') {
                        $key .= 'J';
                    } else {
                        $key .= 'K';
                    }
                    break;
                case 'H':
                    // Before a vowel, and not after C, G, P, S or T
                    if (($code($next) & 1) && !($code($previous) & 4)) {
                        $key .= 'H';
                    }
                    break;
                case 'K':
                    if ($previous !== 'C') {
                        $key .= 'K';
                    }
                    break;
                case 'P':
                    $key .= $next === 'H' ? 'F' : 'P';
                    break;
                case 'Q':
                    $key .= 'K';
                    break;
                case 'S':
                    // SIO and SIA are X, SH is X
                    if ($next === 'I' && ($afterNext === 'O' || $afterNext === 'A')) {
                        $key .= 'X';
                    } elseif ($next === 'H') {
                        $key .= 'X';
                        $skip++;
                    } else {
                        $key .= 'S';
                    }
                    break;
                case 'T':
                    // TIO and TIA are X, TH is 0, and T is silent in TCH
                    if ($next === 'I' && ($afterNext === 'O' || $afterNext === 'A')) {
                        $key .= 'X';
                    } elseif ($next === 'H') {
                        $key .= '0';
                        $skip++;
                    } elseif (!($next === 'C' && $afterNext === 'H')) {
                        $key .= 'T';
                    }
                    break;
                case 'V':
                    $key .= 'F';
                    break;
                case 'W':
                    if ($code($next) & 1) {
                        $key .= 'W';
                    }
                    break;
                case 'X':
                    $key .= 'KS';
                    break;
                case 'Y':
                    if ($code($next) & 1) {
                        $key .= 'Y';
                    }
                    break;
                case 'Z':
                    $key .= 'S';
                    break;
                case 'F':
                case 'J':
                case 'L':
                case 'M':
                case 'N':
                case 'R':
                    $key .= $current;
                    break;
            }
            $i += $skip;
        }

        return $key;
    }

    /**
     * Generate Double Metaphone key for more accurate matching
     */
    public static function doubleMetaphone(string $word): array
    {
        // For now, use basic metaphone twice
        // In a full implementation, you'd use a proper Double Metaphone algorithm
        $primary = self::metaphone($word);
        return [$primary, $primary]; // Simplified - would implement full algorithm
    }

    /**
     * Calculate phonetic similarity between two words
     */
    public static function phoneticSimilarity(string $word1, string $word2): float
    {
        $meta1 = self::metaphone($word1);
        $meta2 = self::metaphone($word2);

        // A word with no Latin letters (Cyrillic, CJK, digits) has no phonetic key, and two
        // empty keys say nothing about how the words sound
        if ($meta1 === '' || $meta2 === '') {
            return 0.0;
        }
        if ($meta1 === $meta2) {
            return 1.0;
        }

        // Partial phonetic match
        similar_text($meta1, $meta2, $percent);

        return $percent / 100.0;
    }

    /**
     * Find phonetically similar words from a dictionary
     */
    public static function findPhoneticMatches(string $term, array $candidates, float $threshold = 0.7): array
    {
        $matches = [];
        foreach ($candidates as $candidate) {
            $similarity = self::phoneticSimilarity($term, $candidate);
            if ($similarity >= $threshold) {
                $matches[] = [$candidate, $similarity];
            }
        }

        // Sort by similarity (descending)
        usort($matches, function ($a, $b) {
            return $b[1] <=> $a[1];
        });

        return $matches;
    }

    /**
     * Check if two words are likely phonetic typos of each other
     */
    public static function isPhoneticTypo(string $original, string $correction): bool
    {
        // Quick length check - phonetic typos usually have similar length
        $lenDiff = abs(strlen($original) - strlen($correction));
        if ($lenDiff > 2) {
            return false;
        }

        $similarity = self::phoneticSimilarity($original, $correction);
        return $similarity >= 0.8;
    }

    /**
     * Common phonetic typo patterns for quick lookup
     */
    private static array $commonPatterns = [
        'fone' => 'phone',
        'thier' => 'their',
        'teh' => 'the',
        'adn' => 'and',
        'taht' => 'that',
        'whihc' => 'which',
        'waht' => 'what',
        'were' => 'where',
        'wher' => 'where',
        'becuase' => 'because',
        'becasue' => 'because',
        'beleive' => 'believe',
        'recieve' => 'receive',
        'seperate' => 'separate',
        'definately' => 'definitely',
        'neccessary' => 'necessary',
        'occured' => 'occurred',
        'untill' => 'until',
        'wich' => 'which',
        'thru' => 'through',
        'tho' => 'though',
        'alot' => 'a lot',
        'cant' => "can't",
        'wont' => "won't",
        'dont' => "don't",
    ];

    /**
     * Quick lookup for common phonetic typos
     */
    public static function quickPhoneticCorrection(string $term): ?string
    {
        $lowerTerm = strtolower($term);
        return self::$commonPatterns[$lowerTerm] ?? null;
    }
}
