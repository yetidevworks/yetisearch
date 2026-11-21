<?php

namespace YetiSearch\DSL;

use YetiSearch\Models\SearchQuery;

/**
 * Parses the query DSL: search text, conditions (`field operator value`) and the
 * keyword clauses FIELDS, SORT, PAGE, LIMIT, OFFSET and FUZZY, in any order.
 *
 * Operators and keywords are whole words, in any case. A keyword only counts when
 * its arguments follow it (`LIMIT 10`, `SORT -date`), and IN or LIKE only when a
 * value of their kind does (`IN [a, b]`, `LIKE "%a%"`), so text such as "landing
 * page" or "made in china" stays search text. Conditions are all joined with AND:
 * OR and parentheses are read, but do not group anything.
 */
class QueryParser
{
    private const TOKEN_PATTERN = '/
        (?P<string>"[^"]*"|\'[^\']*\')
        | (?P<operator>=\?|!=|>=|<=|>|<|=|\b(?:LIKE|NOT\s+IN|IN|AND|OR)\b)
        | (?P<keyword>\b(?:FIELDS|SORT|PAGE|LIMIT|OFFSET|FUZZY)\b)
        | (?P<number>-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?![\w.-]))
        | (?P<field>[a-z_][a-z0-9_]*(?:\.[a-z0-9_]+)*(?![\w.-]))
        | (?P<word>\w+(?:[.-]\w+)*)
        | (?P<minus>-(?=[a-z_]))
        | (?P<bracket>[\[\]])
        | (?P<paren>[()])
        | (?P<comma>,)
        | (?P<colon>:)
        | (?P<wildcard>%\w*%)
    /iux';

    /** The token types a condition takes as its value */
    private const VALUE_TYPES = ['string', 'number', 'field', 'word', 'wildcard'];

    private array $tokens = [];
    private int $position = 0;
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

    public function parse(string $input): SearchQuery
    {
        $this->tokens = $this->tokenize($input);
        $this->position = 0;

        $searchQuery = new SearchQuery('', $this->defaults);
        $text = [];
        $afterCondition = false;

        // Every branch moves past at least one token
        while (($token = $this->current()) !== null) {
            if ($this->parseClause($searchQuery)) {
                continue;
            }

            if ($this->conditionAt($this->position)) {
                $this->parseCondition($searchQuery);
                $afterCondition = true;
                continue;
            }

            $this->position++;

            // Parentheses and stray brackets are left out of the text, so a query of
            // conditions only does not search for "(".
            if ($token['type'] === 'paren' || $token['type'] === 'bracket') {
                continue;
            }

            // AND and OR join conditions; between words they are words
            $joinsConditions = $afterCondition || $this->conditionAt($this->skipParens($this->position));
            if ($this->isLogical($token) && $joinsConditions) {
                continue;
            }

            $value = $token['type'] === 'string' ? substr($token['value'], 1, -1) : $token['value'];
            if ($value !== '') {
                $text[] = $value;
            }
            $afterCondition = false;
        }

        return $searchQuery->setQuery(implode(' ', $text));
    }

    /**
     * Each token is ['type' => group name, 'value' => matched text, 'negated' => bool].
     * A '-' right before a word is not a token of its own: it marks that word as
     * negated, which SORT reads as descending. Characters no group matches are skipped.
     * Words are Unicode words; field names are ASCII, as the storage takes them.
     */
    private function tokenize(string $input): array
    {
        if (preg_match_all(self::TOKEN_PATTERN, $input, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) === false) {
            // Not valid UTF-8: all of it is search text
            return [['type' => 'word', 'value' => $input, 'negated' => false]];
        }

        $tokens = [];
        $negated = false;
        foreach ($matches as $match) {
            $named = array_filter($match, function ($value, $key) {
                return is_string($key) && $value !== null;
            }, ARRAY_FILTER_USE_BOTH);
            $type = (string)key($named);

            if ($type === 'minus') {
                $negated = true;
                continue;
            }

            $tokens[] = ['type' => $type, 'value' => (string)current($named), 'negated' => $negated];
            $negated = false;
        }

        return $tokens;
    }

    /**
     * Parses the keyword clause at the current token, when its arguments follow it.
     * A keyword without them is left as search text.
     */
    private function parseClause(SearchQuery $searchQuery): bool
    {
        $keyword = $this->current();
        if ($keyword === null || $keyword['type'] !== 'keyword') {
            return false;
        }

        $next = $this->position + 1;

        switch (strtoupper($keyword['value'])) {
            case 'FIELDS':
                if (!$this->typeAt($next, 'field')) {
                    return false;
                }
                $this->position = $next;
                $searchQuery->inFields($this->parseFields());
                return true;

            case 'SORT':
                if (!$this->typeAt($next, 'field')) {
                    return false;
                }
                $this->position = $next;
                $this->parseSort($searchQuery);
                return true;

            case 'LIMIT':
            case 'OFFSET':
                $count = $this->countAt($next);
                if ($count === null) {
                    return false;
                }
                $this->position = $next + 1;
                if (strtoupper($keyword['value']) === 'LIMIT') {
                    $searchQuery->limit($count);
                } else {
                    $searchQuery->offset($count);
                }
                return true;

            case 'PAGE':
                // PAGE number[, size]
                $page = $this->countAt($next);
                if ($page === null) {
                    return false;
                }
                $this->position = $next + 1;
                $size = 10;
                if ($this->typeAt($this->position, 'comma') && $this->countAt($this->position + 1) !== null) {
                    $size = $this->countAt($this->position + 1);
                    $this->position += 2;
                }
                $searchQuery->limit($size);
                $searchQuery->offset((max(1, $page) - 1) * $size);
                return true;

            case 'FUZZY':
                // A flag with no arguments: it counts after the search text, at the end of
                // the query or before another clause, so "fuzzy logic" stays text
                $following = $this->tokens[$next] ?? null;
                $endsClause = $following === null || $following['type'] === 'keyword' || $this->conditionAt($next);
                if ($this->position === 0 || !$endsClause) {
                    return false;
                }
                $this->position = $next;
                $searchQuery->fuzzy(true);
                return true;
        }

        return false;
    }

    /**
     * Whether a condition starts at token $i: a field, a comparison operator and a
     * value of the operator's kind.
     */
    private function conditionAt(int $i): bool
    {
        $field = $this->tokens[$i] ?? null;
        $operator = $this->tokens[$i + 1] ?? null;
        $value = $this->tokens[$i + 2] ?? null;
        if ($field === null || $operator === null || $value === null) {
            return false;
        }
        if ($field['type'] !== 'field' || $operator['type'] !== 'operator') {
            return false;
        }

        switch ($this->normalizeOperator($operator['value'])) {
            case 'and':
            case 'or':
                return false;
            case 'in':
            case 'not in':
                return $value['type'] === 'bracket' && $value['value'] === '[';
            case 'like':
                return $value['type'] === 'string' || $value['type'] === 'wildcard';
            default:
                return in_array($value['type'], self::VALUE_TYPES, true);
        }
    }

    private function parseCondition(SearchQuery $searchQuery): void
    {
        $field = $this->tokens[$this->position];
        $operator = $this->tokens[$this->position + 1];
        $this->position += 2;

        $searchQuery->filter(
            $this->resolveFieldAlias($field['value']),
            $this->parseValue(),
            $this->normalizeOperator($operator['value'])
        );
    }

    /**
     * Reads the value at the current token: a scalar, or a [list] up to its closing
     * bracket or the end of the query.
     */
    private function parseValue()
    {
        $token = $this->tokens[$this->position++];

        if ($token['type'] === 'bracket') {
            $values = [];
            while (($item = $this->current()) !== null && $item['type'] !== 'bracket') {
                if (in_array($item['type'], self::VALUE_TYPES, true)) {
                    $values[] = $this->parseValue();
                } else {
                    $this->position++;
                }
            }
            $this->position++; // the closing bracket
            return $values;
        }

        if ($token['type'] === 'string') {
            return substr($token['value'], 1, -1);
        }

        if ($token['type'] === 'number') {
            if (strpos($token['value'], '.') !== false) {
                return (float)$token['value'];
            }
            // An integer too big for PHP stays a string rather than be clamped
            $int = filter_var($token['value'], FILTER_VALIDATE_INT);
            return $int === false ? $token['value'] : $int;
        }

        return $token['value'];
    }

    /**
     * FIELDS a, b, c or FIELDS a:alias, b:alias. With aliases it returns a map of
     * field => alias, as it always has, in which a field without one is its own.
     */
    private function parseFields(): array
    {
        $fields = [];
        $aliases = [];
        $aliased = false;

        while (true) {
            $field = $this->tokens[$this->position++]['value'];
            if ($this->typeAt($this->position, 'colon') && $this->typeAt($this->position + 1, 'field')) {
                $aliases[$field] = $this->tokens[$this->position + 1]['value'];
                $aliased = true;
                $this->position += 2;
            } else {
                $fields[] = $field;
                $aliases[$field] = $field;
            }

            if (!$this->typeAt($this->position, 'comma') || !$this->typeAt($this->position + 1, 'field')) {
                break;
            }
            $this->position++;
        }

        return $aliased ? $aliases : $fields;
    }

    /**
     * SORT a, -b, c:desc
     */
    private function parseSort(SearchQuery $searchQuery): void
    {
        while (true) {
            $field = $this->tokens[$this->position++];
            $direction = $field['negated'] ? 'desc' : 'asc';

            $suffix = $this->tokens[$this->position + 1]['value'] ?? '';
            if ($this->typeAt($this->position, 'colon') && in_array(strtolower($suffix), ['asc', 'desc'], true)) {
                $direction = strtolower($suffix);
                $this->position += 2;
            }

            $searchQuery->sortBy($field['value'], $direction);

            if (!$this->typeAt($this->position, 'comma') || !$this->typeAt($this->position + 1, 'field')) {
                break;
            }
            $this->position++;
        }
    }

    /**
     * The whole number at token $i, or null when it is not one
     */
    private function countAt(int $i): ?int
    {
        if (!$this->typeAt($i, 'number')) {
            return null;
        }
        $count = filter_var($this->tokens[$i]['value'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $count === false ? null : $count;
    }

    /**
     * Lower case, with one space in NOT IN
     */
    private function normalizeOperator(string $operator): string
    {
        return strtolower((string)preg_replace('/\s+/', ' ', $operator));
    }

    private function isLogical(array $token): bool
    {
        return $token['type'] === 'operator'
            && in_array($this->normalizeOperator($token['value']), ['and', 'or'], true);
    }

    private function skipParens(int $i): int
    {
        while ($this->typeAt($i, 'paren')) {
            $i++;
        }

        return $i;
    }

    private function typeAt(int $i, string $type): bool
    {
        return ($this->tokens[$i]['type'] ?? null) === $type;
    }

    private function resolveFieldAlias(string $field): string
    {
        return $this->fieldAliases[$field] ?? $field;
    }

    private function current(): ?array
    {
        return $this->tokens[$this->position] ?? null;
    }
}
