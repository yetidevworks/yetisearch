<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Tests\TestCase;

/**
 * Queries typed by end users routinely contain characters that are operators in
 * the FTS5 MATCH grammar (hyphens in order numbers and SKUs, quotes, colons,
 * asterisks, parentheses). None of them may reach MATCH unescaped.
 */
class SpecialCharacterQueryTest extends TestCase
{
    private const INDEX = 'idx_special_chars';

    private function seedIndex(): \YetiSearch\YetiSearch
    {
        $search = $this->createSearchInstance();
        $this->createTestIndex(self::INDEX);

        $search->index(self::INDEX, [
            'id' => 'order-1',
            'content' => [
                'title' => 'Order BENCH-100821',
                'content' => 'Shipped order BENCH-100821 containing one widget.',
            ],
        ]);
        $search->index(self::INDEX, [
            'id' => 'product-1',
            'content' => [
                'title' => 'Blue Widget',
                'content' => 'Catalog item with SKU ABC-123 and a state-of-the-art finish.',
            ],
        ]);

        return $search;
    }

    public function test_hyphenated_order_number_returns_the_matching_document(): void
    {
        $search = $this->seedIndex();

        $results = $search->search(self::INDEX, 'BENCH-100821');

        $this->assertGreaterThan(0, $results['total']);
        $ids = array_column($results['results'], 'id');
        $this->assertContains('order-1', $ids);
    }

    public function test_hyphenated_sku_returns_the_matching_document(): void
    {
        $search = $this->seedIndex();

        $results = $search->search(self::INDEX, 'ABC-123');

        $this->assertGreaterThan(0, $results['total']);
        $ids = array_column($results['results'], 'id');
        $this->assertContains('product-1', $ids);
    }

    public function test_hyphenated_word_returns_the_matching_document(): void
    {
        $search = $this->seedIndex();

        $results = $search->search(self::INDEX, 'state-of-the-art');

        $this->assertGreaterThan(0, $results['total']);
        $ids = array_column($results['results'], 'id');
        $this->assertContains('product-1', $ids);
    }

    /**
     * @dataProvider specialCharacterQueries
     */
    public function test_special_character_queries_never_throw(string $query): void
    {
        $search = $this->seedIndex();

        $results = $search->search(self::INDEX, $query);

        $this->assertIsArray($results);
        $this->assertArrayHasKey('results', $results);
        $this->assertIsArray($results['results']);
    }

    public static function specialCharacterQueries(): array
    {
        return [
            'hyphen' => ['BENCH-100821'],
            'hyphen then digits' => ['ABC-123'],
            'hyphen then letters' => ['foo-bar'],
            'leading hyphen' => ['-100821'],
            'trailing hyphen' => ['widget-'],
            'double hyphen' => ['foo--bar'],
            'apostrophe' => ["widget's"],
            'double quote' => ['say "widget"'],
            'unbalanced quote' => ['widget"'],
            'asterisk' => ['widget*'],
            'colon' => ['title:widget'],
            'parentheses' => ['(widget)'],
            'caret' => ['^widget'],
            'plus' => ['widget+order'],
            'comma' => ['widget,order'],
            'braces' => ['{widget}'],
            'bare NEAR' => ['NEAR'],
            'bare AND' => ['AND'],
            'bare OR' => ['OR'],
            'bare NOT' => ['NOT'],
            'NEAR expression' => ['NEAR(widget order, 2)'],
            'AND expression' => ['widget AND order'],
            'OR expression' => ['widget OR order'],
            'NOT expression' => ['widget NOT order'],
            'stray operators' => ['AND OR NOT NEAR'],
            'mixed punctuation' => ['BENCH-100821: "widget" (x2)*'],
            'only punctuation' => ['---'],
        ];
    }

    public function test_hyphenated_query_works_with_fuzzy_enabled(): void
    {
        $search = $this->seedIndex();

        $results = $search->search(self::INDEX, 'BENCH-100821', ['fuzzy' => true]);

        $this->assertIsArray($results['results']);
    }

    public function test_hyphenated_query_works_with_prefix_last_token(): void
    {
        $search = $this->createSearchInstance([
            'search' => ['prefix_last_token' => true],
        ]);
        $this->createTestIndex(self::INDEX);
        $search->index(self::INDEX, [
            'id' => 'order-1',
            'content' => ['title' => 'Order BENCH-100821', 'content' => 'A widget order.'],
        ]);

        $results = $search->search(self::INDEX, 'BENCH-100821');

        $this->assertIsArray($results['results']);
        $ids = array_column($results['results'], 'id');
        $this->assertContains('order-1', $ids);
    }

    /**
     * A colon is FTS5 column-filter syntax. The plain search() path has no
     * column-filter feature — that is the DSL's job (`field = "value"`) — so
     * "title:widget" is nothing but user text, and it must never restrict the
     * match to the "title" column. Here "widget" lives only in the content
     * field, so a column filter would return nothing.
     */
    public function test_field_prefix_text_does_not_filter_by_column(): void
    {
        $search = $this->seedIndex();

        $results = $search->search(self::INDEX, 'title:widget');

        $ids = array_column($results['results'], 'id');
        $this->assertContains('product-1', $ids);
    }

    public function test_field_prefix_text_with_an_unknown_column_still_searches(): void
    {
        $search = $this->seedIndex();

        $results = $search->search(self::INDEX, 'nosuchcolumn:widget');

        $ids = array_column($results['results'], 'id');
        $this->assertContains('product-1', $ids);
    }

    public function test_bare_field_prefix_returns_no_results_rather_than_everything(): void
    {
        $search = $this->seedIndex();

        $results = $search->search(self::INDEX, 'foo:');

        $this->assertSame(0, $results['total']);
    }

    /**
     * A query the user actually typed that contains no searchable term at all
     * must come back empty. Falling through to the unfiltered match-all branch
     * answers a search box full of punctuation with the entire index.
     *
     * @dataProvider termlessQueries
     */
    public function test_queries_with_no_searchable_term_return_no_results(string $query): void
    {
        $search = $this->seedIndex();

        $results = $search->search(self::INDEX, $query);

        $this->assertSame(0, $results['total'], "Query {$query} should not match every document");
        $this->assertSame([], $results['results']);
    }

    public static function termlessQueries(): array
    {
        return [
            'colon' => [':'],
            'ellipsis' => ['...'],
            'hyphens' => ['---'],
            'asterisk' => ['*'],
            'double asterisk' => ['**'],
            'quote' => ['"'],
            'empty quotes' => ['""'],
            'parentheses' => ['()'],
            'caret' => ['^'],
            'mixed punctuation' => ['!?.,;'],
        ];
    }

    /**
     * An empty (or whitespace-only) query is the caller saying "no text query",
     * which is how geo-only and facet-only searches are expressed. That branch
     * stays a match-all.
     *
     * @dataProvider blankQueries
     */
    public function test_a_blank_query_remains_a_match_all(string $query): void
    {
        $search = $this->seedIndex();

        $results = $search->search(self::INDEX, $query);

        $this->assertSame(2, $results['total']);
    }

    public static function blankQueries(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ['   '],
        ];
    }

    public function test_count_agrees_with_search_on_a_termless_query(): void
    {
        $search = $this->seedIndex();

        $engine = $search->getSearchEngine(self::INDEX);

        $this->assertSame(0, $engine->count(new \YetiSearch\Models\SearchQuery('...')));
    }

    /**
     * With punctuation stripping turned off the raw token reaches the escaper,
     * and a token that is nothing but the prefix operator used to collapse to
     * an empty string that was then joined into the MATCH expression, producing
     * "widget OR " and an fts5 syntax error.
     */
    public function test_operator_only_token_does_not_break_the_match_expression(): void
    {
        $search = $this->createSearchInstance([
            'analyzer' => ['strip_punctuation' => false],
        ]);
        $this->createTestIndex(self::INDEX);
        $search->index(self::INDEX, [
            'id' => 'product-1',
            'content' => ['title' => 'Blue Widget', 'content' => 'A widget.'],
        ]);

        $results = $search->search(self::INDEX, 'widget *');

        $ids = array_column($results['results'], 'id');
        $this->assertContains('product-1', $ids);
    }

    public function test_engine_count_accepts_hyphenated_queries(): void
    {
        $search = $this->seedIndex();

        $engine = $search->getSearchEngine(self::INDEX);
        $count = $engine->count(new \YetiSearch\Models\SearchQuery('BENCH-100821'));

        $this->assertGreaterThan(0, $count);
    }
}
