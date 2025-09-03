<?php

namespace YetiSearch\DSL;

use YetiSearch\Models\SearchQuery;
use YetiSearch\YetiSearch;
use YetiSearch\Exceptions\InvalidArgumentException;

class QueryBuilder
{
    private YetiSearch $yetiSearch;
    private QueryParser $queryParser;
    private URLQueryParser $urlParser;
    private array $config;
    
    public function __construct(YetiSearch $yetiSearch, array $config = [])
    {
        $this->yetiSearch = $yetiSearch;
        $this->config = array_merge([
            'field_aliases' => [],
            'default_limit' => 20,
            'max_limit' => 1000,
            'default_fuzzy' => false,
            'default_highlight' => true
        ], $config);
        
        $this->queryParser = new QueryParser($this->config['field_aliases']);
        $this->urlParser = new URLQueryParser($this->config['field_aliases']);
    }
    
    /**
     * Parse and execute a DSL query string
     * Example: author = "John" AND status in [published] SORT -created_at LIMIT 10
     */
    public function searchWithDSL(string $index, string $dslQuery, array $options = []): array
    {
        $searchQuery = $this->queryParser->parse($dslQuery);
        return $this->executeSearch($index, $searchQuery, $options);
    }
    
    /**
     * Parse and execute a URL query string or array
     * Example: filter[author][eq]=John&filter[status][in]=published&sort=-created_at&page[limit]=10
     */
    public function searchWithURL(string $index, $queryParams, array $options = []): array
    {
        if (is_string($queryParams)) {
            $searchQuery = $this->urlParser->parseFromQueryString($queryParams);
        } else {
            $searchQuery = $this->urlParser->parse($queryParams);
        }
        
        return $this->executeSearch($index, $searchQuery, $options);
    }
    
    /**
     * Build a query programmatically using a fluent interface
     */
    public function query(string $query = ''): FluentQuery
    {
        return new FluentQuery($this, $query);
    }
    
    /**
     * Execute a SearchQuery object
     */
    public function executeSearch(string $index, SearchQuery $searchQuery, array $options = []): array
    {
        // Apply limits
        $limit = min($searchQuery->getLimit(), $this->config['max_limit']);
        
        // Build options array for YetiSearch
        $searchOptions = array_merge([
            'limit' => $limit,
            'offset' => $searchQuery->getOffset(),
            'fuzzy' => $searchQuery->isFuzzy(),
            'fuzziness' => $searchQuery->getFuzziness(),
            'highlight' => $searchQuery->shouldHighlight(),
            'highlight_length' => $searchQuery->getHighlightLength(),
            'language' => $searchQuery->getLanguage(),
            'fields' => $searchQuery->getFields(),
            'boost' => $searchQuery->getBoost(),
            'facets' => $searchQuery->getFacets(),
            'sort' => $searchQuery->getSort(),
            'filters' => $searchQuery->getFilters()
        ], $options);
        
        // Add geo filters if present
        if ($searchQuery->hasGeoFilters()) {
            $searchOptions['geoFilters'] = $searchQuery->getGeoFilters();
        }
        
        // Execute search
        return $this->yetiSearch->search($index, $searchQuery->getQuery(), $searchOptions);
    }
    
    /**
     * Parse a mixed query (can be DSL string, URL params, or SearchQuery)
     */
    public function parse($query): SearchQuery
    {
        if ($query instanceof SearchQuery) {
            return $query;
        }
        
        if (is_string($query)) {
            // Try to detect if it's a URL query string or DSL
            if (strpos($query, '&') !== false || strpos($query, 'filter[') !== false) {
                return $this->urlParser->parseFromQueryString($query);
            }
            
            return $this->queryParser->parse($query);
        }
        
        if (is_array($query)) {
            return $this->urlParser->parse($query);
        }
        
        throw new InvalidArgumentException('Invalid query format');
    }
    
    /**
     * Get the YetiSearch instance
     */
    public function getYetiSearch(): YetiSearch
    {
        return $this->yetiSearch;
    }
    
    /**
     * Set field aliases for query parsing
     */
    public function setFieldAliases(array $aliases): self
    {
        $this->config['field_aliases'] = $aliases;
        $this->queryParser = new QueryParser($aliases);
        $this->urlParser = new URLQueryParser($aliases);
        
        return $this;
    }
}

/**
 * Fluent query builder for programmatic query construction
 */
class FluentQuery
{
    private QueryBuilder $builder;
    private SearchQuery $searchQuery;
    private string $index = '';
    
    public function __construct(QueryBuilder $builder, string $query = '')
    {
        $this->builder = $builder;
        $this->searchQuery = new SearchQuery($query);
    }
    
    public function in(string $index): self
    {
        $this->index = $index;
        return $this;
    }
    
    public function search(string $query): self
    {
        $this->searchQuery->setQuery($query);
        return $this;
    }
    
    public function where(string $field, $value, string $operator = '='): self
    {
        $this->searchQuery->filter($field, $value, $operator);
        return $this;
    }
    
    public function whereIn(string $field, array $values): self
    {
        $this->searchQuery->filter($field, $values, 'in');
        return $this;
    }
    
    public function whereNotIn(string $field, array $values): self
    {
        $this->searchQuery->filter($field, $values, 'not in');
        return $this;
    }
    
    public function whereLike(string $field, string $pattern): self
    {
        $this->searchQuery->filter($field, $pattern, 'like');
        return $this;
    }
    
    public function whereNull(string $field): self
    {
        $this->searchQuery->filter($field, null, 'is null');
        return $this;
    }
    
    public function whereNotNull(string $field): self
    {
        $this->searchQuery->filter($field, null, 'is not null');
        return $this;
    }
    
    public function whereBetween(string $field, $min, $max): self
    {
        $this->searchQuery->filter($field, [$min, $max], 'between');
        return $this;
    }
    
    public function fields(array $fields): self
    {
        $this->searchQuery->inFields($fields);
        return $this;
    }
    
    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $this->searchQuery->sortBy($field, $direction);
        return $this;
    }
    
    public function limit(int $limit): self
    {
        $this->searchQuery->limit($limit);
        return $this;
    }
    
    public function offset(int $offset): self
    {
        $this->searchQuery->offset($offset);
        return $this;
    }
    
    public function page(int $page, int $perPage = 20): self
    {
        $this->searchQuery->limit($perPage);
        $this->searchQuery->offset(($page - 1) * $perPage);
        return $this;
    }
    
    public function fuzzy(bool $enabled = true, float $fuzziness = 0.8): self
    {
        $this->searchQuery->fuzzy($enabled, $fuzziness);
        return $this;
    }
    
    public function highlight(bool $enabled = true, int $length = 150): self
    {
        $this->searchQuery->highlight($enabled, $length);
        return $this;
    }
    
    public function boost(string $field, float $weight): self
    {
        $this->searchQuery->boost($field, $weight);
        return $this;
    }
    
    public function language(string $language): self
    {
        $this->searchQuery->language($language);
        return $this;
    }
    
    public function facet(string $field, array $options = []): self
    {
        $this->searchQuery->facet($field, $options);
        return $this;
    }
    
    public function nearPoint(float $lat, float $lng, float $radius, string $units = 'm'): self
    {
        $point = new \YetiSearch\Geo\GeoPoint($lat, $lng);
        $this->searchQuery->near($point, $radius, $units);
        return $this;
    }
    
    public function withinBounds(float $north, float $south, float $east, float $west): self
    {
        $this->searchQuery->withinBounds($north, $south, $east, $west);
        return $this;
    }
    
    public function sortByDistance(float $lat, float $lng, string $direction = 'asc'): self
    {
        $point = new \YetiSearch\Geo\GeoPoint($lat, $lng);
        $this->searchQuery->sortByDistance($point, $direction);
        return $this;
    }
    
    public function get(): array
    {
        if (empty($this->index)) {
            throw new InvalidArgumentException('Index not specified. Use in() method to set the index.');
        }
        
        return $this->builder->executeSearch($this->index, $this->searchQuery);
    }
    
    public function first(): ?array
    {
        $this->limit(1);
        $results = $this->get();
        
        return $results['results'][0] ?? null;
    }
    
    public function count(): int
    {
        if (empty($this->index)) {
            throw new InvalidArgumentException('Index not specified. Use in() method to set the index.');
        }
        
        $yetiSearch = $this->builder->getYetiSearch();
        return $yetiSearch->count($this->index, $this->searchQuery->getQuery(), [
            'filters' => $this->searchQuery->getFilters(),
            'fields' => $this->searchQuery->getFields(),
            'language' => $this->searchQuery->getLanguage()
        ]);
    }
    
    public function toSearchQuery(): SearchQuery
    {
        return $this->searchQuery;
    }
    
    public function toArray(): array
    {
        return $this->searchQuery->toArray();
    }
}