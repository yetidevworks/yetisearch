<?php

namespace YetiSearch\Tests\Integration;

use YetiSearch\Tests\TestCase;
use YetiSearch\Geo\GeoPoint;

class ExternalContentSchemaTest extends TestCase
{
    private function getPdo($search): \PDO
    {
        $ref = new \ReflectionClass($search);
        $m = $ref->getMethod('getStorage');
        $m->setAccessible(true);
        $storage = $m->invoke($search);
        $sref = new \ReflectionClass($storage);
        $p = $sref->getProperty('connection');
        $p->setAccessible(true);
        return $p->getValue($storage);
    }

    private function getCreateSql(\PDO $pdo, string $table): ?string
    {
        $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row['sql'] ?? null;
    }

    public function test_schema_uses_doc_id_and_content_rowid(): void
    {
        $search = $this->createSearchInstance([
            'storage' => ['external_content' => true],
        ]);
        $index = 'ext_schema_idx';
        $this->createTestIndex($index);

        $pdo = $this->getPdo($search);
        $mainSql = $this->getCreateSql($pdo, $index);
        $ftsSql = $this->getCreateSql($pdo, $index . '_fts');

        $this->assertNotEmpty($mainSql, 'Main table not created');
        $this->assertStringContainsString('doc_id INTEGER PRIMARY KEY', $mainSql);
        $this->assertStringContainsString('id TEXT UNIQUE', $mainSql);

        $this->assertNotEmpty($ftsSql, 'FTS table not created');
        $this->assertStringContainsString("USING fts5", $ftsSql);
        // External-content FTS should reference content table + rowid
        $this->assertStringContainsString("content='" . $index . "'", str_replace('"', "'", $ftsSql));
        $this->assertStringContainsString("content_rowid='doc_id'", str_replace('"', "'", $ftsSql));
    }

    public function test_basic_search_in_external_mode(): void
    {
        $search = $this->createSearchInstance([
            'storage' => ['external_content' => true],
            'indexer' => [
                'fields' => [
                    'title' => ['boost' => 3.0, 'store' => true],
                    'content' => ['boost' => 1.0, 'store' => true],
                ],
                'fts' => [ 'multi_column' => true ],
            ],
        ]);
        $index = 'ext_basic_idx';
        $this->createTestIndex($index);

        $docs = [
            ['id' => 'd1', 'content' => ['title' => 'Hello World', 'content' => 'First doc']],
            ['id' => 'd2', 'content' => ['title' => 'Another', 'content' => 'Hello again']],
        ];
        $search->indexBatch($index, $docs);
        $search->getIndexer($index)->flush();

        $res = $search->search($index, 'hello', ['limit' => 10]);
        $this->assertGreaterThanOrEqual(2, $res['total']);
        $ids = array_column($res['results'], 'id');
        $this->assertContains('d1', $ids);
        $this->assertContains('d2', $ids);
    }

    public function test_geo_near_returns_distance_in_external_mode(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Geo distance tests are skipped on Windows CI.');
        }
        $search = $this->createSearchInstance([
            'storage' => ['external_content' => true],
            'search' => ['min_score' => 0.0],
        ]);
        $index = 'ext_geo_idx';
        $this->createTestIndex($index);

        $docs = [
            ['id' => 'a', 'content' => ['title' => 'A'], 'geo' => ['lat' => 37.7749, 'lng' => -122.4194]], // SF
            ['id' => 'b', 'content' => ['title' => 'B'], 'geo' => ['lat' => 37.7849, 'lng' => -122.4094]], // ~1.5km
            ['id' => 'c', 'content' => ['title' => 'C'], 'geo' => ['lat' => 37.8049, 'lng' => -122.3894]], // ~4km
        ];
        $search->indexBatch($index, $docs);
        $search->getIndexer($index)->flush();

        $engine = $search->getSearchEngine($index);
        $q = new \YetiSearch\Models\SearchQuery('');
        $q->near(new GeoPoint(37.7749, -122.4194), 5000)
          ->sortByDistance(new GeoPoint(37.7749, -122.4194), 'asc')
          ->limit(10);
        $r = $engine->search($q);
        $this->assertGreaterThanOrEqual(2, count($r->getResults()));
        foreach ($r->getResults() as $row) {
            $this->assertTrue($row->hasDistance());
            $this->assertLessThanOrEqual(5000, $row->getDistance());
        }
    }
}
