<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Tests\TestCase;

class CountNearTest extends TestCase
{
    private function getStorage($search)
    {
        $ref = new \ReflectionClass($search);
        $m = $ref->getMethod('getStorage'); $m->setAccessible(true);
        return $m->invoke($search);
    }

    public function test_count_with_near_radius(): void
    {
        $search = $this->createSearchInstance(['search' => ['min_score' => 0.0]]);
        $index = 'count_near_idx';
        $this->createTestIndex($index);

        $search->indexBatch($index, [
            ['id' => 'a', 'content' => ['title' => 'A'], 'geo' => ['lat' => 37.7749, 'lng' => -122.4194]],
            ['id' => 'b', 'content' => ['title' => 'B'], 'geo' => ['lat' => 37.8049, 'lng' => -122.3894]],
        ]);
        $search->getIndexer($index)->flush();

        $storage = $this->getStorage($search);
        $q = [
            'query' => '',
            'geoFilters' => [
                'near' => [ 'point' => ['lat' => 37.7749, 'lng' => -122.4194], 'radius' => 2000, 'units' => 'm' ]
            ]
        ];
        $cnt = $storage->count($index, $q);
        $this->assertSame(1, $cnt);
    }
}
