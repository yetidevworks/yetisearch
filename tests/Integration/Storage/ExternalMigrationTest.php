<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Tests\TestCase;

class ExternalMigrationTest extends TestCase
{
    private function getPdo($search): \PDO
    {
        $ref = new \ReflectionClass($search);
        $m = $ref->getMethod('getStorage'); $m->setAccessible(true);
        $storage = $m->invoke($search);
        $sref = new \ReflectionClass($storage);
        $p = $sref->getProperty('connection'); $p->setAccessible(true);
        return $p->getValue($storage);
    }

    private function getStorage($search)
    {
        $ref = new \ReflectionClass($search);
        $m = $ref->getMethod('getStorage'); $m->setAccessible(true);
        return $m->invoke($search);
    }

    private function getCreateSql(\PDO $pdo, string $table): ?string
    {
        $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row['sql'] ?? null;
    }

    public function test_migrate_legacy_to_external_with_geo(): void
    {
        // Start in legacy schema (TestCase defaults)
        $search = $this->createSearchInstance([
            'storage' => ['external_content' => false],
            'search' => ['min_score' => 0.0],
        ]);
        $index = 'migrate_ext_idx';
        $this->createTestIndex($index);

        // Seed docs with geo
        $docs = [
            ['id' => 'a', 'content' => ['title' => 'Alpha'], 'geo' => ['lat' => 37.7749, 'lng' => -122.4194]],
            ['id' => 'b', 'content' => ['title' => 'Beta'],  'geo' => ['lat' => 37.7849, 'lng' => -122.4094]],
        ];
        $search->indexBatch($index, $docs);
        $search->getIndexer($index)->flush();

        // Migrate storage schema in-place
        $storage = $this->getStorage($search);
        $storage->migrateToExternalContent($index);

        // Validate new schema
        $pdo = $this->getPdo($search);
        $mainSql = $this->getCreateSql($pdo, $index);
        $ftsSql  = $this->getCreateSql($pdo, $index . '_fts');
        $this->assertNotEmpty($mainSql);
        $this->assertStringContainsString('doc_id INTEGER PRIMARY KEY', $mainSql);
        $this->assertStringContainsString('id TEXT UNIQUE', $mainSql);
        $this->assertNotEmpty($ftsSql);
        $this->assertStringContainsString("content='" . $index . "'", str_replace('"', "'", $ftsSql));
        $this->assertStringContainsString("content_rowid='doc_id'", str_replace('"', "'", $ftsSql));

        // Spatial is recreated empty; re-upsert docs to populate spatial index
        $search->indexBatch($index, $docs);
        $search->getIndexer($index)->flush();
        $countSpatial = (int)$pdo->query("SELECT COUNT(*) FROM {$index}_spatial")->fetchColumn();
        $this->assertGreaterThanOrEqual(2, $countSpatial);

        // Verify geo search now returns distance
        $engine = $search->getSearchEngine($index);
        $q = new \YetiSearch\Models\SearchQuery('');
        $q->near(new \YetiSearch\Geo\GeoPoint(37.7749, -122.4194), 5000)->limit(10);
        $r = $engine->search($q);
        $this->assertGreaterThanOrEqual(1, count($r->getResults()));
        $this->assertTrue($r->getResults()[0]->hasDistance());

        // Exercise rebuildFts/optimize for coverage
        $storage->rebuildFts($index);
        $storage->optimize($index);
        $this->assertTrue(true); // no exception means OK
    }
}
