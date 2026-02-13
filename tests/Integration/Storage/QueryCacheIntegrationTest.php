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
}
