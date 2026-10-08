<?php

namespace YetiSearch\Tests\Integration\Geo;

use YetiSearch\Geo\GeoBounds;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Models\SearchQuery;
use YetiSearch\Tests\TestCase;
use YetiSearch\YetiSearch;

/**
 * Geo queries must give the same answers with the R-tree module, with a plain
 * spatial table in its place, and (for near() results and within()) when SQL has
 * no math functions at all, in both storage schemas.
 */
class GeoFallbackTest extends TestCase
{
    private const CENTER_LAT = 45.0;
    private const CENTER_LNG = 10.0;
    private const RADIUS = 10000.0;
    private const INDEX = 'geo_fallback_idx';

    /** Ids of the fixtures inside the 10 km circle around the center */
    private const INSIDE = ['p_center', 'p_north_5km', 'p_east_9km', 'region_bounds'];

    public function modes(): array
    {
        return [
            'external, rtree'    => [true, 'rtree'],
            'external, no rtree' => [true, 'plain'],
            'legacy, rtree'      => [false, 'rtree'],
            'legacy, no rtree'   => [false, 'plain'],
        ];
    }

    public function modesWithoutSqlMath(): array
    {
        return $this->modes() + [
            'external, no sql math' => [true, 'nomath'],
            'legacy, no sql math'   => [false, 'nomath'],
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 2 * 6371000.0 * asin(min(1.0, sqrt($a)));
    }

    /** Places around (45, 10). The corner documents sit in the corners of a 10 km box, 12 to 14 km away, and rank first on text. */
    private function placeDocs(): array
    {
        $dLat = self::RADIUS / 111000.0;
        $dLng = self::RADIUS / (111000.0 * cos(deg2rad(self::CENTER_LAT)));
        $points = [
            'p_center'     => [self::CENTER_LAT, self::CENTER_LNG],
            'p_north_5km'  => [self::CENTER_LAT + 5000 / 111000.0, self::CENTER_LNG],
            'p_east_9km'   => [self::CENTER_LAT, self::CENTER_LNG + 9000 / (111000.0 * cos(deg2rad(self::CENTER_LAT)))],
            'p_corner_ne'  => [self::CENTER_LAT + 0.95 * $dLat, self::CENTER_LNG + 0.95 * $dLng],
            'p_corner_sw'  => [self::CENTER_LAT - 0.90 * $dLat, self::CENTER_LNG - 0.90 * $dLng],
            'p_north_11km' => [self::CENTER_LAT + 11000 / 111000.0, self::CENTER_LNG],
            'p_far_30km'   => [self::CENTER_LAT + 30000 / 111000.0, self::CENTER_LNG],
        ];
        $docs = [];
        foreach ($points as $id => [$lat, $lng]) {
            $text = strpos($id, 'p_corner') === 0 ? 'coffee coffee coffee coffee' : 'coffee';
            $docs[] = ['id' => $id, 'content' => ['title' => $text . ' ' . $id, 'content' => $text], 'geo' => ['lat' => $lat, 'lng' => $lng]];
        }
        // A region: its center is about 2.8 km north
        $docs[] = [
            'id' => 'region_bounds',
            'content' => ['title' => 'coffee region', 'content' => 'coffee'],
            'geo_bounds' => ['north' => self::CENTER_LAT + 0.04, 'south' => self::CENTER_LAT + 0.01, 'east' => self::CENTER_LNG + 0.02, 'west' => self::CENTER_LNG - 0.02],
        ];
        // No geo at all
        $docs[] = ['id' => 'no_geo', 'content' => ['title' => 'coffee nowhere', 'content' => 'coffee']];

        return $docs;
    }

    /**
     * @return array{0:YetiSearch,1:\YetiSearch\Search\SearchEngine}
     */
    private function build(bool $external, string $mode, ?array $docs = null, array $config = []): array
    {
        $search = $this->createSearchInstance(array_replace_recursive([
            'storage' => ['external_content' => $external],
            'search' => ['cache_ttl' => 0],
        ], $config));

        $storage = $this->storageOf($search);
        if ($mode === 'nomath') {
            $this->setPrivate($storage, 'hasMathFunctions', false);
            $this->removeSqlMathFunctions($storage);
        } elseif (!$this->getPrivate($storage, 'hasMathFunctions')) {
            // The Windows PHP builds in CI, for one: the 'nomath' modes cover them
            $this->markTestSkipped('This SQLite build has no math functions.');
        }
        if ($mode !== 'rtree') {
            $this->setPrivate($storage, 'rtreeSupport', false);
        }

        $search->createIndex(self::INDEX, ['external_content' => $external]);
        $this->createdIndexes[] = self::INDEX;
        $search->indexBatch(self::INDEX, $docs ?? $this->placeDocs());
        $search->getIndexer(self::INDEX)->flush();

        $spatialSql = (string)$this->spatialTableSql($search);
        $isRtree = stripos($spatialSql, 'rtree') !== false;
        if ($mode === 'rtree' && !$isRtree) {
            $this->markTestSkipped('This SQLite build has no R-tree module.');
        }
        $this->assertSame($mode === 'rtree', $isRtree, 'The spatial table is not the kind this test case asks for');

        return [$search, $search->getSearchEngine(self::INDEX)];
    }

    private function storageOf(YetiSearch $search)
    {
        $method = new \ReflectionMethod($search, 'getStorage');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        return $method->invoke($search);
    }

    /** @return mixed */
    private function getPrivate(object $object, string $property)
    {
        $prop = new \ReflectionProperty($object, $property);
        if (PHP_VERSION_ID < 80100) {
            $prop->setAccessible(true);
        }

        return $prop->getValue($object);
    }

    /**
     * Make every SQLite math function fail on the storage's connection, as it
     * does on a build without them (the Windows PHP builds in CI), so a query that
     * still calls one errors here instead of only there.
     */
    private function removeSqlMathFunctions(object $storage): void
    {
        $pdo = $this->getPrivate($storage, 'connection');
        $names = ['acos', 'acosh', 'asin', 'asinh', 'atan', 'atan2', 'atanh', 'ceil', 'ceiling', 'cos', 'cosh',
            'degrees', 'exp', 'floor', 'ln', 'log', 'log10', 'log2', 'mod', 'pi', 'pow', 'power', 'radians',
            'sin', 'sinh', 'sqrt', 'tan', 'tanh', 'trunc'];
        foreach ($names as $name) {
            // Deprecated on a plain PDO since PHP 8.5, and the only way to reach it here
            @$pdo->sqliteCreateFunction($name, function () use ($name) {
                throw new \RuntimeException('no such function: ' . $name);
            }, -1);
        }
    }

    private function setPrivate(object $object, string $property, $value): void
    {
        $prop = new \ReflectionProperty($object, $property);
        if (PHP_VERSION_ID < 80100) {
            $prop->setAccessible(true);
        }
        $prop->setValue($object, $value);
    }

    private function spatialTableSql(YetiSearch $search): ?string
    {
        $storage = $this->storageOf($search);
        $connection = new \ReflectionProperty($storage, 'connection');
        if (PHP_VERSION_ID < 80100) {
            $connection->setAccessible(true);
        }
        $stmt = $connection->getValue($storage)->prepare("SELECT sql FROM sqlite_master WHERE name = ?");
        $stmt->execute([self::INDEX . '_spatial']);
        $sql = $stmt->fetchColumn();

        return $sql === false ? null : (string)$sql;
    }

    private function ids($results): array
    {
        $ids = [];
        foreach ($results as $r) {
            $ids[] = is_array($r) ? $r['id'] : $r->getId();
        }

        return $ids;
    }

    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    private function center(): GeoPoint
    {
        return new GeoPoint(self::CENTER_LAT, self::CENTER_LNG);
    }

    private function centerArray(): array
    {
        return ['lat' => self::CENTER_LAT, 'lng' => self::CENTER_LNG];
    }

    // ------------------------------------------------------------------- near

    /**
     * @dataProvider modesWithoutSqlMath
     */
    public function test_near_returns_exactly_the_points_inside_the_radius(bool $external, string $mode): void
    {
        [, $engine] = $this->build($external, $mode);

        foreach (['coffee', ''] as $text) {
            $r = $engine->search((new SearchQuery($text))->near($this->center(), self::RADIUS)->limit(50));
            $this->assertSame($this->sorted(self::INSIDE), $this->sorted($this->ids($r->getResults())), "near() with text '{$text}'");
            foreach ($r->getResults() as $hit) {
                $this->assertLessThanOrEqual(self::RADIUS, $hit->getDistance());
            }
        }
    }

    /**
     * @dataProvider modes
     */
    public function test_near_total_counts_the_circle_and_not_the_box(bool $external, string $mode): void
    {
        [, $engine] = $this->build($external, $mode);

        foreach (['coffee', ''] as $text) {
            $r = $engine->search((new SearchQuery($text))->near($this->center(), self::RADIUS)->limit(50));
            $this->assertSame(count(self::INSIDE), $r->getTotalCount(), "total with text '{$text}'");
        }
    }

    /**
     * @dataProvider modes
     */
    public function test_near_pages_correctly_when_box_corners_rank_first(bool $external, string $mode): void
    {
        [, $engine] = $this->build($external, $mode);

        $pages = [];
        foreach ([0, 2] as $offset) {
            $r = $engine->search((new SearchQuery('coffee'))->near($this->center(), self::RADIUS)->limit(2)->offset($offset));
            $this->assertSame(count(self::INSIDE), $r->getTotalCount(), "total at offset {$offset}");
            $ids = $this->ids($r->getResults());
            $this->assertCount(2, $ids, "page at offset {$offset}");
            $pages = array_merge($pages, $ids);
        }
        $this->assertSame($this->sorted(self::INSIDE), $this->sorted($pages));
    }

    /**
     * Without SQL math the radius test runs in PHP after the page is cut, so a page can come back short.
     * Sorting by distance cuts the page after the test, and pages fully.
     */
    public function test_near_pages_by_distance_without_sql_math(): void
    {
        foreach ([true, false] as $external) {
            [, $engine] = $this->build($external, 'nomath');
            $q = (new SearchQuery('coffee'))->near($this->center(), self::RADIUS)->sortByDistance($this->center())->limit(2);
            $this->assertSame(['p_center', 'region_bounds'], $this->ids($engine->search($q)->getResults()));
            $this->assertSame(['p_north_5km', 'p_east_9km'], $this->ids($engine->search($q->offset(2))->getResults()));
        }
    }

    /**
     * @dataProvider modes
     */
    public function test_near_with_distance_sort_orders_by_distance(bool $external, string $mode): void
    {
        [, $engine] = $this->build($external, $mode);

        $q = (new SearchQuery('coffee'))->near($this->center(), self::RADIUS)->sortByDistance($this->center())->limit(50);
        $r = $engine->search($q);
        $this->assertSame(['p_center', 'region_bounds', 'p_north_5km', 'p_east_9km'], $this->ids($r->getResults()));
        $this->assertSame(4, $r->getTotalCount());
    }

    // ----------------------------------------------------------------- within

    /**
     * @dataProvider modesWithoutSqlMath
     */
    public function test_within_returns_the_points_inside_the_box(bool $external, string $mode): void
    {
        [, $engine] = $this->build($external, $mode);

        // Centered on the center, so a swapped east and west leaves out the center itself
        $bounds = new GeoBounds(self::CENTER_LAT + 0.06, self::CENTER_LAT - 0.06, self::CENTER_LNG + 0.1, self::CENTER_LNG - 0.1);
        $r = $engine->search((new SearchQuery('coffee'))->within($bounds)->limit(50));
        $this->assertSame($this->sorted(['p_center', 'p_north_5km', 'region_bounds']), $this->sorted($this->ids($r->getResults())));
        $this->assertSame(3, $r->getTotalCount());

        // A box off to one side of the center
        $east = new GeoBounds(self::CENTER_LAT + 0.01, self::CENTER_LAT - 0.01, self::CENTER_LNG + 0.2, self::CENTER_LNG + 0.05);
        $r = $engine->search((new SearchQuery('coffee'))->within($east)->limit(50));
        $this->assertSame(['p_east_9km'], $this->ids($r->getResults()));
    }

    // ------------------------------------------------------------ antimeridian

    private function datelineDocs(): array
    {
        return [
            ['id' => 'dl_east_4km', 'content' => ['title' => 'coffee east', 'content' => 'coffee'], 'geo' => ['lat' => 0.0, 'lng' => 179.99]],
            ['id' => 'dl_west_9km', 'content' => ['title' => 'coffee west', 'content' => 'coffee'], 'geo' => ['lat' => 0.0, 'lng' => -179.97]],
            ['id' => 'dl_far', 'content' => ['title' => 'coffee far', 'content' => 'coffee'], 'geo' => ['lat' => 0.0, 'lng' => -179.5]],
            ['id' => 'dl_other_side', 'content' => ['title' => 'coffee other', 'content' => 'coffee'], 'geo' => ['lat' => 0.0, 'lng' => 0.0]],
        ];
    }

    /**
     * @dataProvider modesWithoutSqlMath
     */
    public function test_near_finds_points_on_both_sides_of_the_antimeridian(bool $external, string $mode): void
    {
        [, $engine] = $this->build($external, $mode, $this->datelineDocs());

        // Centered east of 180, with a point 4.4 km east of it and one 8.9 km away on the other side
        $r = $engine->search((new SearchQuery('coffee'))->near(new GeoPoint(0.0, 179.95), 10000)->limit(10));
        $this->assertSame(['dl_east_4km', 'dl_west_9km'], $this->sorted($this->ids($r->getResults())));

        // Centered west of -180
        $r = $engine->search((new SearchQuery('coffee'))->near(new GeoPoint(0.0, -179.95), 10000)->limit(10));
        $this->assertSame(['dl_east_4km', 'dl_west_9km'], $this->sorted($this->ids($r->getResults())));
    }

    /**
     * @dataProvider modes
     */
    public function test_near_across_the_antimeridian_counts_both_sides(bool $external, string $mode): void
    {
        [, $engine] = $this->build($external, $mode, $this->datelineDocs());

        $r = $engine->search((new SearchQuery('coffee'))->near(new GeoPoint(0.0, 179.95), 10000)->limit(1));
        $this->assertSame(2, $r->getTotalCount());
    }

    /**
     * @dataProvider modesWithoutSqlMath
     */
    public function test_within_across_the_antimeridian(bool $external, string $mode): void
    {
        [, $engine] = $this->build($external, $mode, $this->datelineDocs());

        $r = $engine->search((new SearchQuery('coffee'))->within(new GeoBounds(1, -1, -179.9, 179.9))->limit(10));
        $this->assertSame(['dl_east_4km', 'dl_west_9km'], $this->sorted($this->ids($r->getResults())));
    }

    // -------------------------------------------------------- poles and width

    /**
     * @dataProvider modesWithoutSqlMath
     */
    public function test_near_reaching_a_pole_takes_every_longitude(bool $external, string $mode): void
    {
        $docs = [];
        foreach (['pole_0' => [89.9, 0.0], 'pole_90' => [89.9, 90.0], 'pole_180' => [89.9, 180.0], 'pole_m90' => [89.9, -90.0], 'far' => [80.0, 0.0]] as $id => [$lat, $lng]) {
            $docs[] = ['id' => $id, 'content' => ['title' => 'coffee ' . $id, 'content' => 'coffee'], 'geo' => ['lat' => $lat, 'lng' => $lng]];
        }
        [, $engine] = $this->build($external, $mode, $docs);

        // Each of the four is 5.6 to 17 km from here, over the pole or not
        $r = $engine->search((new SearchQuery('coffee'))->near(new GeoPoint(89.95, 0.0), 20000)->limit(10));
        $this->assertSame(['pole_0', 'pole_180', 'pole_90', 'pole_m90'], $this->sorted($this->ids($r->getResults())));
    }

    /**
     * @dataProvider modesWithoutSqlMath
     */
    public function test_near_with_a_wide_radius_at_high_latitude(bool $external, string $mode): void
    {
        // A circle is widest east and west not at its center's latitude but farther poleward, where
        // a meridian touches it. Put one point just inside that spot and one just outside.
        $lat0 = 70.0;
        $lng0 = 10.0;
        $radius = 500000.0;
        $angular = $radius / 6371000.0;
        $widest = asin(sin($angular) / cos(deg2rad($lat0)));
        $latAtWidest = rad2deg(asin(sin(deg2rad($lat0)) / cos($angular)));

        $inside = [$latAtWidest, $lng0 + rad2deg($widest) * 0.9995];
        $outside = [$latAtWidest, $lng0 + rad2deg($widest) * 1.0005];
        $this->assertLessThan($radius, $this->haversine($lat0, $lng0, $inside[0], $inside[1]));
        $this->assertGreaterThan($radius, $this->haversine($lat0, $lng0, $outside[0], $outside[1]));

        $docs = [
            ['id' => 'inside', 'content' => ['title' => 'coffee inside', 'content' => 'coffee'], 'geo' => ['lat' => $inside[0], 'lng' => $inside[1]]],
            ['id' => 'outside', 'content' => ['title' => 'coffee outside', 'content' => 'coffee'], 'geo' => ['lat' => $outside[0], 'lng' => $outside[1]]],
        ];
        [, $engine] = $this->build($external, $mode, $docs);

        $r = $engine->search((new SearchQuery('coffee'))->near(new GeoPoint($lat0, $lng0), $radius)->limit(10));
        $this->assertSame(['inside'], $this->ids($r->getResults()));
    }

    // ------------------------------------------------------------ max_distance

    /**
     * @dataProvider modesWithoutSqlMath
     */
    public function test_max_distance_clamps_a_distance_sort(bool $external, string $mode): void
    {
        [$search] = $this->build($external, $mode);

        $res = $search->search(self::INDEX, 'coffee', [
            'limit' => 50,
            'geoFilters' => [
                'distance_sort' => ['from' => $this->centerArray(), 'direction' => 'asc'],
                'max_distance' => 6,
                'units' => 'km',
            ],
        ]);
        $this->assertSame(['p_center', 'region_bounds', 'p_north_5km'], $this->ids($res['results']));
        $this->assertSame(3, $res['total']);
    }

    /**
     * @dataProvider modesWithoutSqlMath
     */
    public function test_a_document_indexed_again_without_geo_loses_its_location(bool $external, string $mode): void
    {
        [$search] = $this->build($external, $mode);
        $near = ['geoFilters' => ['near' => ['point' => $this->centerArray(), 'radius' => self::RADIUS]], 'limit' => 50];
        $this->assertContains('p_north_5km', $this->ids($search->search(self::INDEX, 'coffee', $near)['results']));

        // A long body, so the document is chunked and its chunks lose the location too
        $search->index(self::INDEX, [
            'id' => 'p_north_5km',
            'content' => ['title' => 'coffee p_north_5km', 'content' => 'coffee ' . str_repeat('moved away without a location. ', 120)],
        ]);
        $search->getIndexer(self::INDEX)->flush();

        $ids = $this->ids($search->search(self::INDEX, 'coffee', $near)['results']);
        $this->assertNotContains('p_north_5km', $ids, 'the old location is gone');
        $this->assertContains('p_center', $ids, 'other locations stay');
        $this->assertContains('p_north_5km', $this->ids($search->search(self::INDEX, 'moved', ['limit' => 50])['results']), 'still found by text');
    }

    /**
     * @dataProvider modesWithoutSqlMath
     */
    public function test_max_distance_clamps_nearest_at_the_storage(bool $external, string $mode): void
    {
        [$search] = $this->build($external, $mode);
        $storage = $this->storageOf($search);

        $rows = $storage->search(self::INDEX, [
            'query' => '',
            'limit' => 10,
            'geoFilters' => ['nearest' => ['k' => 10, 'from' => $this->centerArray()], 'max_distance' => 6000],
        ]);
        $this->assertSame(['p_center', 'region_bounds', 'p_north_5km'], $this->ids($rows));
    }

    // ----------------------------------------------------------------- facade

    /**
     * @dataProvider modesWithoutSqlMath
     */
    public function test_facade_runs_the_readme_nearest_example(bool $external, string $mode): void
    {
        [$search] = $this->build($external, $mode, null, ['search' => ['geo_units' => 'km']]);

        // README: k-NN with a distance sort, a max distance and units
        $res = $search->search(self::INDEX, '', [
            'geoFilters' => [
                'nearest' => 2,
                'distance_sort' => ['from' => $this->centerArray(), 'direction' => 'asc'],
                'max_distance' => 6,
                'units' => 'km',
            ],
            'limit' => 5,
        ]);
        $this->assertSame(['p_center', 'region_bounds'], $this->ids($res['results']), 'nearest 2 stops at two');
        // k-NN rows once nested their content, so every result came back with an empty document
        $this->assertSame(
            ['title' => 'coffee p_center', 'content' => 'coffee'],
            $res['results'][0]['document'],
            'nearest returns the document'
        );

        $res = $search->search(self::INDEX, '', [
            'geoFilters' => [
                'nearest' => ['k' => 10],
                'distance_sort' => ['from' => $this->centerArray(), 'direction' => 'asc'],
                'max_distance' => 6,
                'units' => 'km',
            ],
            'limit' => 10,
        ]);
        $this->assertSame(['p_center', 'region_bounds', 'p_north_5km'], $this->ids($res['results']), 'max_distance 6 km');

        // A neighbor that is not due north has a fractional bearing, which once raised a deprecation
        $res = $search->search(self::INDEX, '', [
            'geoFilters' => [
                'nearest' => 4,
                'distance_sort' => ['from' => $this->centerArray(), 'direction' => 'asc'],
                'max_distance' => 10,
                'units' => 'km',
            ],
            'limit' => 4,
        ]);
        $this->assertSame(['p_center', 'region_bounds', 'p_north_5km', 'p_east_9km'], $this->ids($res['results']), 'max_distance 10 km');
        $east = $res['results'][3];
        $this->assertSame('E', $east['metadata']['bearing_cardinal']);
    }

    /**
     * @dataProvider modes
     */
    public function test_facade_near_reads_units_once(bool $external, string $mode): void
    {
        // search.geo_units is km as well: a radius of 10 with units km must stay 10 km
        foreach ([[], ['search' => ['geo_units' => 'km']]] as $config) {
            [$search] = $this->build($external, $mode, null, $config);

            $near = ['point' => $this->centerArray(), 'radius' => 10, 'units' => 'km'];
            $res = $search->search(self::INDEX, 'coffee', ['limit' => 50, 'geoFilters' => ['near' => $near]]);
            $this->assertSame($this->sorted(self::INSIDE), $this->sorted($this->ids($res['results'])));
            $this->assertSame(4, $res['total']);
        }
    }

    /**
     * @dataProvider modes
     */
    public function test_geo_units_is_the_default_unit_for_radius_and_max_distance(bool $external, string $mode): void
    {
        [$search, $engine] = $this->build($external, $mode, null, ['search' => ['geo_units' => 'km']]);

        // A radius of 10 with no units is 10 km, through the facade and through SearchQuery
        $near = ['point' => $this->centerArray(), 'radius' => 10];
        $res = $search->search(self::INDEX, 'coffee', ['limit' => 50, 'geoFilters' => ['near' => $near]]);
        $this->assertSame($this->sorted(self::INSIDE), $this->sorted($this->ids($res['results'])));
        $this->assertSame(4, $res['total']);

        $query = (new SearchQuery('coffee'))->near($this->center(), 10)->limit(50);
        $this->assertSame($this->sorted(self::INSIDE), $this->sorted($this->ids($engine->search($query)->getResults())));

        // max_distance follows it
        $res = $search->search(self::INDEX, '', [
            'geoFilters' => ['nearest' => 10, 'distance_sort' => ['from' => $this->centerArray()], 'max_distance' => 6],
            'limit' => 10,
        ]);
        $this->assertSame(['p_center', 'region_bounds', 'p_north_5km'], $this->ids($res['results']));

        // Units on the query still win
        $near = ['point' => $this->centerArray(), 'radius' => 10000, 'units' => 'm'];
        $res = $search->search(self::INDEX, 'coffee', ['limit' => 50, 'geoFilters' => ['near' => $near]]);
        $this->assertSame($this->sorted(self::INSIDE), $this->sorted($this->ids($res['results'])));
    }

    /**
     * @dataProvider modes
     */
    public function test_facade_takes_top_level_units_and_within(bool $external, string $mode): void
    {
        [$search] = $this->build($external, $mode);

        $res = $search->search(self::INDEX, 'coffee', [
            'limit' => 50,
            'geoFilters' => ['near' => ['point' => $this->centerArray(), 'radius' => 10], 'units' => 'km'],
        ]);
        $this->assertSame($this->sorted(self::INSIDE), $this->sorted($this->ids($res['results'])), 'top-level units');

        $res = $search->search(self::INDEX, 'coffee', [
            'limit' => 50,
            'geoFilters' => ['within' => ['bounds' => [
                'north' => self::CENTER_LAT + 0.06, 'south' => self::CENTER_LAT - 0.06,
                'east' => self::CENTER_LNG + 0.1, 'west' => self::CENTER_LNG - 0.1,
            ]]],
        ]);
        $this->assertSame($this->sorted(['p_center', 'p_north_5km', 'region_bounds']), $this->sorted($this->ids($res['results'])), 'within');
    }

    // ------------------------------------------------------------------ facet

    /**
     * @dataProvider modesWithoutSqlMath
     */
    public function test_distance_facet_leaves_out_documents_without_geo(bool $external, string $mode): void
    {
        [$search] = $this->build($external, $mode);
        $facet = ['distance' => ['from' => $this->centerArray(), 'ranges' => [1, 5], 'units' => 'km']];

        // The only match has no geo: no bucket counts it
        $res = $search->search(self::INDEX, 'nowhere', ['facets' => $facet]);
        $this->assertSame([['value' => '<= 1 km', 'count' => 0], ['value' => '<= 5 km', 'count' => 0]], $res['facets']['distance']);

        // All nine match: the eight with geo are counted, the one without is not
        $res = $search->search(self::INDEX, 'coffee', ['limit' => 50, 'facets' => $facet]);
        $counts = [];
        foreach ($res['facets']['distance'] as $bucket) {
            $counts[$bucket['value']] = $bucket['count'];
        }
        $this->assertSame(['<= 1 km' => 1, '<= 5 km' => 1, '> 5 km' => 6], $counts);
    }
}
