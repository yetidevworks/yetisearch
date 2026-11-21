<?php

namespace YetiSearch\DSL;

use YetiSearch\Models\SearchQuery;
use YetiSearch\YetiSearch;
use YetiSearch\Exceptions\InvalidArgumentException;

/**
 * QueryBuilder provides multiple query interfaces for YetiSearch
 *
 * Supports three query styles:
 * - Natural language DSL: 'author = "John" AND status = "published" SORT -created_at'
 * - URL parameters: 'filter[author][eq]=John&sort=-created_at'
 * - Fluent PHP interface: ->where('author', 'John')->orderBy('created_at', 'desc')
 */
class QueryBuilder
{
    /** The parameters URLQueryParser reads, as a regex alternation */
    private const URL_PARAMETERS =
        '(?:q|query|filter|sort|fields|page|limit|offset|fuzzy|highlight|facets|geo|language|boost)';

    private YetiSearch $yetiSearch;
    private QueryParser $queryParser;
    private URLQueryParser $urlParser;
    private array $config;

    /**
     * Constructor
     *
     * @param YetiSearch $yetiSearch YetiSearch instance
     * @param array $config Configuration options:
     *   - field_aliases: Map of field aliases (e.g., ['writer' => 'author'])
     *   - default_limit: Default result limit (default: 20)
     *   - max_limit: Maximum allowed limit (default: 1000)
     *   - default_fuzzy: Enable fuzzy search by default (default: false)
     *   - default_highlight: Enable highlighting by default (default: true)
     *   - metadata_fields: Fields to treat as metadata (auto-prefixed with 'metadata.')
     *     Common fields like 'author', 'status', 'price' are included by default.
     *     These fields are stored in the metadata array and used for filtering/sorting.
     */
    public function __construct(YetiSearch $yetiSearch, array $config = [])
    {
        $this->yetiSearch = $yetiSearch;
        $this->config = array_merge([
            'field_aliases' => [],
            'default_limit' => 20,
            'max_limit' => 1000,
            'default_fuzzy' => false,
            'default_highlight' => true,
            // Fields that should be treated as metadata (not content)
            // These are automatically prefixed with 'metadata.' in filters and sorts.
            // id, type, language and timestamp are columns of their own and are not here.
            'metadata_fields' => [
                'author', 'status', 'category', 'tags', 'date', 'published', 'draft',
                'created_at', 'updated_at', 'views', 'likes', 'rating', 'score', 'priority',
                'user', 'owner', 'assignee', 'reviewer', 'editor', 'price', 'cost', 'quantity',
                'stock', 'sku', 'uuid', 'slug', 'url', 'email', 'phone', 'address',
                'city', 'state', 'country', 'zip', 'lat', 'lng', 'latitude', 'longitude'
            ]
        ], $config);

        $this->createParsers();
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
     * Execute a SearchQuery object. Its fields (FIELDS, `fields=`, fields()) are the fields of the
     * documents it returns, as the DSL has them, and do not narrow the search.
     */
    public function executeSearch(string $index, SearchQuery $searchQuery, array $options = []): array
    {
        $results = $this->yetiSearch->search(
            $index,
            $searchQuery->getQuery(),
            array_merge($this->searchOptions($searchQuery), $options)
        );

        return $this->selectFields($results, $searchQuery->getFields());
    }

    /**
     * The results with only the selected fields in their documents, under the alias a field is
     * given one (`FIELDS title:headline`); every field when none is selected. A field is read
     * from the document, else from the metadata (`metadata.views` from the metadata only).
     *
     * @param array<int|string, string> $fields A list of fields, or a map of field => alias
     */
    private function selectFields(array $results, array $fields): array
    {
        if ($fields === []) {
            return $results;
        }
        $names = [];
        foreach ($fields as $key => $value) {
            $names[is_int($key) ? (string)$value : $key] = (string)$value;
        }
        foreach ($results['results'] ?? [] as $i => $result) {
            $content = is_array($result['document'] ?? null) ? $result['document'] : [];
            $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
            $document = [];
            foreach ($names as $field => $name) {
                if (strpos($field, 'metadata.') === 0) {
                    $from = [$metadata, substr($field, 9)];
                } else {
                    $from = array_key_exists($field, $content) ? [$content, $field] : [$metadata, $field];
                }
                if (array_key_exists($from[1], $from[0])) {
                    $document[$name] = $from[0][$from[1]];
                }
            }
            $results['results'][$i]['document'] = $document;
        }

        return $results;
    }

    /**
     * The YetiSearch::search() options for a query: its limit capped at max_limit,
     * and the metadata fields in its filters and sort given their 'metadata.' prefix.
     */
    private function searchOptions(SearchQuery $searchQuery): array
    {
        $filters = [];
        foreach ($searchQuery->getFilters() as $filter) {
            $filter['field'] = $this->metadataField($filter['field']);
            $filters[] = $filter;
        }

        $sort = [];
        foreach ($searchQuery->getSort() as $field => $direction) {
            $sort[$this->metadataField($field)] = $direction;
        }

        $options = [
            'limit' => min($searchQuery->getLimit(), $this->config['max_limit']),
            'offset' => $searchQuery->getOffset(),
            'fuzzy' => $searchQuery->isFuzzy(),
            'fuzziness' => $searchQuery->getFuzziness(),
            'highlight' => $searchQuery->shouldHighlight(),
            'highlight_length' => $searchQuery->getHighlightLength(),
            'language' => $searchQuery->getLanguage(),
            'boost' => $searchQuery->getBoost(),
            'facets' => $searchQuery->getFacets(),
            'sort' => $sort,
            'filters' => $filters
        ];

        if ($searchQuery->hasGeoFilters()) {
            $options['geoFilters'] = $searchQuery->getGeoFilters();
        }

        return $options;
    }

    private function metadataField(string $field): string
    {
        return in_array($field, $this->config['metadata_fields'], true) ? 'metadata.' . $field : $field;
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
            return $this->isQueryString($query)
                ? $this->urlParser->parseFromQueryString($query)
                : $this->queryParser->parse($query);
        }

        if (is_array($query)) {
            return $this->urlParser->parse($query);
        }

        throw new InvalidArgumentException('Invalid query format');
    }

    /**
     * Whether a string is URL parameters rather than DSL: key=value pairs joined
     * by '&', every key one of the parameters URLQueryParser reads.
     */
    private function isQueryString(string $query): bool
    {
        $pairs = array_filter(explode('&', $query), 'strlen');
        foreach ($pairs as $pair) {
            $key = urldecode((string)strstr($pair, '=', true));
            if (!preg_match('/^' . self::URL_PARAMETERS . '(?:\[.*\])?$/', $key)) {
                return false;
            }
        }

        return $pairs !== [];
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
        $this->createParsers();

        return $this;
    }

    /**
     * The SearchQuery options a new query starts from: default_limit,
     * default_fuzzy and default_highlight
     */
    public function getQueryDefaults(): array
    {
        return [
            'limit' => (int)$this->config['default_limit'],
            'fuzzy' => (bool)$this->config['default_fuzzy'],
            'highlight' => (bool)$this->config['default_highlight'],
        ];
    }

    private function createParsers(): void
    {
        $this->queryParser = new QueryParser($this->config['field_aliases'], $this->getQueryDefaults());
        $this->urlParser = new URLQueryParser($this->config['field_aliases'], $this->getQueryDefaults());
    }

    /**
     * Set which fields should be treated as metadata fields
     *
     * Metadata fields are automatically prefixed with 'metadata.' when used in
     * filters and sorts. This allows natural query syntax like 'price > 100'
     * instead of requiring 'metadata.price > 100'.
     *
     * @param array $fields List of field names to treat as metadata
     * @return self For method chaining
     *
     * @example
     * $builder->setMetadataFields(['company', 'department', 'employee_id']);
     */
    public function setMetadataFields(array $fields): self
    {
        $this->config['metadata_fields'] = $fields;
        return $this;
    }

    /**
     * Add a field to the metadata fields list
     *
     * Adds a single field to the existing metadata fields list without
     * replacing the entire list.
     *
     * @param string $field Field name to add to metadata fields
     * @return self For method chaining
     *
     * @example
     * $builder->addMetadataField('custom_score')
     *         ->addMetadataField('priority_level');
     */
    public function addMetadataField(string $field): self
    {
        if (!in_array($field, $this->config['metadata_fields'])) {
            $this->config['metadata_fields'][] = $field;
        }
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
        $this->searchQuery = new SearchQuery($query, $builder->getQueryDefaults());
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
        return $this->builder->executeSearch($this->index(), $this->searchQuery);
    }

    public function first(): ?array
    {
        $this->limit(1);
        $results = $this->get();

        return $results['results'][0] ?? null;
    }

    /**
     * The number of documents get() finds in all, with the same filters,
     * metadata fields and geo filters
     */
    public function count(): int
    {
        $results = $this->builder->executeSearch($this->index(), $this->searchQuery, [
            'limit' => 0,
            'offset' => 0,
            'highlight' => false,
            'facets' => [],
        ]);

        return (int)($results['total'] ?? 0);
    }

    private function index(): string
    {
        if ($this->index === '') {
            throw new InvalidArgumentException('Index not specified. Use in() method to set the index.');
        }

        return $this->index;
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
