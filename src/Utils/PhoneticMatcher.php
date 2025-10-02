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
     * Generate Metaphone key for a word
     */
    public static function metaphone(string $word): string
    {
        // Use PHP's built-in metaphone function
        return metaphone($word);
    }

    /**
     * Generate Double Metaphone key for more accurate matching
     */
    public static function doubleMetaphone(string $word): array
    {
        // For now, use basic metaphone twice
        // In a full implementation, you'd use a proper Double Metaphone algorithm
        $primary = metaphone($word);
        return [$primary, $primary]; // Simplified - would implement full algorithm
    }

    /**
     * Calculate phonetic similarity between two words
     */
    public static function phoneticSimilarity(string $word1, string $word2): float
    {
        $meta1 = self::metaphone($word1);
        $meta2 = self::metaphone($word2);

        if ($meta1 === $meta2) {
            return 1.0;
        }

        // Check Double Metaphone alternatives
        $double1 = self::doubleMetaphone($word1);
        $double2 = self::doubleMetaphone($word2);

        foreach ($double1 as $alt1) {
            foreach ($double2 as $alt2) {
                if ($alt1 === $alt2) {
                    return 0.9; // High similarity for phonetic match
                }
            }
        }

        // Partial phonetic match
        $similarity = 0.0;
        $len1 = strlen($meta1);
        $len2 = strlen($meta2);
        $maxLen = max($len1, $len2);

        if ($maxLen > 0) {
            $common = similar_text($meta1, $meta2, $percent);
            $similarity = $percent / 100.0;
        }

        return $similarity;
    }

    /**
     * Find phonetically similar words from a dictionary
     */
    public static function findPhoneticMatches(string $term, array $candidates, float $threshold = 0.7): array
    {
        $matches = [];
        $termPhonetic = self::metaphone($term);

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
