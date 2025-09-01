<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Tests\TestCase;
use YetiSearch\Models\SearchQuery;
use YetiSearch\Geo\GeoPoint;

class DistancePhpSortTest extends TestCase
{
    public function test_php_sort_for_fts_with_distance_sort(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Geo PHP distance-sort test is skipped on Windows CI.');
        }
        $search = $this->createSearchInstance(['search' => ['min_score' => 0.0]]);
        $index = 'php_sort_idx';
        $this->createTestIndex($index);

        // Two docs with same text so BM25 comparable; distance should sort
        $search->indexBatch($index, [
            ['id' => 'near', 'content' => ['title' => 'Hello World'], 'geo' => ['lat' => 37.7749, 'lng' => -122.4194]],
            ['id' => 'far',  'content' => ['title' => 'Hello World'], 'geo' => ['lat' => 37.8049, 'lng' => -122.3894]],
        ]);
        $search->getIndexer($index)->flush();

        $engine = $search->getSearchEngine($index);
        $q = new SearchQuery('hello');
        $from = new GeoPoint(37.7749, -122.4194);
        $q->sortByDistance($from, 'asc')->near($from, 5000)->limit(10);
        $res = $engine->search($q);
        $ids = array_map(fn($r) => $r->getId(), $res->getResults());
        $this->assertSame('near', $ids[0] ?? null);
    }
}
