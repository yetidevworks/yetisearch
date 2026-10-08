<?php

namespace YetiSearch\Contracts;

/**
 * A storage that can tell when what a search of an index would return may
 * have changed. SearchEngine checks it before serving a result it holds in
 * memory, so a write is seen by the next search in the same process.
 */
interface TracksIndexChanges
{
    /**
     * A value that changes whenever the index's documents, vectors or
     * calibration may have changed: on every write through this storage, and
     * on every commit to the database from another connection. Equal tokens
     * mean nothing changed in between.
     */
    public function indexChangeToken(string $index): string;
}
