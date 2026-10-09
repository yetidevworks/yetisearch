<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Tests\TestCase;

class QueryCacheIntegrationTest extends TestCase
{
    public function test_top_level_cache_config_and_bypass_cache_option(): void
    {
        $dbPath = getTestDbPath(uniqid('cache_integration_'));
        $search = $this->createSearchInstance([
            'storage' => [
                'path' => $dbPath,
                'external_content' => false,
            ],
            'search' => [
                // Disable in-memory SearchEngine cache so storage cache behavior is observable.
                'cache_ttl' => 0,
            ],
            'cache' => [
                'enabled' => true,
                'ttl' => 3600,
                'max_size' => 100,
                'table_name' => '_query_cache',
            ],
        ]);

        $index = 'cache_idx';
        $this->createTestIndex($index);
        $search->indexBatch($index, [
            ['id' => 'd1', 'content' => ['title' => 'cache target one']],
            ['id' => 'd2', 'content' => ['title' => 'cache target two']],
        ]);
        $search->getIndexer($index)->flush();

        // First query populates cache.
        $search->search($index, 'cache target');

        $pdo = new \PDO('sqlite:' . $dbPath);
        $exists = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='_query_cache'")->fetchColumn();
        $this->assertSame(1, $exists);

        $row = $pdo->query("SELECT hit_count FROM _query_cache")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame(0, (int)$row['hit_count']);

        // bypass_cache=true should skip cache reads, so hit_count should not increase.
        $search->search($index, 'cache target', ['bypass_cache' => true]);
        $row = $pdo->query("SELECT hit_count FROM _query_cache")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame(0, (int)$row['hit_count']);

        // Normal query should hit storage query cache and increment hit_count.
        $search->search($index, 'cache target');
        $row = $pdo->query("SELECT hit_count FROM _query_cache")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame(1, (int)$row['hit_count']);
    }

    private function openWithQueryCache(string $dbPath): \YetiSearch\YetiSearch
    {
        $search = $this->createSearchInstance([
            'storage' => ['path' => $dbPath, 'external_content' => true],
            'search' => ['cache_ttl' => 0, 'min_score' => 0.0, 'enable_fuzzy' => false],
            'cache' => ['enabled' => true, 'ttl' => 3600, 'max_size' => 100, 'table_name' => '_query_cache'],
        ]);
        $search->createIndex('cache_idx');
        $search->indexBatch('cache_idx', [
            ['id' => 'z', 'content' => ['title' => 'Zebra page']],
            ['id' => 'g', 'content' => ['title' => 'Giraffe page']],
        ]);
        $search->getIndexer('cache_idx')->flush();

        return $search;
    }

    private function cachedRows(string $dbPath): int
    {
        $pdo = new \PDO('sqlite:' . $dbPath);

        return (int)$pdo->query('SELECT COUNT(*) FROM _query_cache')->fetchColumn();
    }

    public function test_a_storage_query_that_cannot_be_encoded_is_not_cached(): void
    {
        $dbPath = getTestDbPath(uniqid('cache_unencodable_'));
        $search = $this->openWithQueryCache($dbPath);
        $getStorage = new \ReflectionMethod($search, 'getStorage');
        if (PHP_VERSION_ID < 80100) {
            $getStorage->setAccessible(true);
        }
        $storage = $getStorage->invoke($search);
        $ids = function (string $text) use ($storage): array {
            return array_column($storage->search('cache_idx', [
                'query' => $text,
                'limit' => 10,
                'field_weights' => ['title' => NAN],
            ]), 'id');
        };

        // They used to share the key of an empty string, so the second query got the first one's rows
        $this->assertSame(['z'], $ids('zebra'));
        $this->assertSame(['g'], $ids('giraffe'));
        $this->assertSame(0, $this->cachedRows($dbPath), 'Nothing is written for a query that does not encode');

        // An ordinary query is still cached
        $this->assertSame(['g'], array_column($storage->search('cache_idx', ['query' => 'giraffe', 'limit' => 10]), 'id'));
        $this->assertSame(1, $this->cachedRows($dbPath));
    }

    public function test_a_search_the_engine_does_not_cache_bypasses_the_storage_cache(): void
    {
        $dbPath = getTestDbPath(uniqid('cache_uncacheable_'));
        $search = $this->openWithQueryCache($dbPath);
        $engine = $search->getSearchEngine('cache_idx');
        $ids = function (string $text) use ($engine): array {
            // An option the result-cache key cannot be made from
            return array_map(
                fn($result) => $result->getId(),
                $engine->search(new \YetiSearch\Models\SearchQuery($text), ['marker' => INF])->getResults()
            );
        };

        // An ordinary search leaves a row, which the same search with an option that cannot be
        // encoded does not read (its hit count stays) or add to
        $engine->search(new \YetiSearch\Models\SearchQuery('giraffe'));
        $this->assertSame(1, $this->cachedRows($dbPath));
        $this->assertSame(['g'], $ids('giraffe'));
        $this->assertSame(['z'], $ids('zebra'));
        $this->assertSame(1, $this->cachedRows($dbPath), 'The storage query cache is not written for a search that is not cached');
        $pdo = new \PDO('sqlite:' . $dbPath);
        $this->assertSame(0, (int)$pdo->query('SELECT SUM(hit_count) FROM _query_cache')->fetchColumn(), 'Nor read');

        $engine->search(new \YetiSearch\Models\SearchQuery('giraffe'));
        $this->assertSame(1, (int)$pdo->query('SELECT SUM(hit_count) FROM _query_cache')->fetchColumn(), 'An ordinary search still uses it');
    }

    public function test_the_stem_query_and_its_weight_are_part_of_the_key(): void
    {
        $dbPath = getTestDbPath(uniqid('cache_stems_'));
        $search = $this->createSearchInstance([
            'storage' => ['path' => $dbPath, 'external_content' => true],
            'search' => ['cache_ttl' => 0, 'min_score' => 0.0, 'enable_fuzzy' => false],
            'cache' => ['enabled' => true, 'ttl' => 3600, 'max_size' => 100, 'table_name' => '_query_cache'],
        ]);
        $search->createIndex('cache_stem_idx', ['stemming' => true]);
        $search->indexBatch('cache_stem_idx', [
            ['id' => 'c', 'content' => ['title' => 'Connect the dots']],
            ['id' => 'a', 'content' => ['title' => 'Connected quickly']],
        ]);
        $search->getIndexer('cache_stem_idx')->flush();
        $getStorage = new \ReflectionMethod($search, 'getStorage');
        if (PHP_VERSION_ID < 80100) {
            $getStorage->setAccessible(true);
        }
        $storage = $getStorage->invoke($search);
        $run = function (array $extra) use ($storage): array {
            $scores = [];
            foreach ($storage->search('cache_stem_idx', array_merge(['query' => 'connecting', 'limit' => 10], $extra)) as $row) {
                $scores[$row['id']] = $row['score'];
            }
            ksort($scores);

            return $scores;
        };

        // The same raw query, first as typed (no document has the word), then also by its
        // stem: the second must not get the first's rows
        $this->assertSame([], array_keys($run([])));
        $stemQuery = '(connecting) OR _stems : connect';
        $this->assertSame(['a', 'c'], array_keys($run(['stem_query' => $stemQuery, 'stem_weight' => 0.5])));

        // Another stem weight scores the stem-only match differently, so it is another entry
        $light = $run(['stem_query' => $stemQuery, 'stem_weight' => 0.5]);
        $heavy = $run(['stem_query' => $stemQuery, 'stem_weight' => 5.0]);
        $this->assertNotEquals($light['a'], $heavy['a']);
    }

    public function test_searches_with_weights_that_cannot_be_encoded_each_get_their_own_rows(): void
    {
        $dbPath = getTestDbPath(uniqid('cache_nan_weights_'));
        $search = $this->openWithQueryCache($dbPath);
        $engine = $search->getSearchEngine('cache_idx');
        $ids = function (string $text) use ($engine): array {
            return array_map(
                fn($result) => $result->getId(),
                $engine->search(new \YetiSearch\Models\SearchQuery($text), ['field_weights' => ['unused' => NAN]])->getResults()
            );
        };

        $this->assertSame(['z'], $ids('zebra'));
        $this->assertSame(['g'], $ids('giraffe'));
        $this->assertSame(0, $this->cachedRows($dbPath));
    }

    public function test_invalid_cache_table_name_is_rejected(): void
    {
        $this->expectException(\YetiSearch\Exceptions\CacheException::class);

        $search = $this->createSearchInstance([
            'storage' => [
                'path' => getTestDbPath(uniqid('cache_invalid_table_')),
                'external_content' => false,
            ],
            'cache' => [
                'enabled' => true,
                'table_name' => 'bad-table-name',
            ],
        ]);

        $search->createIndex('cache_idx_invalid_table');
    }
}
