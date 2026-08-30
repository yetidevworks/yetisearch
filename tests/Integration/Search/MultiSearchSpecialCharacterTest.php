<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Tests\TestCase;

/**
 * multiSearch() does not go through SearchEngine, so raw user text reaches
 * FTS5 MATCH directly. Special characters that are operators in the MATCH
 * grammar must still behave as literal user text, a termless query must not
 * match the whole index, and a blank query must keep its match-all meaning.
 */
class MultiSearchSpecialCharacterTest extends TestCase
{
    private const INDEX_A = 'idx_multi_a';
    private const INDEX_B = 'idx_multi_b';

    private function seedIndices(array $config = []): \YetiSearch\YetiSearch
    {
        $search = $this->createSearchInstance($config);
        $this->createTestIndex(self::INDEX_A);
        $this->createTestIndex(self::INDEX_B);

        $search->index(self::INDEX_A, [
            'id' => 'order-1',
            'content' => [
                'title' => 'Order BENCH-100821',
                'content' => 'Shipped order BENCH-100821 containing one widget.',
            ],
        ]);
        $search->index(self::INDEX_B, [
            'id' => 'product-1',
            'content' => [
                'title' => 'Blue Widget',
                'content' => 'Catalog item with SKU ABC-123 and a widget price.',
            ],
        ]);
        $search->index(self::INDEX_B, [
            'id' => 'guide-1',
            'content' => [
                'title' => 'Naming Guide',
                'content' => 'How to title a widget properly, with examples.',
            ],
        ]);

        return $search;
    }

    public function test_hyphenated_query_finds_the_matching_document_across_indices(): void
    {
        $search = $this->seedIndices();

        $results = $search->multiSearch([self::INDEX_A, self::INDEX_B], 'BENCH-100821');

        $this->assertGreaterThan(0, $results['total']);
        $ids = array_column($results['results'], 'id');
        $this->assertContains('order-1', $ids);
    }

    public function test_hyphenated_query_finds_documents_in_every_index(): void
    {
        $search = $this->seedIndices();

        $results = $search->multiSearch([self::INDEX_A, self::INDEX_B], 'widget');

        $indices = array_column($results['results'], '_index');
        $this->assertContains(self::INDEX_A, $indices);
        $this->assertContains(self::INDEX_B, $indices);
    }

    /**
     * A colon is FTS5 column-filter syntax. Reaching a column filter is the
     * DSL's job, so "title:widget" is literal user text. multiSearch joins
     * tokens with FTS5's implicit AND, so a document containing both words
     * must match — a column filter on "title" would return nothing, since
     * "widget" lives only in the content field.
     */
    public function test_field_prefix_text_does_not_filter_by_column(): void
    {
        $search = $this->seedIndices();

        $results = $search->multiSearch([self::INDEX_B], 'title:widget');

        $ids = array_column($results['results'], 'id');
        $this->assertContains('guide-1', $ids);
    }

    public function test_quoted_phrase_is_literal_user_text(): void
    {
        $search = $this->seedIndices();

        $results = $search->multiSearch([self::INDEX_B], '"blue widget"');

        $ids = array_column($results['results'], 'id');
        $this->assertContains('product-1', $ids);
    }

    /**
     * A query the user typed that contains no searchable term must come back
     * empty instead of matching every document in every index.
     *
     * @dataProvider termlessQueries
     */
    public function test_termless_queries_return_no_results(string $query): void
    {
        $search = $this->seedIndices();

        $results = $search->multiSearch([self::INDEX_A, self::INDEX_B], $query);

        $this->assertSame(0, $results['total'], "Query {$query} should not match every document");
        $this->assertSame([], $results['results']);
    }

    public static function termlessQueries(): array
    {
        return [
            'colon' => [':'],
            'ellipsis' => ['...'],
            'asterisk' => ['*'],
            'parentheses' => ['()'],
            'mixed punctuation' => ['!?.,;'],
        ];
    }

    /**
     * An empty query is the caller saying "no text query" — that branch stays
     * a match-all across the given indices.
     */
    public function test_a_blank_query_remains_a_match_all(): void
    {
        $search = $this->seedIndices();

        $results = $search->multiSearch([self::INDEX_A, self::INDEX_B], '');

        $this->assertSame(3, $results['total']);
    }

    /**
     * Bare FTS5 keywords are quoted, so they match documents containing the
     * word instead of being parsed as operators.
     */
    public function test_bare_keywords_are_searched_as_terms(): void
    {
        $search = $this->seedIndices([
            'analyzer' => [
                'disable_stop_words' => true,
                'lowercase' => false,
            ],
        ]);

        $results = $search->multiSearch([self::INDEX_B], 'AND');

        $ids = array_column($results['results'], 'id');
        $this->assertContains('product-1', $ids);
    }

    /**
     * The query has a dedicated argument, so an options entry must not replace
     * the analyzed and escaped value sent to FTS5.
     */
    public function test_query_option_cannot_bypass_escaping(): void
    {
        $search = $this->seedIndices();

        $results = $search->multiSearch(
            [self::INDEX_B],
            'properly',
            ['query' => 'ABC-123']
        );

        $ids = array_column($results['results'], 'id');
        $this->assertContains('guide-1', $ids);
        $this->assertNotContains('product-1', $ids);
    }
}
