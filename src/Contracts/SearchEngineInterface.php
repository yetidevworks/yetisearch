<?php

namespace YetiSearch\Contracts;

use YetiSearch\Models\SearchQuery;
use YetiSearch\Models\SearchResults;

interface SearchEngineInterface
{
    public function search(SearchQuery $query): SearchResults;
    
    public function suggest(string $term, array $options = []): array;
    
    public function count(SearchQuery $query): int;
    
    public function getStats(): array;
}