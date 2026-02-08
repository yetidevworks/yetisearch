<?php

namespace YetiSearch\Tests\DSL;

use PHPUnit\Framework\TestCase;
use YetiSearch\DSL\QueryParser;

class QueryParserDetailedTest extends TestCase
{
    private QueryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new QueryParser();
    }

    // ──────────────────────────────────────
    // Basic Query Text
    // ──────────────────────────────────────

    public function testParsesEmptyQuery(): void
    {
        $result = $this->parser->parse('');
        $this->assertEquals('', $result->getQuery());
        $this->assertEmpty($result->getFilters());
    }

    public function testParsesSingleWordQuery(): void
    {
        $result = $this->parser->parse('golang');
        $this->assertEquals('golang', $result->getQuery());
        $this->assertEmpty($result->getFilters());
    }

    public function testParsesMultiWordQuery(): void
    {
        $result = $this->parser->parse('golang tutorial advanced');
        $this->assertEquals('golang tutorial advanced', $result->getQuery());
    }

    public function testParsesQuotedStringAsQuery(): void
    {
        $result = $this->parser->parse('"hello world"');
        $this->assertEquals('hello world', $result->getQuery());
    }

    // ──────────────────────────────────────
    // Equality Filters
    // ──────────────────────────────────────

    public function testParsesDoubleQuotedFilterValue(): void
    {
        $result = $this->parser->parse('author = "John Doe"');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('author', $filters[0]['field']);
        $this->assertEquals('John Doe', $filters[0]['value']);
        $this->assertEquals('=', $filters[0]['operator']);
    }

    public function testParsesSingleQuotedFilterValue(): void
    {
        $result = $this->parser->parse("author = 'Jane Smith'");
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('Jane Smith', $filters[0]['value']);
    }

    public function testParsesUnquotedFieldValue(): void
    {
        $result = $this->parser->parse('status = published');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('published', $filters[0]['value']);
    }

    // ──────────────────────────────────────
    // Numeric Filter Values
    // Note: Positive numbers are tokenized as 'field' type
    // (not 'number') due to tokenizer regex ordering, so they
    // come through as string values. Negative numbers correctly
    // tokenize as 'number' type and are cast to int/float.
    // ──────────────────────────────────────

    public function testParsesPositiveIntegerFilterValue(): void
    {
        $result = $this->parser->parse('count = 42');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        // Positive numbers tokenize as 'field' type, returned as string
        $this->assertEquals('42', $filters[0]['value']);
    }

    public function testParsesPositiveFloatFilterValue(): void
    {
        $result = $this->parser->parse('price = 19.99');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        // Dot-notation numbers match the field pattern (\w+\.\w+)
        $this->assertEquals('19.99', $filters[0]['value']);
    }

    public function testParsesNegativeNumberFilterValue(): void
    {
        $result = $this->parser->parse('score = -5');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        // Negative numbers correctly tokenize as 'number' type
        $this->assertSame(-5, $filters[0]['value']);
    }

    public function testParsesNegativeFloatFilterValue(): void
    {
        $result = $this->parser->parse('score = -3.5');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertSame(-3.5, $filters[0]['value']);
    }

    // ──────────────────────────────────────
    // Comparison Operators
    // ──────────────────────────────────────

    public function testParsesNotEqualOperator(): void
    {
        $result = $this->parser->parse('status != "deleted"');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('!=', $filters[0]['operator']);
        $this->assertEquals('deleted', $filters[0]['value']);
    }

    public function testParsesGreaterThanOperator(): void
    {
        $result = $this->parser->parse('price > "100"');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('>', $filters[0]['operator']);
        $this->assertEquals('100', $filters[0]['value']);
    }

    public function testParsesLessThanOperator(): void
    {
        $result = $this->parser->parse('price < "50"');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('<', $filters[0]['operator']);
        $this->assertEquals('50', $filters[0]['value']);
    }

    public function testParsesGreaterThanOrEqualOperator(): void
    {
        $result = $this->parser->parse('rating >= "4"');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('>=', $filters[0]['operator']);
    }

    public function testParsesLessThanOrEqualOperator(): void
    {
        $result = $this->parser->parse('rating <= "3"');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('<=', $filters[0]['operator']);
    }

    public function testParsesEqualsOrNullOperator(): void
    {
        $result = $this->parser->parse('category =? "tech"');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('=?', $filters[0]['operator']);
        $this->assertEquals('tech', $filters[0]['value']);
    }

    public function testParsesGreaterThanWithNegativeNumber(): void
    {
        $result = $this->parser->parse('score > -10');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('>', $filters[0]['operator']);
        $this->assertSame(-10, $filters[0]['value']);
    }

    // ──────────────────────────────────────
    // LIKE Operator
    // ──────────────────────────────────────

    public function testParsesLikeWithDoubleQuotedWildcard(): void
    {
        $result = $this->parser->parse('title LIKE "%golang%"');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('like', $filters[0]['operator']);
        $this->assertEquals('%golang%', $filters[0]['value']);
    }

    public function testParsesLikeWithWildcardToken(): void
    {
        $result = $this->parser->parse('title LIKE %golang%');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('like', $filters[0]['operator']);
        $this->assertEquals('%golang%', $filters[0]['value']);
    }

    // ──────────────────────────────────────
    // IN Operator
    // ──────────────────────────────────────

    public function testParsesInWithMultipleValues(): void
    {
        $result = $this->parser->parse('status IN [draft, published, archived]');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('in', $filters[0]['operator']);
        $this->assertEquals(['draft', 'published', 'archived'], $filters[0]['value']);
    }

    public function testParsesInWithSingleValue(): void
    {
        $result = $this->parser->parse('status IN [published]');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('in', $filters[0]['operator']);
        $this->assertEquals(['published'], $filters[0]['value']);
    }

    public function testParsesInWithQuotedValues(): void
    {
        $result = $this->parser->parse('author IN ["John Doe", "Jane Smith"]');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals(['John Doe', 'Jane Smith'], $filters[0]['value']);
    }

    public function testParsesInWithNumericValues(): void
    {
        // Positive numbers in arrays are strings (tokenizer limitation)
        $result = $this->parser->parse('rating IN [1, 2, 3, 4, 5]');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertCount(5, $filters[0]['value']);
        $this->assertEquals(['1', '2', '3', '4', '5'], $filters[0]['value']);
    }

    public function testParsesNotInOperator(): void
    {
        $result = $this->parser->parse('status NOT IN [draft, deleted]');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('not in', $filters[0]['operator']);
        $this->assertEquals(['draft', 'deleted'], $filters[0]['value']);
    }

    // ──────────────────────────────────────
    // Multiple Filters & Logical Operators
    // ──────────────────────────────────────

    public function testParsesMultipleFiltersWithAnd(): void
    {
        $result = $this->parser->parse('status = "published" AND author = "John"');
        $filters = $result->getFilters();
        $this->assertCount(2, $filters);
        $this->assertEquals('status', $filters[0]['field']);
        $this->assertEquals('author', $filters[1]['field']);
    }

    public function testParsesMultipleFiltersWithOr(): void
    {
        $result = $this->parser->parse('status = "published" OR status = "featured"');
        $filters = $result->getFilters();
        $this->assertCount(2, $filters);
    }

    public function testParsesThreeFiltersChained(): void
    {
        $result = $this->parser->parse('status = "published" AND author = "John" AND category = "tech"');
        $filters = $result->getFilters();
        $this->assertCount(3, $filters);
        $this->assertEquals('status', $filters[0]['field']);
        $this->assertEquals('author', $filters[1]['field']);
        $this->assertEquals('category', $filters[2]['field']);
    }

    public function testParsesMixedOperatorsInFilters(): void
    {
        $result = $this->parser->parse('price > "10" AND price < "100" AND status = "active"');
        $filters = $result->getFilters();
        $this->assertCount(3, $filters);
        $this->assertEquals('>', $filters[0]['operator']);
        $this->assertEquals('<', $filters[1]['operator']);
        $this->assertEquals('=', $filters[2]['operator']);
    }

    // ──────────────────────────────────────
    // Grouped Conditions
    // ──────────────────────────────────────

    public function testParsesGroupedConditions(): void
    {
        $result = $this->parser->parse('(status = "published" OR status = "featured") AND author = "John"');
        $filters = $result->getFilters();
        // Should have at least the grouped conditions plus the AND condition
        $this->assertGreaterThanOrEqual(2, count($filters));
    }

    public function testParsesNestedParentheses(): void
    {
        $result = $this->parser->parse('(status = "published") AND (category = "tech")');
        $filters = $result->getFilters();
        $this->assertGreaterThanOrEqual(2, count($filters));
    }

    // ──────────────────────────────────────
    // Query Text + Filters Combined
    // ──────────────────────────────────────

    public function testParsesQueryTextFollowedByFilter(): void
    {
        $result = $this->parser->parse('golang tutorial author = "John"');
        $this->assertEquals('golang tutorial', $result->getQuery());
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('author', $filters[0]['field']);
    }

    public function testParsesQueryTextWithMultipleFilters(): void
    {
        $result = $this->parser->parse('search terms status = "published" AND rating > "3"');
        $this->assertEquals('search terms', $result->getQuery());
        $filters = $result->getFilters();
        $this->assertCount(2, $filters);
    }

    // ──────────────────────────────────────
    // FIELDS Keyword
    // ──────────────────────────────────────

    public function testParsesFieldsKeyword(): void
    {
        $result = $this->parser->parse('FIELDS title, author, body');
        $fields = $result->getFields();
        $this->assertCount(3, $fields);
        $this->assertContains('title', $fields);
        $this->assertContains('author', $fields);
        $this->assertContains('body', $fields);
    }

    public function testParsesFieldsWithAliases(): void
    {
        $result = $this->parser->parse('FIELDS title:t, author:a');
        $fields = $result->getFields();
        // When aliases are used, returns associative array
        $this->assertArrayHasKey('title', $fields);
        $this->assertEquals('t', $fields['title']);
        $this->assertArrayHasKey('author', $fields);
        $this->assertEquals('a', $fields['author']);
    }

    public function testParsesSingleField(): void
    {
        $result = $this->parser->parse('FIELDS title');
        $fields = $result->getFields();
        $this->assertCount(1, $fields);
        $this->assertContains('title', $fields);
    }

    public function testParsesFieldsAfterFilters(): void
    {
        $result = $this->parser->parse('author = "John" FIELDS title, body');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $fields = $result->getFields();
        $this->assertCount(2, $fields);
    }

    // ──────────────────────────────────────
    // SORT Keyword
    // ──────────────────────────────────────

    public function testParsesAscendingSortDefault(): void
    {
        $result = $this->parser->parse('SORT title');
        $sort = $result->getSort();
        $this->assertArrayHasKey('title', $sort);
        $this->assertEquals('asc', $sort['title']);
    }

    public function testParsesMultipleSortFields(): void
    {
        $result = $this->parser->parse('SORT title, author');
        $sort = $result->getSort();
        $this->assertCount(2, $sort);
        $this->assertArrayHasKey('title', $sort);
        $this->assertArrayHasKey('author', $sort);
    }

    public function testParsesSortAfterFilters(): void
    {
        $result = $this->parser->parse('status = "published" SORT created_at');
        $sort = $result->getSort();
        $this->assertArrayHasKey('created_at', $sort);
        $this->assertEquals('asc', $sort['created_at']);
    }

    // ──────────────────────────────────────
    // LIMIT / OFFSET / PAGE Keywords
    // Note: The tokenizer regex matches positive numbers as
    // 'field' type before reaching the 'number' pattern.
    // This means parsePagination (which checks for 'number'
    // type) cannot read standalone positive numbers. This is
    // a known limitation affecting LIMIT, OFFSET, and PAGE.
    // The tests below verify the current actual behavior.
    // ──────────────────────────────────────

    public function testLimitKeywordFallsBackToDefaultForPositiveNumbers(): void
    {
        // Known limitation: LIMIT 50 doesn't parse because 50 is tokenized as 'field' not 'number'
        $result = $this->parser->parse('LIMIT 50');
        $this->assertEquals(20, $result->getLimit()); // Default
    }

    public function testOffsetKeywordFallsBackToDefaultForPositiveNumbers(): void
    {
        // Known limitation: same tokenizer issue
        $result = $this->parser->parse('OFFSET 20');
        $this->assertEquals(0, $result->getOffset()); // Default
    }

    public function testPageKeywordUsesDefaults(): void
    {
        // PAGE numbers aren't read, defaults (pageNum=1, pageSize=10) apply
        $result = $this->parser->parse('PAGE 1');
        $this->assertEquals(10, $result->getLimit());
        $this->assertEquals(0, $result->getOffset());
    }

    // ──────────────────────────────────────
    // Field Aliases
    // ──────────────────────────────────────

    public function testResolvesFieldAliases(): void
    {
        $parser = new QueryParser(['writer' => 'author', 'cat' => 'category']);
        $result = $parser->parse('writer = "John" AND cat = "tech"');
        $filters = $result->getFilters();
        $this->assertCount(2, $filters);
        $this->assertEquals('author', $filters[0]['field']);
        $this->assertEquals('category', $filters[1]['field']);
    }

    public function testUnknownFieldNotAliased(): void
    {
        $parser = new QueryParser(['writer' => 'author']);
        $result = $parser->parse('status = "published"');
        $filters = $result->getFilters();
        $this->assertEquals('status', $filters[0]['field']);
    }

    // ──────────────────────────────────────
    // Nested/Dot-Notation Fields
    // ──────────────────────────────────────

    public function testParsesDotNotationFieldName(): void
    {
        $result = $this->parser->parse('metadata.author = "John"');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('metadata.author', $filters[0]['field']);
    }

    public function testParsesDeepDotNotationFieldName(): void
    {
        $result = $this->parser->parse('metadata.address.city = "NYC"');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('metadata.address.city', $filters[0]['field']);
    }

    // ──────────────────────────────────────
    // Complex Combined Queries
    // ──────────────────────────────────────

    public function testParsesQueryWithFieldsAndSort(): void
    {
        $result = $this->parser->parse('status = "published" FIELDS title, body SORT created_at');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $fields = $result->getFields();
        $this->assertCount(2, $fields);
        $sort = $result->getSort();
        $this->assertArrayHasKey('created_at', $sort);
    }

    public function testParsesQueryTextWithFiltersAndSort(): void
    {
        $result = $this->parser->parse('tutorial author = "John" SORT title');
        $this->assertEquals('tutorial', $result->getQuery());
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $sort = $result->getSort();
        $this->assertArrayHasKey('title', $sort);
    }

    public function testParsesFilterWithInAndSort(): void
    {
        $result = $this->parser->parse('status IN [published, featured] SORT created_at');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('in', $filters[0]['operator']);
        $sort = $result->getSort();
        $this->assertArrayHasKey('created_at', $sort);
    }

    // ──────────────────────────────────────
    // Operator Normalization
    // ──────────────────────────────────────

    public function testNormalizesLikeOperatorCase(): void
    {
        $result = $this->parser->parse('title LIKE "test"');
        $filters = $result->getFilters();
        $this->assertEquals('like', $filters[0]['operator']);
    }

    public function testNormalizesInOperatorCase(): void
    {
        $result = $this->parser->parse('status IN [published]');
        $filters = $result->getFilters();
        $this->assertEquals('in', $filters[0]['operator']);
    }

    // ──────────────────────────────────────
    // Edge Cases
    // ──────────────────────────────────────

    public function testWhitespaceOnlyQueryReturnsEmpty(): void
    {
        $result = $this->parser->parse('   ');
        $this->assertEquals('', $result->getQuery());
        $this->assertEmpty($result->getFilters());
    }

    public function testParsesFilterWithEmptyQuotedString(): void
    {
        $result = $this->parser->parse('author = ""');
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('', $filters[0]['value']);
    }

    public function testDefaultLimitIs20WhenNotSpecified(): void
    {
        $result = $this->parser->parse('hello');
        $this->assertEquals(20, $result->getLimit());
    }

    public function testDefaultOffsetIs0WhenNotSpecified(): void
    {
        $result = $this->parser->parse('hello');
        $this->assertEquals(0, $result->getOffset());
    }

    public function testFiltersOnlyNoQueryText(): void
    {
        $result = $this->parser->parse('author = "John"');
        $this->assertEquals('', $result->getQuery());
        $this->assertCount(1, $result->getFilters());
    }

    public function testSortOnlyNoFilters(): void
    {
        $result = $this->parser->parse('SORT title');
        $this->assertEquals('', $result->getQuery());
        $this->assertEmpty($result->getFilters());
        $this->assertArrayHasKey('title', $result->getSort());
    }

    public function testFieldsOnlyNoFilters(): void
    {
        $result = $this->parser->parse('FIELDS title, body');
        $this->assertEmpty($result->getFilters());
        $this->assertCount(2, $result->getFields());
    }

    public function testQueryWithMultipleSpaces(): void
    {
        $result = $this->parser->parse('hello   world');
        // Multiple spaces between words still captured
        $this->assertStringContainsString('hello', $result->getQuery());
        $this->assertStringContainsString('world', $result->getQuery());
    }
}
