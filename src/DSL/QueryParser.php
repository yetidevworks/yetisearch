<?php

namespace YetiSearch\DSL;

use YetiSearch\Models\SearchQuery;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Geo\GeoBounds;
use YetiSearch\Exceptions\InvalidArgumentException;

class QueryParser
{
    private array $tokens = [];
    private int $position = 0;
    private array $fieldAliases = [];

    public function __construct(array $fieldAliases = [])
    {
        $this->fieldAliases = $fieldAliases;
    }

    public function parse(string $input): SearchQuery
    {
        $this->tokens = $this->tokenize($input);
        $this->position = 0;

        $query = $this->parseQuery();
        $searchQuery = new SearchQuery($query['query']);

        // Apply filters
        foreach ($query['filters'] as $filter) {
            $searchQuery->filter($filter['field'], $filter['value'], $filter['operator']);
        }

        // Apply fields selection
        if (!empty($query['fields'])) {
            $searchQuery->inFields($query['fields']);
        }

        // Apply sorting
        foreach ($query['sort'] as $field => $direction) {
            $searchQuery->sortBy($field, $direction);
        }

        // Apply pagination
        if (isset($query['limit'])) {
            $searchQuery->limit($query['limit']);
        }
        if (isset($query['offset'])) {
            $searchQuery->offset($query['offset']);
        }

        return $searchQuery;
    }

    private function tokenize(string $input): array
    {
        $tokens = [];
        $pattern = '/
            (?P<string>"[^"]*"|\'[^\']*\')           | # Quoted strings
            (?P<operator>=|!=|>=|<=|>|<|LIKE|IN|NOT\s+IN|AND|OR) | # Operators (check before field)
            (?P<keyword>FIELDS|SORT|PAGE|LIMIT|OFFSET|FUZZY|HIGHLIGHT|NEAR|WITHIN) | # Keywords
            (?P<field>\w+(?:\.\w+)*)                 | # Field names (with dots for nested)
            (?P<bracket>\[|\])                       | # Array brackets
            (?P<paren>\(|\))                         | # Parentheses
            (?P<comma>,)                              | # Comma
            (?P<colon>:)                              | # Colon
            (?P<number>-?\d+\.?\d*)                  | # Numbers
            (?P<wildcard>%\w*%)                      | # Wildcards
            (?P<whitespace>\s+)                      # Whitespace
        /ix';

        preg_match_all($pattern, $input, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            foreach ($match as $key => $value) {
                if (!is_numeric($key) && $value !== '' && $key !== 'whitespace') {
                    $tokens[] = [
                        'type' => $key,
                        'value' => $value
                    ];
                    break;
                }
            }
        }

        return $tokens;
    }

    private function parseQuery(): array
    {
        $result = [
            'query' => '',
            'filters' => [],
            'fields' => [],
            'sort' => [],
            'limit' => null,
            'offset' => null
        ];

        $queryParts = [];
        $inQuery = true;

        while ($this->position < count($this->tokens)) {
            $token = $this->current();

            if (!$token) {
                break;
            }

            // Check for keywords that end the query part
            if ($token['type'] === 'keyword') {
                $inQuery = false;

                switch (strtoupper($token['value'])) {
                    case 'FIELDS':
                        $this->next();
                        $result['fields'] = $this->parseFields();
                        break;

                    case 'SORT':
                        $this->next();
                        $result['sort'] = $this->parseSort();
                        break;

                    case 'PAGE':
                    case 'LIMIT':
                    case 'OFFSET':
                        $this->next();
                        $pagination = $this->parsePagination($token['value']);
                        if (isset($pagination['limit'])) {
                            $result['limit'] = $pagination['limit'];
                        }
                        if (isset($pagination['offset'])) {
                            $result['offset'] = $pagination['offset'];
                        }
                        break;

                    case 'NEAR':
                    case 'WITHIN':
                        // Geo queries - store as part of filters for now
                        $this->next();
                        $result['filters'][] = $this->parseGeoFilter($token['value']);
                        break;
                }
            } elseif ($inQuery && $this->isCondition()) {
                // This is a filter condition
                $inQuery = false;
                $result['filters'] = array_merge($result['filters'], $this->parseConditions());
            } elseif ($inQuery) {
                // Part of the search query
                if ($token['type'] === 'string') {
                    $queryParts[] = trim($token['value'], '"\'');
                } else {
                    $queryParts[] = $token['value'];
                }
                $this->next();
            } else {
                // Process filters and conditions
                if ($this->isCondition()) {
                    $result['filters'] = array_merge($result['filters'], $this->parseConditions());
                } else {
                    $this->next();
                }
            }
        }

        $result['query'] = trim(implode(' ', $queryParts));

        return $result;
    }

    private function isCondition(): bool
    {
        if ($this->position >= count($this->tokens) - 1) {
            return false;
        }

        $current = $this->current();
        $next = $this->peek();

        // Check if current is a field and next is an operator
        return $current && $next &&
               $current['type'] === 'field' &&
               in_array($next['type'], ['operator']);
    }

    private function parseConditions(): array
    {
        $conditions = [];
        $currentGroup = [];
        $logicalOp = 'AND';

        while ($this->position < count($this->tokens)) {
            $token = $this->current();

            if (!$token) {
                break;
            }

            // Check for logical operators
            if ($token['type'] === 'operator' && in_array(strtoupper($token['value']), ['AND', 'OR'])) {
                $logicalOp = strtoupper($token['value']);
                $this->next();
                continue;
            }

            // Check for parentheses (grouped conditions)
            if ($token['type'] === 'paren' && $token['value'] === '(') {
                $this->next();
                $grouped = $this->parseGroupedConditions();
                $currentGroup[] = ['grouped' => $grouped, 'operator' => $logicalOp];
                continue;
            }

            // Break on keywords
            if ($token['type'] === 'keyword') {
                break;
            }

            // Parse individual condition
            if ($this->isCondition()) {
                $condition = $this->parseCondition();
                if ($condition) {
                    $conditions[] = $condition;
                }
            } else {
                break;
            }
        }

        return $conditions;
    }

    private function parseGroupedConditions(): array
    {
        $conditions = [];

        while ($this->position < count($this->tokens)) {
            $token = $this->current();

            if (!$token) {
                break;
            }

            if ($token['type'] === 'paren' && $token['value'] === ')') {
                $this->next();
                break;
            }

            if ($this->isCondition()) {
                $condition = $this->parseCondition();
                if ($condition) {
                    $conditions[] = $condition;
                }
            } else {
                $this->next();
            }
        }

        return $conditions;
    }

    private function parseCondition(): ?array
    {
        $field = $this->current();
        if (!$field || $field['type'] !== 'field') {
            return null;
        }

        $fieldName = $this->resolveFieldAlias($field['value']);
        $this->next();

        $operator = $this->current();
        if (!$operator || $operator['type'] !== 'operator') {
            return null;
        }

        $op = $this->normalizeOperator($operator['value']);
        $this->next();

        // Handle negation
        $negate = false;
        if ($this->current() && $this->current()['value'] === '-') {
            $negate = true;
            $this->next();
        }

        $value = $this->parseValue();

        if ($negate) {
            $op = $this->negateOperator($op);
        }

        return [
            'field' => $fieldName,
            'operator' => $op,
            'value' => $value
        ];
    }

    private function parseValue()
    {
        $token = $this->current();

        if (!$token) {
            return null;
        }

        // Array values [value1, value2]
        if ($token['type'] === 'bracket' && $token['value'] === '[') {
            return $this->parseArrayValue();
        }

        // String values
        if ($token['type'] === 'string') {
            $this->next();
            return trim($token['value'], '"\'');
        }

        // Number values
        if ($token['type'] === 'number') {
            $this->next();
            return is_numeric($token['value']) ?
                   (strpos($token['value'], '.') !== false ?
                    (float)$token['value'] : (int)$token['value']) :
                   $token['value'];
        }

        // Wildcard values
        if ($token['type'] === 'wildcard') {
            $this->next();
            return $token['value'];
        }

        // Field name as value
        if ($token['type'] === 'field') {
            $this->next();
            return $token['value'];
        }

        $this->next();
        return $token['value'] ?? null;
    }

    private function parseArrayValue(): array
    {
        $values = [];
        $this->next(); // Skip [

        while ($this->position < count($this->tokens)) {
            $token = $this->current();

            if (!$token) {
                break;
            }

            if ($token['type'] === 'bracket' && $token['value'] === ']') {
                $this->next();
                break;
            }

            if ($token['type'] === 'comma') {
                $this->next();
                continue;
            }

            $values[] = $this->parseValue();
        }

        return $values;
    }

    private function parseFields(): array
    {
        $fields = [];
        $fieldMap = [];

        while ($this->position < count($this->tokens)) {
            $token = $this->current();

            if (!$token || $token['type'] === 'keyword') {
                break;
            }

            if ($token['type'] === 'field') {
                $fieldName = $token['value'];
                $this->next();

                // Check for alias (field:alias)
                if ($this->current() && $this->current()['type'] === 'colon') {
                    $this->next();
                    if ($this->current() && $this->current()['type'] === 'field') {
                        $alias = $this->current()['value'];
                        $fieldMap[$fieldName] = $alias;
                        $this->next();
                    }
                } else {
                    $fields[] = $fieldName;
                }
            } elseif ($token['type'] === 'comma') {
                $this->next();
            } else {
                break;
            }
        }

        return !empty($fieldMap) ? $fieldMap : $fields;
    }

    private function parseSort(): array
    {
        $sort = [];

        while ($this->position < count($this->tokens)) {
            $token = $this->current();

            if (!$token || $token['type'] === 'keyword') {
                break;
            }

            $direction = 'asc';

            // Check for - prefix (descending)
            if ($token['value'] === '-') {
                $direction = 'desc';
                $this->next();
                $token = $this->current();
            }

            if ($token && $token['type'] === 'field') {
                $sort[$token['value']] = $direction;
                $this->next();
            }

            // Skip commas
            if ($this->current() && $this->current()['type'] === 'comma') {
                $this->next();
            }
        }

        return $sort;
    }

    private function parsePagination(string $keyword): array
    {
        $result = [];

        if (strtoupper($keyword) === 'PAGE') {
            // PAGE format: PAGE 1,10 (page number, items per page)
            $pageNum = 1;
            $pageSize = 10;

            if ($this->current() && $this->current()['type'] === 'number') {
                $pageNum = (int)$this->current()['value'];
                $this->next();

                if ($this->current() && $this->current()['type'] === 'comma') {
                    $this->next();
                    if ($this->current() && $this->current()['type'] === 'number') {
                        $pageSize = (int)$this->current()['value'];
                        $this->next();
                    }
                }
            }

            $result['limit'] = $pageSize;
            $result['offset'] = ($pageNum - 1) * $pageSize;
        } elseif (strtoupper($keyword) === 'LIMIT') {
            if ($this->current() && $this->current()['type'] === 'number') {
                $result['limit'] = (int)$this->current()['value'];
                $this->next();
            }
        } elseif (strtoupper($keyword) === 'OFFSET') {
            if ($this->current() && $this->current()['type'] === 'number') {
                $result['offset'] = (int)$this->current()['value'];
                $this->next();
            }
        }

        return $result;
    }

    private function parseGeoFilter(string $type): array
    {
        // Simplified geo filter parsing - would need full implementation
        return [
            'field' => '_geo',
            'operator' => strtolower($type),
            'value' => []
        ];
    }

    private function normalizeOperator(string $operator): string
    {
        $map = [
            '=' => '=',
            '!=' => '!=',
            '>' => '>',
            '<' => '<',
            '>=' => '>=',
            '<=' => '<=',
            'LIKE' => 'like',
            'IN' => 'in',
            'NOT IN' => 'not in'
        ];

        return $map[strtoupper($operator)] ?? '=';
    }

    private function negateOperator(string $operator): string
    {
        $map = [
            '=' => '!=',
            '!=' => '=',
            '>' => '<=',
            '<' => '>=',
            '>=' => '<',
            '<=' => '>',
            'like' => 'not like',
            'in' => 'not in',
            'not in' => 'in'
        ];

        return $map[$operator] ?? $operator;
    }

    private function resolveFieldAlias(string $field): string
    {
        return $this->fieldAliases[$field] ?? $field;
    }

    private function current(): ?array
    {
        return $this->tokens[$this->position] ?? null;
    }

    private function peek(): ?array
    {
        return $this->tokens[$this->position + 1] ?? null;
    }

    private function next(): void
    {
        $this->position++;
    }
}
