<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Geo\GeoPoint;
use YetiSearch\Models\SearchQuery;
use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Tests\TestCase;

/**
 * migrateToExternalContent() replaces the legacy table rather than keeping it, gives the new one
 * the legacy table's indexes, keeps the locations, and does nothing to an index it migrated.
 */
class MigrateToExternalContentTest extends TestCase
{
    private const INDEX = 'migrate_idx';

    private SqliteStorage $storage;
    private \PDO $pdo;

    private function legacyIndex(array $options = []): void
    {
        $this->createSearchInstance(['storage' => ['external_content' => false], 'search' => ['min_score' => 0.0, 'cache_ttl' => 0]]);
        $this->search->createIndex(self::INDEX, $options);
        $this->search->indexBatch(self::INDEX, [
            ['id' => 'paris', 'content' => ['title' => 'Cafe in Paris'], 'geo' => ['lat' => 48.8566, 'lng' => 2.3522]],
            ['id' => 'lyon', 'content' => ['title' => 'Cafe in Lyon'], 'geo' => ['lat' => 45.7640, 'lng' => 4.8357]],
        ]);

        $getStorage = new \ReflectionMethod($this->search, 'getStorage');
        if (PHP_VERSION_ID < 80100) {
            $getStorage->setAccessible(true);
        }
        $this->storage = $getStorage->invoke($this->search);
        $connection = new \ReflectionProperty($this->storage, 'connection');
        if (PHP_VERSION_ID < 80100) {
            $connection->setAccessible(true);
        }
        $this->pdo = $connection->getValue($this->storage);
    }

    private function tables(): array
    {
        return $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function nearParis(): array
    {
        $query = (new SearchQuery(''))->near(new GeoPoint(48.8566, 2.3522), 50000)->limit(10);

        return array_map(static function ($result) {
            return $result->getId();
        }, $this->search->getSearchEngine(self::INDEX)->search($query)->getResults());
    }

    public function test_the_legacy_table_is_replaced_and_its_indexes_are_kept(): void
    {
        $this->legacyIndex();

        $this->storage->migrateToExternalContent(self::INDEX);

        $this->assertNotContains(self::INDEX . '_old', $this->tables());
        $indexes = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = '" . self::INDEX . "'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['language', 'type', 'timestamp'] as $column) {
            $this->assertContains('idx_' . self::INDEX . '_' . $column, $indexes);
        }
        $this->assertSame(2, $this->search->search(self::INDEX, 'cafe')['total']);
    }

    public function test_the_locations_are_kept(): void
    {
        $this->legacyIndex();
        $rtree = new \ReflectionMethod($this->storage, 'hasRTreeSupport');
        if (PHP_VERSION_ID < 80100) {
            $rtree->setAccessible(true);
        }
        if (!$rtree->invoke($this->storage)) {
            $this->markTestSkipped('Needs R-tree support');
        }
        $this->assertSame(['paris'], $this->nearParis());

        $this->storage->migrateToExternalContent(self::INDEX);

        $this->assertSame(['paris'], $this->nearParis());
        $this->assertNotContains(self::INDEX . '_id_map', $this->tables());
    }

    public function test_a_migrated_index_is_left_as_it_is(): void
    {
        $this->legacyIndex();
        $this->storage->migrateToExternalContent(self::INDEX);
        $docIds = $this->pdo->query('SELECT id, doc_id FROM ' . self::INDEX . ' ORDER BY id')->fetchAll(\PDO::FETCH_KEY_PAIR);

        $this->storage->migrateToExternalContent(self::INDEX);

        $this->assertSame($docIds, $this->pdo->query('SELECT id, doc_id FROM ' . self::INDEX . ' ORDER BY id')->fetchAll(\PDO::FETCH_KEY_PAIR));
        $this->assertSame(2, $this->search->search(self::INDEX, 'cafe')['total']);
    }

    public function test_an_index_made_without_locations_gets_no_spatial_table(): void
    {
        $this->legacyIndex(['enable_spatial' => false]);

        $this->storage->migrateToExternalContent(self::INDEX);

        $this->assertNotContains(self::INDEX . '_spatial', $this->tables());
        $this->assertSame(2, $this->search->search(self::INDEX, 'cafe')['total']);
    }
}
