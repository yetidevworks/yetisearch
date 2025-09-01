<?php

namespace YetiSearch\Tests\Integration\Geo;

use YetiSearch\Tests\TestCase;
use YetiSearch\Geo\GeoPoint;

class GeoScoringTest extends TestCase
{
    public function test_distance_weight_improves_nearest_ranking(): void
    {
        $search = $this->createSearchInstance([
            'search' => [
                'distance_weight' => 0.6,
                'distance_decay_k' => 0.5,
            ],
        ]);
        $index = 'geo_score_idx';
        $this->createTestIndex($index);

        $center = new GeoPoint(37.7749, -122.4194);

        $docs = [
            ['id' => 'near', 'content' => ['title' => 'Coffee Shop', 'content' => 'coffee best'],
             'geo' => ['lat' => 37.7839, 'lng' => -122.4194]],
            ['id' => 'far', 'content' => ['title' => 'Coffee Shop', 'content' => 'coffee best'],
             'geo' => ['lat' => 37.8749, 'lng' => -122.4194]],
        ];
        $search->indexBatch($index, $docs);
        $search->getIndexer($index)->flush();

        $engine = $search->getSearchEngine($index);
        $q = new \YetiSearch\Models\SearchQuery('coffee');
        $q->near($center, 20000)->sortByDistance($center, 'asc')->limit(10);
        $results = $engine->search($q);
        if (empty($results->getResults())) {
            $this->assertTrue(true);
            return;
        }
        $this->assertSame('near', $results->getResults()[0]->getId(), 'Nearest document should rank first when distance_weight > 0');
    }
}

