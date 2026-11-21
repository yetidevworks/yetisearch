<?php

namespace YetiSearch\Contracts;

/**
 * A storage that can look up the terms of an index by their beginning. Typo correction uses it
 * to know that a word it does not hold among the most frequent terms is in the index all the
 * same, and to correct to such a rare word (a name, a title). SearchEngine checks for it, so a
 * storage without it keeps working with the most frequent terms alone.
 */
interface ProvidesTermLookup
{
    /**
     * The indexed terms that start with $prefix, at most $limit of them, with the number of
     * documents each is in
     *
     * @return array<string, int>
     */
    public function termsStartingWith(string $index, string $prefix, int $limit = 2000): array;
}
