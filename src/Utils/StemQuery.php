<?php

namespace YetiSearch\Utils;

use YetiSearch\Contracts\AnalyzerInterface;

/**
 * Builds the MATCH expression that finds words by their stems on an index
 * that keeps them in its `_stems` column.
 */
class StemQuery
{
    /**
     * The stem query for a raw query that is an OR of components (the phrase, the words near
     * each other, each word): the raw query as it is, or the stem of any of the words. Words
     * that are synonyms or fuzzy variants are not passed, so they match as typed. Where no word
     * has a stem there is no stem query: it would only repeat the raw one.
     *
     * @param string[] $words The words whose stems match
     * @param bool $prefixLast Whether the last word is a prefix, as in the raw query
     * @return array{query: ?string, stems: string[]} The stem query, and the stems of the words
     */
    public static function alternatives(
        AnalyzerInterface $analyzer,
        string $rawQuery,
        array $words,
        string $language,
        bool $prefixLast = false
    ): array {
        $alternatives = [];
        $stems = [];
        $last = count($words) - 1;

        foreach (array_values($words) as $i => $word) {
            foreach (self::stemsOfTerm($analyzer, $word, $language) as $stem) {
                $escapedStem = Fts5Escaper::escapeToken($stem . ($prefixLast && $i === $last ? '*' : ''));
                if ($escapedStem !== '') {
                    $alternatives['_stems : ' . $escapedStem] = true;
                    $stems[] = $stem;
                }
            }
        }

        return [
            'query' => empty($alternatives) ? null : '(' . $rawQuery . ') OR ' . implode(' OR ', array_keys($alternatives)),
            'stems' => $stems,
        ];
    }

    /**
     * The stems of one query term, in a language: what the analyzer makes of it,
     * which is one stem, or none when it drops the term (a stop word, too short).
     *
     * @return string[]
     */
    public static function stemsOfTerm(AnalyzerInterface $analyzer, string $term, string $language): array
    {
        $analyzed = $analyzer->analyze($term, $language);
        $stems = is_array($analyzed) && isset($analyzed['tokens']) ? $analyzed['tokens'] : [];

        return array_values(array_filter(array_map('strval', $stems), function ($stem) {
            return $stem !== '';
        }));
    }
}
