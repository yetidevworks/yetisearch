<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Tests\TestCase;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Models\SearchQuery;

class SearchEngineScoringAndDedupTest extends TestCase
{
    public function test_distance_weight_influences_order_and_unique_by_route(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Geo distance weighting tests are skipped on Windows CI.');
        }
        $search = $this->createSearchInstance([
            'search' => [
                'distance_weight' => 0.7,
                'distance_decay_k' => 0.01,
                'min_score' => 0.0,
            ],
        ]);
        $index = 'engine_geo_idx';
        $this->createTestIndex($index);

        $docs = [
            ['id' => 'r1#1', 'content' => ['title' => 'Alpha near', 'route' => '/a'], 'geo' => ['lat' => 37.7749, 'lng' => -122.4194]],
            ['id' => 'r1#2', 'content' => ['title' => 'Alpha far',  'route' => '/a'], 'geo' => ['lat' => 37.8049, 'lng' => -122.3894]],
            ['id' => 'r2#1', 'content' => ['title' => 'Beta mid',  'route' => '/b'], 'geo' => ['lat' => 37.7849, 'lng' => -122.4094]],
        ];
        $search->indexBatch($index, $docs);
        $search->getIndexer($index)->flush();

        $engine = $search->getSearchEngine($index);
        $q = new SearchQuery('alpha');
        $q->near(new GeoPoint(37.7749, -122.4194), 5000)
          ->sortByDistance(new GeoPoint(37.7749, -122.4194), 'asc')
          ->limit(10);
        $results = $engine->search($q);

        // With distance weight, "Alpha near" should score higher than "Alpha far"
        $ids = array_map(fn($r) => $r->getId(), $results->getResults());
        $this->assertLessThan(array_search('r1#2', $ids), array_search('r1#1', $ids));

        // Test unique_by_route dedup keeps best per route
        $arr = $search->search($index, 'alpha', ['unique_by_route' => true, 'limit' => 10]);
        $ids2 = array_column($arr['results'], 'id');
        $this->assertContains('r1#1', $ids2); // keep nearer alpha
        $this->assertNotContains('r1#2', $ids2);
    }
}
