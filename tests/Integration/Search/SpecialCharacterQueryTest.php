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

    public function test_engine_count_accepts_hyphenated_queries(): void
    {
        $search = $this->seedIndex();

        $engine = $search->getSearchEngine(self::INDEX);
        $count = $engine->count(new \YetiSearch\Models\SearchQuery('BENCH-100821'));

        $this->assertGreaterThan(0, $count);
    }
}
