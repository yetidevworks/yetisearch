<?php

namespace YetiSearch\DSL;

use YetiSearch\Models\SearchQuery;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Geo\GeoBounds;
use YetiSearch\Exceptions\InvalidArgumentException;

class URLQueryParser
{
    private array $fieldAliases = [];

    public function __construct(array $fieldAliases = [])
    {
        $this->fieldAliases = $fieldAliases;
    }

    public function parse(array $params): SearchQuery
    {
        // Extract main query
        $queryString = $params['q'] ?? $params['query'] ?? '';
        $searchQuery = new SearchQuery($queryString);

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

        // Parse pagination
        if (isset($params['page'])) {
            $this->parsePagination($params['page'], $searchQuery);
        }

        // Direct pagination params (fallback)
        if (isset($params['limit'])) {
            $searchQuery->limit((int)$params['limit']);
        }
        if (isset($params['offset'])) {
            $searchQuery->offset((int)$params['offset']);
        }

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

        // Parse facets
        if (isset($params['facets']) && is_array($params['facets'])) {
            foreach ($params['facets'] as $field => $options) {
                $searchQuery->facet($field, is_array($options) ? $options : []);
            }
        }

        // Parse geo filters
        if (isset($params['geo']) && is_array($params['geo'])) {
            $this->parseGeoFilters($params['geo'], $searchQuery);
        }

        // Parse language
        if (isset($params['language'])) {
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
            $fieldName = $this->resolveFieldAlias($field);

            if (is_array($conditions)) {
                foreach ($conditions as $operator => $value) {
                    $op = $this->mapOperator($operator);
                    $searchQuery->filter($fieldName, $this->parseValue($value), $op);
                }
            } else {
                // Simple equality filter
                $searchQuery->filter($fieldName, $this->parseValue($conditions), '=');
            }
        }
    }

    private function parseSort(string $sort, SearchQuery $searchQuery): void
    {
        $fields = explode(',', $sort);

        foreach ($fields as $field) {
            $field = trim($field);

            if (empty($field)) {
                continue;
            }

            // Check for descending prefix
            if ($field[0] === '-') {
                $searchQuery->sortBy(substr($field, 1), 'desc');
            } else {
                // Check for explicit asc/desc suffix
                if (strpos($field, ':') !== false) {
                    [$fieldName, $direction] = explode(':', $field, 2);
                    $searchQuery->sortBy($fieldName, $direction);
                } else {
                    $searchQuery->sortBy($field, 'asc');
                }
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

    private function parsePagination($page, SearchQuery $searchQuery): void
    {
        // JSON API style pagination
        // page[limit]=10&page[offset]=20
        // or
        // page[number]=2&page[size]=10

        if (is_array($page)) {
            if (isset($page['limit'])) {
                $searchQuery->limit((int)$page['limit']);
            }
            if (isset($page['offset'])) {
                $searchQuery->offset((int)$page['offset']);
            }

            // Alternative: page number and size
            if (isset($page['number']) && isset($page['size'])) {
                $pageNum = max(1, (int)$page['number']);
                $pageSize = max(1, (int)$page['size']);

                $searchQuery->limit($pageSize);
                $searchQuery->offset(($pageNum - 1) * $pageSize);
            }
        } elseif (is_string($page)) {
            // Simple page number
            $pageNum = max(1, (int)$page);
            $searchQuery->limit(20); // Default page size
            $searchQuery->offset(($pageNum - 1) * 20);
        }
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

    private function parseValue($value)
    {
        // Handle different value formats
        if (is_string($value)) {
            // Check for comma-separated values (for IN operator)
            if (strpos($value, ',') !== false) {
                return array_map('trim', explode(',', $value));
            }

            // Check for boolean strings
            if ($value === 'true') {
                return true;
            }
            if ($value === 'false') {
                return false;
            }
            if ($value === 'null') {
                return null;
            }

            // Check for numeric strings
            if (is_numeric($value)) {
                return strpos($value, '.') !== false ? (float)$value : (int)$value;
            }
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
