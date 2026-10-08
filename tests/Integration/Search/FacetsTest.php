<?php

namespace YetiSearch\Tests\Integration\Search;

use Psr\Log\LoggerInterface;
use YetiSearch\Models\SearchQuery;
use YetiSearch\Tests\TestCase;
use YetiSearch\YetiSearch;

class FacetsTest extends TestCase
{
    private const INDEX = 'facets_idx';

    /** @var array<int, array{0: string, 1: string, 2: array}> */
    private array $logged = [];

    private function products(bool $externalContent = false, bool $withLogger = false): YetiSearch
    {
        if ($withLogger) {
            $logger = $this->createMock(LoggerInterface::class);
            foreach (['warning', 'notice'] as $level) {
                $logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level) {
                    $this->logged[] = [$level, (string)$message, $context];
                });
            }
            $this->search = new YetiSearch([
                'storage' => ['path' => $this->getTestDbPath(), 'external_content' => $externalContent],
                'search' => ['cache_enabled' => false, 'min_score' => 0.0],
            ], $logger);
        } else {
            $this->createSearchInstance(['storage' => ['external_content' => $externalContent]]);
        }
        $this->createTestIndex(self::INDEX);

        $this->search->indexBatch(self::INDEX, [
            ['id' => 'p1', 'content' => ['title' => 'Cheap headphones'],
             'metadata' => ['price' => 50, 'rating' => 4.5, 'tags' => ['audio', 'budget', 'audio'], 'sizes' => [8, 9, 42]]],
            ['id' => 'p2', 'content' => ['title' => 'Mid headphones'],
             'metadata' => ['price' => '150', 'rating' => 4.5, 'tags' => ['audio']]],
            ['id' => 'p3', 'content' => ['title' => 'Boundary headphones'],
             'metadata' => ['price' => 100.0, 'rating' => 3.9, 'tags' => ['audio', 'premium']]],
            ['id' => 'p4', 'content' => ['title' => 'Expensive headphones'],
             'metadata' => ['price' => 500, 'tags' => 'premium']],
        ]);

        return $this->search;
    }

    private function facets(SearchQuery $query): array
    {
        return $this->search->getSearchEngine(self::INDEX)->search($query)->getFacets();
    }

    public function schemaProvider(): array
    {
        return ['legacy schema' => [false], 'external content schema' => [true]];
    }

    /**
     * @dataProvider schemaProvider
     */
    public function test_range_facet_buckets_a_numeric_field(bool $externalContent): void
    {
        $this->products($externalContent);

        $facets = $this->facets((new SearchQuery('headphones'))->facet('price_range', [
            'field' => 'price',
            'ranges' => [
                ['to' => 100],
                ['from' => 100, 'to' => 200],
                ['from' => 200, 'key' => 'premium'],
                ['from' => 1000, 'key' => 'luxury'],
            ],
        ]));

        // 'from' is inclusive and 'to' exclusive, so 100.0 is in the middle
        // bucket; '150' counts as a number; empty buckets stay, in order.
        $this->assertSame([
            ['value' => '< 100', 'count' => 1, 'from' => null, 'to' => 100],
            ['value' => '100 - 200', 'count' => 2, 'from' => 100, 'to' => 200],
            ['value' => 'premium', 'count' => 1, 'from' => 200, 'to' => null],
            ['value' => 'luxury', 'count' => 0, 'from' => 1000, 'to' => null],
        ], $facets['price_range']);
    }

    public function test_range_facet_named_after_its_field_needs_no_field_option(): void
    {
        $this->products();

        $facets = $this->facets((new SearchQuery('headphones'))->facet('price', [
            'type' => 'range',
            'ranges' => [['to' => 99.5], ['from' => 99.5]],
        ]));

        $this->assertSame(['< 99.5', '>= 99.5'], array_column($facets['price'], 'value'));
        $this->assertSame([1, 3], array_column($facets['price'], 'count'));
    }

    public function test_range_facet_counts_a_list_of_numbers_once_per_bucket(): void
    {
        $this->products();

        $facets = $this->facets((new SearchQuery('headphones'))->facet('sizes', [
            'ranges' => [['to' => 10], ['from' => 10]],
        ]));

        $this->assertSame([1, 1], array_column($facets['sizes'], 'count'));
    }

    public function test_unusable_ranges_are_left_out_and_logged(): void
    {
        $this->products(false, true);

        $facets = $this->facets((new SearchQuery('headphones'))
            ->facet('price', ['ranges' => [['from' => 'cheap'], ['key' => 'no bounds'], 'oops', ['to' => 300]]])
            ->facet('rating', ['type' => 'range']));

        $this->assertSame([['value' => '< 300', 'count' => 3, 'from' => null, 'to' => 300]], $facets['price']);
        $this->assertSame([], $facets['rating']);

        $warnings = array_filter($this->logged, fn($entry) => $entry[0] === 'warning');
        $this->assertCount(4, $warnings);
        $this->assertSame(['price', 'price', 'price', 'rating'], array_values(array_map(
            fn($entry) => $entry[2]['facet'],
            $warnings
        )));
    }

    public function test_facet_on_a_field_no_document_has_logs_a_notice(): void
    {
        $this->products(false, true);

        // The facet is named price_range but no document has that field: the
        // buckets are empty, and the log says which field it looked at.
        $facets = $this->facets((new SearchQuery('headphones'))->facet('price_range', [
            'ranges' => [['to' => 100], ['from' => 100]],
        ]));

        $this->assertSame([0, 0], array_column($facets['price_range'], 'count'));
        $this->assertSame(
            [['notice', 'Facet field has no value in any matching document', ['facet' => 'price_range', 'field' => 'price_range']]],
            $this->logged
        );
    }

    public function test_value_facet_keeps_float_values(): void
    {
        $this->products();

        $facets = $this->facets((new SearchQuery('headphones'))->facet('rating'));

        $this->assertSame([
            ['value' => 4.5, 'count' => 2],
            ['value' => 3.9, 'count' => 1],
        ], $facets['rating']);
    }

    public function test_value_facet_counts_each_list_value_once_per_document(): void
    {
        $this->products();

        $facets = $this->facets((new SearchQuery('headphones'))->facet('tags'));

        $counts = array_column($facets['tags'], 'count', 'value');
        $this->assertSame(['audio' => 3, 'premium' => 2, 'budget' => 1], $counts);
    }

    public function test_search_passes_facets_through(): void
    {
        $search = $this->products();

        $results = $search->search(self::INDEX, 'headphones', [
            'facets' => [
                'tags' => ['limit' => 1],
                'price' => [
                    'type' => 'range',
                    'ranges' => [
                        ['to' => 500, 'key' => 'budget'],
                        ['from' => 500, 'key' => 'premium'],
                    ],
                ],
            ],
        ]);

        $this->assertSame([['value' => 'audio', 'count' => 3]], $results['facets']['tags']);
        $this->assertSame([3, 1], array_column($results['facets']['price'], 'count'));

        // A plain list of fields asks for value facets with their defaults
        $results = $search->search(self::INDEX, 'headphones', ['facets' => ['rating']]);
        $this->assertSame([4.5, 3.9], array_column($results['facets']['rating'], 'value'));
    }
}
