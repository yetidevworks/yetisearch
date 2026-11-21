<?php

namespace YetiSearch\DSL;

use YetiSearch\Models\SearchQuery;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Geo\GeoBounds;

class URLQueryParser
{
    /** Operators whose value is a comma-separated list */
    private const LIST_OPERATORS = ['in', 'not in', 'between'];

    private array $fieldAliases;
    private array $defaults;

    /**
     * @param array $fieldAliases Map of alias => field name
     * @param array $defaults SearchQuery options every parsed query starts from (limit, fuzzy, highlight)
     */
    public function __construct(array $fieldAliases = [], array $defaults = [])
    {
        $this->fieldAliases = $fieldAliases;
        $this->defaults = $defaults;
    }

    public function parse(array $params): SearchQuery
    {
        // Extract main query
        $queryString = $params['q'] ?? $params['query'] ?? '';
        $searchQuery = new SearchQuery(is_string($queryString) ? $queryString : '', $this->defaults);

        // Parse filters (JSON API compliant)
        if (isset($params['filter']) && is_array($params['filter'])) {
            $this->parseFilters($params['filter'], $searchQuery);
        }

        // Parse sorting
        if (isset($params['sort'])) {
            $this->parseSort($params['sort'], $searchQuery);
        }

        // Parse fields selection
        if (isset($params['fields'])) {
            $this->parseFields($params['fields'], $searchQuery);
        }

        $this->parsePagination($params, $searchQuery);

        // Parse fuzzy search options
        if (isset($params['fuzzy'])) {
            $fuzzyValue = $params['fuzzy'];
            if (is_string($fuzzyValue)) {
                $searchQuery->fuzzy($fuzzyValue === 'true' || $fuzzyValue === '1');
            } elseif (is_bool($fuzzyValue)) {
                $searchQuery->fuzzy($fuzzyValue);
            }
        }

        // Parse highlighting
        if (isset($params['highlight'])) {
            $highlightValue = $params['highlight'];
            $searchQuery->highlight(
                $highlightValue === 'true' || $highlightValue === '1' || $highlightValue === true
            );
        }

        // Parse facets: facets[]=brand, or facets[brand][limit]=10
        if (isset($params['facets']) && is_array($params['facets'])) {
            foreach ($params['facets'] as $field => $options) {
                if (is_int($field) && is_string($options)) {
                    $searchQuery->facet($options);
                } elseif (is_string($field)) {
                    $searchQuery->facet($field, is_array($options) ? $options : []);
                }
            }
        }

        // Parse geo filters
        if (isset($params['geo']) && is_array($params['geo'])) {
            $this->parseGeoFilters($params['geo'], $searchQuery);
        }

        // Parse language
        if (isset($params['language']) && is_string($params['language'])) {
            $searchQuery->language($params['language']);
        }

        // Parse field boosts
        if (isset($params['boost']) && is_array($params['boost'])) {
            foreach ($params['boost'] as $field => $weight) {
                $searchQuery->boost($field, (float)$weight);
            }
        }

        return $searchQuery;
    }

    public function parseFromQueryString(string $queryString): SearchQuery
    {
        parse_str($queryString, $params);
        return $this->parse($params);
    }

    private function parseFilters(array $filters, SearchQuery $searchQuery): void
    {
        foreach ($filters as $field => $conditions) {
            $fieldName = $this->resolveFieldAlias((string)$field);

            if (!is_array($conditions)) {
                // Simple equality filter
                $searchQuery->filter($fieldName, $this->parseValue($conditions, '='), '=');
            } elseif (!$this->isAssociativeArray($conditions)) {
                // filter[category][]=a&filter[category][]=b: any of the values
                $searchQuery->filter($fieldName, $this->parseValue($conditions, 'in'), 'in');
            } else {
                foreach ($conditions as $operator => $value) {
                    $op = $this->mapOperator((string)$operator);
                    $searchQuery->filter($fieldName, $this->parseValue($value, $op), $op);
                }
            }
        }
    }

    /**
     * sort=-created_at,title or sort=title:desc, also as sort[]=-created_at&sort[]=title
     * or sort[title]=desc
     */
    private function parseSort($sort, SearchQuery $searchQuery): void
    {
        $fields = [];
        if (is_array($sort)) {
            foreach ($sort as $key => $value) {
                if (is_string($value)) {
                    $fields[] = is_string($key) ? $key . ':' . $value : $value;
                }
            }
        } elseif (is_scalar($sort)) {
            $fields = explode(',', (string)$sort);
        }

        foreach ($fields as $field) {
            $field = trim($field);

            if ($field === '') {
                continue;
            }

            // Check for descending prefix
            if ($field[0] === '-') {
                $searchQuery->sortBy(substr($field, 1), 'desc');
            } elseif (strpos($field, ':') !== false) {
                // Check for explicit asc/desc suffix
                [$fieldName, $direction] = explode(':', $field, 2);
                $searchQuery->sortBy($fieldName, $direction);
            } else {
                $searchQuery->sortBy($field, 'asc');
            }
        }
    }

    private function parseFields($fields, SearchQuery $searchQuery): void
    {
        // Fields can be:
        // 1. String: "field1,field2,field3"
        // 2. Array: ["field1", "field2", "field3"]
        // 3. Object with aliases: {"field1": "alias1", "field2": "alias2"}

        if (is_string($fields)) {
            $fieldList = array_map('trim', explode(',', $fields));
            $searchQuery->inFields($fieldList);
        } elseif (is_array($fields)) {
            if ($this->isAssociativeArray($fields)) {
                // Field aliases mapping - store for result transformation
                $fieldList = array_keys($fields);
            } else {
                // Simple field list
                $fieldList = $fields;
            }
            $searchQuery->inFields($fieldList);
        }
    }

    /**
     * JSON API style pagination, page[limit]=10&page[offset]=20 or
     * page[number]=2&page[size]=10, and the plain page, limit and offset
     * parameters. The plain limit and offset win over the page[] ones, and a
     * page number counts in pages of whichever size is given, else of the
     * default size. A value that is not a whole number is ignored.
     */
    private function parsePagination(array $params, SearchQuery $searchQuery): void
    {
        $page = $params['page'] ?? [];
        if (!is_array($page)) {
            $page = ['number' => $page];
        }

        $limit = $this->count($params['limit'] ?? null)
            ?? $this->count($page['size'] ?? null)
            ?? $this->count($page['limit'] ?? null);
        if ($limit !== null) {
            $searchQuery->limit($limit);
        }

        $offset = $this->count($params['offset'] ?? null);
        if ($offset === null) {
            $number = $this->count($page['number'] ?? null);
            $offset = $number !== null
                ? (max(1, $number) - 1) * $searchQuery->getLimit()
                : $this->count($page['offset'] ?? null);
        }
        if ($offset !== null) {
            $searchQuery->offset($offset);
        }
    }

    /**
     * A whole number of zero or more, or null
     */
    private function count($value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }
        $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $count === false ? null : $count;
    }

    private function parseGeoFilters(array $geo, SearchQuery $searchQuery): void
    {
        // Near query: geo[near][lat]=10&geo[near][lng]=20&geo[near][radius]=100
        if (isset($geo['near'])) {
            $near = $geo['near'];
            if (isset($near['lat'], $near['lng'], $near['radius'])) {
                $point = new GeoPoint((float)$near['lat'], (float)$near['lng']);
                $searchQuery->near($point, (float)$near['radius'], $near['units'] ?? null);
            }
        }

        // Within bounds: geo[within][north]=10&geo[within][south]=5...
        if (isset($geo['within'])) {
            $within = $geo['within'];
            if (isset($within['north'], $within['south'], $within['east'], $within['west'])) {
                $bounds = new GeoBounds(
                    (float)$within['north'],
                    (float)$within['south'],
                    (float)$within['east'],
                    (float)$within['west']
                );
                $searchQuery->within($bounds);
            }
        }

        // Distance sort: geo[sort][lat]=10&geo[sort][lng]=20&geo[sort][direction]=asc
        if (isset($geo['sort'])) {
            $sort = $geo['sort'];
            if (isset($sort['lat'], $sort['lng'])) {
                $point = new GeoPoint((float)$sort['lat'], (float)$sort['lng']);
                $searchQuery->sortByDistance($point, $sort['direction'] ?? 'asc');
            }
        }

        // Geo units
        if (isset($geo['units'])) {
            $searchQuery->geoUnits($geo['units']);
        }
    }

    private function mapOperator(string $operator): string
    {
        $operatorMap = [
            'eq' => '=',
            'eqor' => '=?', // Equals value OR empty/null - useful for optional taxonomy fields
            'neq' => '!=',
            'ne' => '!=',
            'gt' => '>',
            'gte' => '>=',
            'lt' => '<',
            'lte' => '<=',
            'like' => 'like',
            'in' => 'in',
            'nin' => 'not in',
            'between' => 'between',
            'exists' => 'exists',
            'null' => 'is null',
            'notnull' => 'is not null'
        ];

        return $operatorMap[strtolower($operator)] ?? '=';
    }

    /**
     * A list operator (in, not in, between) takes a comma-separated string or
     * repeated parameters; any other operator takes the value as it is.
     */
    private function parseValue($value, string $operator)
    {
        if (is_array($value)) {
            return array_map([$this, 'scalar'], array_values($value));
        }

        if (is_string($value) && in_array($operator, self::LIST_OPERATORS, true)) {
            return array_map([$this, 'scalar'], array_map('trim', explode(',', $value)));
        }

        return $this->scalar($value);
    }

    /**
     * 'true', 'false' and 'null' become those values, and a number written the
     * way PHP would write it becomes an int or a float. Anything else stays a
     * string, so 00123, +5, 1e3 and integers too big for PHP keep what was sent.
     */
    private function scalar($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        $keywords = ['true' => true, 'false' => false, 'null' => null];
        if (array_key_exists($value, $keywords)) {
            return $keywords[$value];
        }

        if (preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value)) {
            $int = filter_var($value, FILTER_VALIDATE_INT);
            return $int === false ? $value : $int;
        }

        if (preg_match('/^-?(?:0|[1-9][0-9]*)\.[0-9]+$/', $value)) {
            return (float)$value;
        }

        return $value;
    }

    private function resolveFieldAlias(string $field): string
    {
        return $this->fieldAliases[$field] ?? $field;
    }

    private function isAssociativeArray(array $array): bool
    {
        if (empty($array)) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
