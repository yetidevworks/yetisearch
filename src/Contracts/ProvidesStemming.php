<?php

namespace YetiSearch\Contracts;

/**
 * A storage that can keep the stems of an index's text next to the text
 * itself. SearchEngine checks it before building a stem query, so a storage
 * without it keeps working and searches the text as typed.
 */
interface ProvidesStemming
{
    /**
     * The language an index stems in: the canonical name of the language it
     * was created with ('english' when it was given none), or null when the
     * index does not stem.
     */
    public function stemmingFor(string $index): ?string;
}
