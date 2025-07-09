<?php

namespace YetiSearch\Models;

class SearchQuery
{
    private string $query;
    private array $filters = [];
    private array $fields = [];
    private int $limit = 20;
    private int $offset = 0;
    private array $sort = [];
    private ?string $language = null;
    private array $boost = [];
    private bool $fuzzy = false;
    private float $fuzziness = 0.8;
    private bool $highlight = true;
    private int $highlightLength = 150;
    private array $facets = [];
    private array $aggregations = [];
    
    public function __construct(string $query)
    {
        $this->query = $query;
    }
    
    public function getQuery(): string
    {
        return $this->query;
    }
    
    public function setQuery(string $query): self
    {
        $this->query = $query;
        return $this;
    }
    
    public function filter(string $field, $value, string $operator = '='): self
    {
        $this->filters[] = [
            'field' => $field,
            'value' => $value,
            'operator' => $operator
        ];
        return $this;
    }
    
    public function getFilters(): array
    {
        return $this->filters;
    }
    
    public function inFields(array $fields): self
    {
        $this->fields = $fields;
        return $this;
    }
    
    public function getFields(): array
    {
        return $this->fields;
    }
    
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }
    
    public function getLimit(): int
    {
        return $this->limit;
    }
    
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }
    
    public function getOffset(): int
    {
        return $this->offset;
    }
    
    public function sortBy(string $field, string $direction = 'asc'): self
    {
        $this->sort[$field] = strtolower($direction);
        return $this;
    }
    
    public function getSort(): array
    {
        return $this->sort;
    }
    
    public function language(string $language): self
    {
        $this->language = $language;
        return $this;
    }
    
    public function getLanguage(): ?string
    {
        return $this->language;
    }
    
    public function boost(string $field, float $weight): self
    {
        $this->boost[$field] = $weight;
        return $this;
    }
    
    public function getBoost(): array
    {
        return $this->boost;
    }
    
    public function fuzzy(bool $fuzzy = true, float $fuzziness = 0.8): self
    {
        $this->fuzzy = $fuzzy;
        $this->fuzziness = max(0, min(1, $fuzziness));
        return $this;
    }
    
    public function isFuzzy(): bool
    {
        return $this->fuzzy;
    }
    
    public function getFuzziness(): float
    {
        return $this->fuzziness;
    }
    
    public function highlight(bool $highlight = true, int $length = 150): self
    {
        $this->highlight = $highlight;
        $this->highlightLength = $length;
        return $this;
    }
    
    public function shouldHighlight(): bool
    {
        return $this->highlight;
    }
    
    public function getHighlightLength(): int
    {
        return $this->highlightLength;
    }
    
    public function facet(string $field, array $options = []): self
    {
        $this->facets[$field] = $options;
        return $this;
    }
    
    public function getFacets(): array
    {
        return $this->facets;
    }
    
    public function aggregate(string $name, string $type, array $options): self
    {
        $this->aggregations[$name] = [
            'type' => $type,
            'options' => $options
        ];
        return $this;
    }
    
    public function getAggregations(): array
    {
        return $this->aggregations;
    }
    
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'filters' => $this->filters,
            'fields' => $this->fields,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'sort' => $this->sort,
            'language' => $this->language,
            'boost' => $this->boost,
            'fuzzy' => $this->fuzzy,
            'fuzziness' => $this->fuzziness,
            'highlight' => $this->highlight,
            'highlightLength' => $this->highlightLength,
            'facets' => $this->facets,
            'aggregations' => $this->aggregations
        ];
    }
}