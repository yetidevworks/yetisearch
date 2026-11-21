<?php

namespace YetiSearch\Contracts;

/**
 * A storage that can read some fields of every document a query matches, without reading the
 * documents whole or a page at a time. Aggregations use it, so they count every match;
 * SearchEngine checks for it, so a storage without it computes them from the results of a
 * search of up to max_results documents.
 */
interface ProvidesFieldValues
{
    /**
     * The values of $fields in each document the query (its text, filters, language, location
     * and field restriction) matches, one array per document keyed by field. A field is a
     * content field, else the metadata field of that name; `content.x` and `metadata.x` name
     * one of them, and id, language, type and timestamp the document's own. A field a document
     * lacks is null.
     *
     * @param string[] $fields
     * @return iterable<array<string, mixed>>
     */
    public function fieldValues(string $index, array $query, array $fields): iterable;
}
