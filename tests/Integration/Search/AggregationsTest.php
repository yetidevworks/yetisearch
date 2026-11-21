<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Models\SearchQuery;
use YetiSearch\Tests\TestCase;

/**
 * Aggregations (SearchQuery::aggregate(), the `aggregations` search option) are computed over
 * every document a search matches, not over its page.
 */
class AggregationsTest extends TestCase
{
    private const INDEX = 'agg_idx';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSearchInstance(['search' => ['min_score' => 0.0, 'cache_ttl' => 0]]);
        $this->search->createIndex(self::INDEX, ['fields' => [
            'title' => ['boost' => 1.0, 'store' => true],
            'price' => ['store' => true, 'index' => false],
            'sizes' => ['store' => true, 'index' => false],
        ]]);
        $this->createdIndexes[] = self::INDEX;
        $this->search->indexBatch(self::INDEX, [
            ['id' => 'a', 'content' => ['title' => 'Red shoe', 'price' => 10, 'sizes' => [40, 41]], 'metadata' => ['stock' => 5]],
            ['id' => 'b', 'content' => ['title' => 'Blue shoe', 'price' => 25.5, 'sizes' => [42]], 'metadata' => ['stock' => 0]],
            ['id' => 'c', 'content' => ['title' => 'Green shoe', 'price' => '4.5'], 'metadata' => ['stock' => 12]],
            ['id' => 'd', 'content' => ['title' => 'Old shoe', 'price' => 'unknown']],
            ['id' => 'e', 'content' => ['title' => 'Red hat', 'price' => 99]],
        ]);
    }

    private function aggregate(string $query, array $aggregations, array $options = []): array
    {
        return $this->search->search(self::INDEX, $query, $options + ['aggregations' => $aggregations, 'fuzzy' => false])['aggregations'];
    }

    public function test_each_type_over_every_match(): void
    {
        $aggregations = $this->aggregate('shoe', [
            'priced' => ['type' => 'count', 'field' => 'price'],
            'total' => ['type' => 'sum', 'field' => 'price'],
            'average' => ['type' => 'avg', 'field' => 'price'],
            'cheapest' => ['type' => 'min', 'field' => 'price'],
            'dearest' => ['type' => 'max', 'field' => 'price'],
        ], ['limit' => 1]);

        // Four shoes, three of them with a price that is a number; 'unknown' is not one
        $this->assertSame(['value' => 3], $aggregations['priced']);
        $this->assertSame(['value' => 40.0], $aggregations['total']);
        $this->assertEqualsWithDelta(40 / 3, $aggregations['average']['value'], 1e-9);
        $this->assertSame(['value' => 4.5], $aggregations['cheapest']);
        $this->assertSame(['value' => 25.5], $aggregations['dearest']);
    }

    public function test_stats_lists_and_metadata(): void
    {
        $aggregations = $this->aggregate('shoe', [
            'sizes' => ['type' => 'stats', 'field' => 'sizes'],
            'stock' => ['type' => 'sum', 'field' => 'stock'],
            'stock_only' => ['type' => 'max', 'field' => 'metadata.stock'],
        ]);

        $this->assertSame(['count' => 3, 'sum' => 123, 'avg' => 41.0, 'min' => 40, 'max' => 42], $aggregations['sizes']);
        $this->assertSame(['value' => 17], $aggregations['stock']);
        $this->assertSame(['value' => 12], $aggregations['stock_only']);
    }

    public function test_filters_count_and_no_value_gives_null(): void
    {
        $red = $this->aggregate('red', ['total' => ['type' => 'sum', 'field' => 'price']]);
        $this->assertSame(['value' => 109], $red['total']);

        $filtered = $this->aggregate('shoe', ['total' => ['type' => 'sum', 'field' => 'price']], [
            'filters' => [['field' => 'id', 'operator' => 'in', 'value' => ['a', 'b']]],
        ]);
        $this->assertSame(['value' => 35.5], $filtered['total']);

        $none = $this->aggregate('shoe', [
            'average' => ['type' => 'avg', 'field' => 'weight'],
            'total' => ['type' => 'sum', 'field' => 'weight'],
        ]);
        $this->assertSame(['average' => ['value' => null], 'total' => ['value' => 0]], $none);
    }

    public function test_an_aggregation_it_cannot_compute_is_left_out(): void
    {
        $aggregations = $this->aggregate('shoe', [
            'median' => ['type' => 'median', 'field' => 'price'],
            'nofield' => ['type' => 'sum'],
            'badfield' => ['type' => 'sum', 'field' => 'unit-price'],
            'total' => ['type' => 'sum', 'field' => 'price'],
        ]);

        $this->assertSame(['total' => ['value' => 40.0]], $aggregations);
    }

    public function test_the_search_engine_takes_them_from_the_query(): void
    {
        $query = (new SearchQuery('shoe'))->limit(1)->aggregate('dearest', 'max', ['field' => 'price']);

        $results = $this->search->getSearchEngine(self::INDEX)->search($query);

        $this->assertSame(['value' => 25.5], $results->getAggregation('dearest'));
    }
}
