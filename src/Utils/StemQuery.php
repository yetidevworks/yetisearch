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
     * The stem query for a raw query that is an implicit AND of terms: one group
     * per term, which matches the term as typed or by its stem, so a document
     * needs every word but may have it in either form. A term with no stem
     * stays as it is.
     *
     * The terms are those of the raw query, before escaping. Where no term has a
     * stem, there is no stem query: it would only repeat the raw one.
     *
     * @param string[] $terms
     * @return array{query: ?string, stems: string[]} The stem query, and the stems of the terms
     */
    public static function termGroups(AnalyzerInterface $analyzer, array $terms, string $language): array
    {
        $groups = [];
        $allStems = [];

        foreach ($terms as $term) {
            $escaped = Fts5Escaper::escapeToken($term);
            if ($escaped === '') {
                continue;
            }

            $alternatives = [$escaped];
            foreach (self::stemsOfTerm($analyzer, $term, $language) as $stem) {
                $escapedStem = Fts5Escaper::escapeToken($stem);
                if ($escapedStem !== '') {
                    $alternatives[] = '_stems : ' . $escapedStem;
                    $allStems[] = $stem;
                }
            }
            $groups[] = '(' . implode(' OR ', array_unique($alternatives)) . ')';
        }

        return [
            'query' => empty($allStems) ? null : implode(' AND ', $groups),
            'stems' => $allStems,
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
