<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Geo\GeoBounds;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Models\SearchQuery;
use YetiSearch\Search\SearchEngine;
use YetiSearch\Tests\TestCase;
use YetiSearch\YetiSearch;

/**
 * The results a SearchEngine holds in memory must never outlive a write.
 */
class ResultCacheTest extends TestCase
{
    private const INDEX = 'result_cache_idx';

    private function open(string $dbPath, array $config = []): YetiSearch
    {
        return new YetiSearch(array_replace_recursive([
            'storage' => ['path' => $dbPath, 'external_content' => true],
            'search' => ['min_score' => 0.0, 'enable_fuzzy' => false],
        ], $config));
    }

    /**
     * The results returned, not the total: the storage's query cache holds
     * result rows while the total is counted again.
     */
    private function hits(YetiSearch $search, string $query): int
    {
        return count($search->search(self::INDEX, $query)['results']);
    }

    private function heldResults(YetiSearch $search): int
    {
        $cache = new \ReflectionProperty(SearchEngine::class, 'cache');
        if (PHP_VERSION_ID < 80100) {
            $cache->setAccessible(true);
        }

        return count($cache->getValue($search->getSearchEngine(self::INDEX)));
    }

    public function test_writes_through_the_facade_are_seen_by_the_next_search(): void
    {
        $search = $this->open(getTestDbPath(uniqid('result_cache_')), [
            'indexer' => ['chunk_size' => 200, 'chunk_overlap' => 0],
        ]);

        $long = 'Opening paragraph. ' . str_repeat('Lorem ipsum dolor sit amet. ', 40) . 'Closing ghostword sentence.';
        $search->update(self::INDEX, ['id' => '1', 'content' => ['title' => 'Long', 'content' => $long]]);
        $this->assertGreaterThan(0, $this->hits($search, 'ghostword'));

        // The reporter's sequence from #48, without clearCache()
        $search->deleteByIdPrefix(self::INDEX, '1#', false);
        $search->update(self::INDEX, ['id' => '1', 'content' => ['title' => 'Long', 'content' => 'Tiny body.']]);
        $this->assertSame(0, $this->hits($search, 'ghostword'));

        $search->index(self::INDEX, ['id' => '2', 'content' => ['title' => 'Ghostword returns']]);
        $this->assertSame(1, $this->hits($search, 'ghostword'));

        $search->delete(self::INDEX, '2');
        $this->assertSame(0, $this->hits($search, 'ghostword'));
    }

    public function test_writes_through_the_indexer_are_seen_by_the_next_search(): void
    {
        $search = $this->open(getTestDbPath(uniqid('result_cache_')));
        $indexer = $search->createIndex(self::INDEX);

        $indexer->insert(['id' => 'a', 'content' => ['title' => 'Alpha page']]);
        $this->assertSame(0, $this->hits($search, 'beta'));

        $indexer->insert(['id' => 'b', 'content' => ['title' => 'Beta page']]);
        $indexer->flush();
        $this->assertSame(1, $this->hits($search, 'beta'));
    }

    public function test_writes_from_another_connection_are_seen_by_the_next_search(): void
    {
        $dbPath = getTestDbPath(uniqid('result_cache_'));
        $reader = $this->open($dbPath);
        $writer = $this->open($dbPath);

        $writer->index(self::INDEX, ['id' => 'a', 'content' => ['title' => 'Alpha page']]);
        $this->assertSame(0, $this->hits($reader, 'beta'));

        $writer->index(self::INDEX, ['id' => 'b', 'content' => ['title' => 'Beta page']]);
        $this->assertSame(1, $this->hits($reader, 'beta'));
    }

    public function test_clear_forgets_cached_results_with_the_query_cache_on(): void
    {
        // No results held in memory, so only the storage's query cache is in play
        $search = $this->open(getTestDbPath(uniqid('result_cache_')), [
            'search' => ['cache_ttl' => 0],
            'cache' => ['enabled' => true, 'ttl' => 3600],
        ]);

        $search->index(self::INDEX, ['id' => 'a', 'content' => ['title' => 'Alpha page']]);
        $this->assertSame(1, $this->hits($search, 'alpha'));

        $search->clear(self::INDEX);
        $this->assertSame(0, $this->hits($search, 'alpha'));
    }

    public function test_clear_cache_empties_the_engine_results(): void
    {
        $search = $this->open(getTestDbPath(uniqid('result_cache_')));
        $search->index(self::INDEX, ['id' => 'a', 'content' => ['title' => 'Alpha page']]);

        $this->hits($search, 'alpha');
        $this->assertSame(1, $this->heldResults($search));

        $search->clearCache();
        $this->assertSame(0, $this->heldResults($search));
    }

    public function test_queries_at_different_places_are_cached_apart(): void
    {
        $search = $this->open(getTestDbPath(uniqid('result_cache_')));
        $search->indexBatch(self::INDEX, [
            ['id' => 'south', 'content' => ['title' => 'Coffee south'], 'geo' => ['lat' => 45.0, 'lng' => 10.0]],
            ['id' => 'north', 'content' => ['title' => 'Coffee north'], 'geo' => ['lat' => 45.3, 'lng' => 10.0]],
        ]);
        $engine = $search->getSearchEngine(self::INDEX);
        $ids = function (SearchQuery $query) use ($engine): array {
            return array_map(fn($result) => $result->getId(), $engine->search($query)->getResults());
        };

        // A point and a box used to encode as {} in the cache key, so the
        // second place got the first place's results.
        $this->assertSame(['south'], $ids((new SearchQuery('coffee'))->near(new GeoPoint(45.0, 10.0), 4000)));
        $this->assertSame(['north'], $ids((new SearchQuery('coffee'))->near(new GeoPoint(45.3, 10.0), 4000)));

        $this->assertSame(['south'], $ids((new SearchQuery('coffee'))->within(new GeoBounds(45.1, 44.9, 10.1, 9.9))));
        $this->assertSame(['north'], $ids((new SearchQuery('coffee'))->within(new GeoBounds(45.4, 45.2, 10.1, 9.9))));
    }

    public function test_bypass_cache_skips_the_engine_results(): void
    {
        $search = $this->open(getTestDbPath(uniqid('result_cache_')));
        $search->index(self::INDEX, ['id' => 'a', 'content' => ['title' => 'Alpha page']]);

        $search->search(self::INDEX, 'alpha', ['bypass_cache' => true]);
        $this->assertSame(0, $this->heldResults($search));

        $search->search(self::INDEX, 'alpha');
        $this->assertSame(1, $this->heldResults($search));
    }

    /** @return array<string, array{0: callable}> Options the cache key cannot be made from */
    public function unencodableOptions(): array
    {
        return [
            'an object that serializes to NAN' => [fn() => new class implements \JsonSerializable {
                #[\ReturnTypeWillChange]
                public function jsonSerialize()
                {
                    return NAN;
                }
            }],
            'infinity' => [fn() => INF],
            'a resource' => [fn() => fopen('php://memory', 'r')],
            'an object whose serializer throws' => [fn() => new class implements \JsonSerializable {
                #[\ReturnTypeWillChange]
                public function jsonSerialize()
                {
                    throw new \RuntimeException('cannot serialize');
                }
            }],
        ];
    }

    /** @dataProvider unencodableOptions */
    public function test_a_search_whose_options_cannot_be_encoded_is_not_cached(callable $makeOption): void
    {
        $search = $this->open(getTestDbPath(uniqid('result_cache_')));
        $search->indexBatch(self::INDEX, [
            ['id' => 'z', 'content' => ['title' => 'Zebra page']],
            ['id' => 'g', 'content' => ['title' => 'Giraffe page']],
        ]);
        $engine = $search->getSearchEngine(self::INDEX);
        $option = $makeOption();
        $ids = function (string $text) use ($engine, $option): array {
            return array_map(fn($result) => $result->getId(), $engine->search(new SearchQuery($text), ['marker' => $option])->getResults());
        };

        // They used to share the key of an empty string, so the second search got the first one's results
        $this->assertSame(['z'], $ids('zebra'));
        $this->assertSame(['g'], $ids('giraffe'));
        $this->assertSame(['z'], $ids('zebra'));
        $this->assertSame(0, $this->heldResults($search), 'Nothing is held for them');

        $config = new \ReflectionProperty(SearchEngine::class, 'config');
        if (PHP_VERSION_ID < 80100) {
            $config->setAccessible(true);
        }
        $this->assertArrayNotHasKey('marker', $config->getValue($engine), 'The options of the search are not left in the config');

        // The same engine still caches an ordinary search
        $this->assertSame(['g'], array_map(fn($result) => $result->getId(), $engine->search(new SearchQuery('giraffe'))->getResults()));
        $this->assertSame(1, $this->heldResults($search));
    }
}
