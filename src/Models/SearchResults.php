<?php

namespace YetiSearch\Models;

class SearchResults implements \Countable, \IteratorAggregate
{
    private array $results = [];
    private int $totalCount = 0;
    private float $searchTime = 0;
    private array $facets = [];
    private array $aggregations = [];
    private ?string $suggestion = null;
    private array $metadata = [];
    
    public function __construct(
        array $results,
        int $totalCount,
        float $searchTime = 0,
        array $facets = [],
        array $aggregations = []
    ) {
        $this->results = array_map(function ($result) {
            return $result instanceof SearchResult ? $result : new SearchResult($result);
        }, $results);
        $this->totalCount = $totalCount;
        $this->searchTime = $searchTime;
        $this->facets = $facets;
        $this->aggregations = $aggregations;
    }
    
    public function getResults(): array
    {
        return $this->results;
    }
    
    public function getTotalCount(): int
    {
        return $this->totalCount;
    }
    
    public function getSearchTime(): float
    {
        return $this->searchTime;
    }
    
    public function getFacets(): array
    {
        return $this->facets;
    }
    
    public function getFacet(string $name): ?array
    {
        return $this->facets[$name] ?? null;
    }
    
    public function getAggregations(): array
    {
        return $this->aggregations;
    }
    
    public function getAggregation(string $name): ?array
    {
        return $this->aggregations[$name] ?? null;
    }
    
    public function setSuggestion(?string $suggestion): void
    {
        $this->suggestion = $suggestion;
    }
    
    public function getSuggestion(): ?string
    {
        return $this->suggestion;
    }
    
    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }
    
    public function getMetadata(): array
    {
        return $this->metadata;
    }
    
    public function count(): int
    {
        return count($this->results);
    }
    
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->results);
    }
    
    public function isEmpty(): bool
    {
        return empty($this->results);
    }
    
    public function first(): ?SearchResult
    {
        return $this->results[0] ?? null;
    }
    
    public function toArray(): array
    {
        return [
            'results' => array_map(function (SearchResult $result) {
                return $result->toArray();
            }, $this->results),
            'total' => $this->totalCount,
            'count' => count($this->results),
            'search_time' => $this->searchTime,
            'facets' => $this->facets,
            'aggregations' => $this->aggregations,
            'suggestion' => $this->suggestion,
            'metadata' => $this->metadata
        ];
    }
    
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }
}