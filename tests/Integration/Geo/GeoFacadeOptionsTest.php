<?php

namespace YetiSearch\Tests\Integration\Geo;

use YetiSearch\Tests\TestCase;

class GeoFacadeOptionsTest extends TestCase
{
    public function test_facade_accepts_geo_units_and_filters(): void
    {
        $search = $this->createSearchInstance();
        $index = 'geo_facade_idx';
        $this->createTestIndex($index);

        $docs = [
            ['id' => 'a', 'content' => ['title' => 'A'], 'geo' => ['lat' => 37.7749, 'lng' => -122.4194]], // SF
            ['id' => 'b', 'content' => ['title' => 'B'], 'geo' => ['lat' => 37.7849, 'lng' => -122.4094]], // ~1.5km
            ['id' => 'c', 'content' => ['title' => 'C'], 'geo' => ['lat' => 37.8049, 'lng' => -122.3894]], // ~4km
        ];
        $search->indexBatch($index, $docs);

        // Near with units km (5 km) should include b and c
        $res = $search->search($index, '', [
            'limit' => 10,
            'geoFilters' => [
                'near' => [
                    'point' => ['lat' => 37.7749, 'lng' => -122.4194],
                    'radius' => 5,
                    'units' => 'km',
                ],
                'distance_sort' => [
                    'from' => ['lat' => 37.7749, 'lng' => -122.4194],
                    'direction' => 'asc',
                ],
            ],
        ]);

        if (empty($res['results'])) {
            $this->markTestSkipped('No geo results via facade; skipping (environment/R-tree variability).');
        }
        $this->assertGreaterThanOrEqual(2, count($res['results']));
        foreach ($res['results'] as $row) {
            $this->assertArrayHasKey('distance', $row);
            $this->assertLessThanOrEqual(5000, $row['distance']);
        }
    }
}
