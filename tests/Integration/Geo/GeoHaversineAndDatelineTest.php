<?php

namespace YetiSearch\Tests\Integration\Geo;

use YetiSearch\Tests\TestCase;
use YetiSearch\YetiSearch;
use YetiSearch\Geo\GeoPoint;

class GeoHaversineAndDatelineTest extends TestCase
{
    protected ?YetiSearch $search = null;
    private string $index = 'geo_hd_idx';
    private bool $hasGeoSupport = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->search = new YetiSearch([
            'analyzer' => [
                'remove_stop_words' => false,
                'disable_stop_words' => true,
            ],
        ]);
        $this->search->createIndex($this->index);

        // Seed a couple of well-known points
        $docs = [
            ['id' => 'nyc', 'content' => ['title' => 'New York City'], 'geo' => ['lat' => 40.7128, 'lng' => -74.0060]],
            ['id' => 'la',  'content' => ['title' => 'Los Angeles'],    'geo' => ['lat' => 34.0522, 'lng' => -118.2437]],
            ['id' => 'fiji',  'content' => ['title' => 'Fiji'],        'geo' => ['lat' => 17.7134, 'lng' => 178.0650]],
            ['id' => 'samoa', 'content' => ['title' => 'Samoa'],       'geo' => ['lat' => 13.7590, 'lng' => -172.1046]],
        ];
        $this->search->indexBatch($this->index, $docs);

        // Robust geo support probe: requires distance to be computed
        try {
            $engine = $this->search->getSearchEngine($this->index);
            $q = new \YetiSearch\Models\SearchQuery('');
            $q->near(new GeoPoint(40.7128, -74.0060), 1000)->limit(5);
            $r = $engine->search($q);
            $this->hasGeoSupport = count($r->getResults()) > 0 && $r->getResults()[0]->hasDistance();
        } catch (\Throwable $e) {
            $this->hasGeoSupport = false;
        }
    }

    private function requiresGeo(): void
    {
        if (!$this->hasGeoSupport) {
            $this->markTestSkipped('Geo (R-tree) not available in this environment.');
        }
    }

    public function test_haversine_distance_between_major_cities(): void
    {
        $this->requiresGeo();
        // Near NYC with large radius to include LA
        $engine = $this->search->getSearchEngine($this->index);
        $q = new \YetiSearch\Models\SearchQuery('');
        $q->near(new GeoPoint(40.7128, -74.0060), 5_000_000) // 5000 km
          ->sortByDistance(new GeoPoint(40.7128, -74.0060), 'asc');
        $results = $engine->search($q);

        $laDist = null;
        foreach ($results->getResults() as $r) {
            if ($r->getId() === 'la') {
                $laDist = $r->getDistance();
                break;
            }
        }

        $this->assertNotNull($laDist, 'Expected to find Los Angeles in radius of 5000 km from NYC');

        // Known NYC-LA distance ~ 3936 km (3,936,000 m). Allow 50 km tolerance.
        $expected = 3_936_000;
        $this->assertEqualsWithDelta($expected, $laDist, 50_000, 'Haversine distance should be close to known value');
    }

    public function test_dateline_crossing_bounds(): void
    {
        $this->requiresGeo();
        // Bounds crossing the antimeridian: lat 10..20, lon 170..-170
        $engine = $this->search->getSearchEngine($this->index);
        $q = new \YetiSearch\Models\SearchQuery('');
        $q->withinBounds(20.0, 10.0, -170.0, 170.0);
        $results = $engine->search($q);
        $ids = array_map(fn($r) => $r->getId(), $results->getResults());

        $this->assertContains('fiji', $ids, 'Bounds crossing antimeridian should include Fiji');
        $this->assertContains('samoa', $ids, 'Bounds crossing antimeridian should include Samoa');
    }
}
