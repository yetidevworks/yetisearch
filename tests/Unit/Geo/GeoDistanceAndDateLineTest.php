<?php

namespace YetiSearch\Tests\Unit\Geo;

use PHPUnit\Framework\TestCase;
use YetiSearch\Geo\GeoBounds;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Geo\GeoUtils;

class GeoDistanceAndDateLineTest extends TestCase
{
    public function testNearlyOppositePointsHaveADistance(): void
    {
        // A pair whose haversine term rounds a hair past 1
        $from = new GeoPoint(-42.2, -171.3);
        $to = new GeoPoint(42.2, 8.7);

        $distance = $from->distanceTo($to);
        $this->assertFalse(is_nan($distance));
        $this->assertEqualsWithDelta(M_PI * GeoUtils::EARTH_RADIUS_METERS, $distance, 1.0);
        $this->assertFalse(is_nan(GeoUtils::distanceBetween(-42.2, -171.3, 42.2, 8.7)));
    }

    public function testBothDistanceFunctionsAgree(): void
    {
        $from = new GeoPoint(37.7749, -122.4194);
        $to = new GeoPoint(40.7128, -74.0060);

        $this->assertSame(GeoUtils::distanceBetween(37.7749, -122.4194, 40.7128, -74.0060), $from->distanceTo($to));
        $this->assertSame($from->distanceTo($to), GeoUtils::distance($from, $to));
    }

    public function intersections(): array
    {
        // Each box as north, south, east, west; the first two cross the date line
        return [
            'date line box and a box at 0..10' => [[10, -10, -170, 170], [10, -10, 10, 0], false],
            'date line box and a box at 175..179' => [[10, -10, -170, 170], [10, -10, 179, 175], true],
            'date line box and a box at -179..-175' => [[10, -10, -170, 170], [10, -10, -175, -179], true],
            'two date line boxes' => [[10, -10, -170, 170], [10, -10, -160, 160], true],
            'date line box and a box west of it' => [[10, -10, -170, 170], [10, -10, 160, 150], false],
            'boxes apart in latitude' => [[10, -10, -170, 170], [30, 20, -170, 170], false],
            'plain boxes that touch' => [[10, 0, 10, 0], [10, 0, 20, 10], true],
            'plain boxes apart' => [[10, 0, 10, 0], [10, 0, 30, 20], false],
        ];
    }

    /**
     * @dataProvider intersections
     */
    public function testIntersectsAcrossTheDateLine(array $a, array $b, bool $expected): void
    {
        $first = new GeoBounds(...$a);
        $second = new GeoBounds(...$b);

        $this->assertSame($expected, $first->intersects($second));
        $this->assertSame($expected, $second->intersects($first));
    }
}
