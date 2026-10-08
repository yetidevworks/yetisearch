<?php

namespace YetiSearch\Tests\Unit\Geo;

use YetiSearch\Tests\TestCase;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Geo\GeoBounds;
use YetiSearch\Exceptions\InvalidArgumentException;

class GeoPointTest extends TestCase
{
    public function testValidGeoPoint(): void
    {
        $point = new GeoPoint(45.5152, -122.6784);
        
        $this->assertEquals(45.5152, $point->getLatitude());
        $this->assertEquals(-122.6784, $point->getLongitude());
    }
    
    public function testInvalidLatitude(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Latitude must be between -90 and 90 degrees');
        
        new GeoPoint(91, 0);
    }
    
    public function testInvalidLongitude(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Longitude must be between -180 and 180 degrees');
        
        new GeoPoint(0, 181);
    }
    
    public function testDistanceCalculation(): void
    {
        // Portland, OR
        $portland = new GeoPoint(45.5152, -122.6784);
        
        // Seattle, WA  
        $seattle = new GeoPoint(47.6062, -122.3321);
        
        $distance = $portland->distanceTo($seattle);
        
        // Distance should be approximately 233 km (233,000 meters)
        $this->assertGreaterThan(230000, $distance);
        $this->assertLessThan(236000, $distance);
    }
    
    public function testDistanceToSamePoint(): void
    {
        $point = new GeoPoint(45.5152, -122.6784);
        $distance = $point->distanceTo($point);
        
        $this->assertEquals(0, $distance);
    }
    
    public function testBoundingBox(): void
    {
        $point = new GeoPoint(45.5152, -122.6784);
        
        // 5km radius
        $bounds = $point->getBoundingBox(5000);
        
        $this->assertInstanceOf(GeoBounds::class, $bounds);
        
        // Bounds should be approximately 0.045 degrees (5km) in each direction
        $this->assertGreaterThan(45.5152, $bounds->getNorth());
        $this->assertLessThan(45.5152, $bounds->getSouth());
        $this->assertGreaterThan(-122.6784, $bounds->getEast());
        $this->assertLessThan(-122.6784, $bounds->getWest());
        
        // The point should be within its own bounding box
        $this->assertTrue($bounds->contains($point));
    }
    
    public function testBoundingBoxEnclosesTheCircleAtItsWidestPoint(): void
    {
        // 500 km at 70N: the circle is widest east and west where a meridian touches it,
        // about 13.25 degrees from the center, more than the 13.17 that cos(70N) gives
        $point = new GeoPoint(70.0, 10.0);
        $bounds = $point->getBoundingBox(500000);

        $angular = 500000 / 6371000;
        $expectedHalfWidth = rad2deg(asin(sin($angular) / cos(deg2rad(70.0))));
        $this->assertEqualsWithDelta(10.0 + $expectedHalfWidth, $bounds->getEast(), 1e-9);
        $this->assertEqualsWithDelta(10.0 - $expectedHalfWidth, $bounds->getWest(), 1e-9);
        $this->assertGreaterThan(13.25, $bounds->getEast() - 10.0);
        $this->assertEqualsWithDelta(70.0 + rad2deg($angular), $bounds->getNorth(), 1e-9);
        $this->assertEqualsWithDelta(70.0 - rad2deg($angular), $bounds->getSouth(), 1e-9);
    }

    public function testBoundingBoxAcrossTheAntimeridianHasWestGreaterThanEast(): void
    {
        $east = (new GeoPoint(0.0, 179.95))->getBoundingBox(10000);
        $this->assertTrue($east->crossesDateLine());
        $this->assertEqualsWithDelta(179.95 - 0.0899, $east->getWest(), 1e-3);
        $this->assertEqualsWithDelta(-179.9601, $east->getEast(), 1e-3);
        $this->assertTrue($east->contains(new GeoPoint(0.0, 179.99)));
        $this->assertTrue($east->contains(new GeoPoint(0.0, -179.97)));
        $this->assertFalse($east->contains(new GeoPoint(0.0, -179.5)));

        $west = (new GeoPoint(0.0, -179.95))->getBoundingBox(10000);
        $this->assertTrue($west->crossesDateLine());
        $this->assertTrue($west->contains(new GeoPoint(0.0, 179.99)));
        $this->assertTrue($west->contains(new GeoPoint(0.0, -179.97)));
    }

    public function testBoundingBoxHoldingAPoleTakesEveryLongitude(): void
    {
        $bounds = (new GeoPoint(89.95, 0.0))->getBoundingBox(20000);
        $this->assertEquals(90.0, $bounds->getNorth());
        $this->assertEquals(-180.0, $bounds->getWest());
        $this->assertEquals(180.0, $bounds->getEast());
        $this->assertFalse($bounds->crossesDateLine());

        $south = (new GeoPoint(-89.95, 120.0))->getBoundingBox(20000);
        $this->assertEquals(-90.0, $south->getSouth());
        $this->assertEquals(-180.0, $south->getWest());
        $this->assertEquals(180.0, $south->getEast());

        // A radius past a quarter of the globe also holds a pole
        $wide = (new GeoPoint(0.0, 0.0))->getBoundingBox(12000000);
        $this->assertEquals(90.0, $wide->getNorth());
        $this->assertEquals(-90.0, $wide->getSouth());
    }

    public function testToArray(): void
    {
        $point = new GeoPoint(45.5152, -122.6784);
        $array = $point->toArray();
        
        $this->assertEquals([
            'lat' => 45.5152,
            'lng' => -122.6784
        ], $array);
    }
    
    public function testFromArray(): void
    {
        $data = ['lat' => 45.5152, 'lng' => -122.6784];
        $point = GeoPoint::fromArray($data);
        
        $this->assertEquals(45.5152, $point->getLatitude());
        $this->assertEquals(-122.6784, $point->getLongitude());
    }
    
    public function testFromArrayMissingKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Array must contain 'lat' and 'lng' keys");
        
        GeoPoint::fromArray(['latitude' => 45.5152]);
    }
    
    public function testToString(): void
    {
        $point = new GeoPoint(45.5152, -122.6784);
        
        $this->assertEquals('45.515200,-122.678400', (string)$point);
    }
    
    public function testEdgeCases(): void
    {
        // Test poles
        $northPole = new GeoPoint(90, 0);
        $southPole = new GeoPoint(-90, 0);
        
        $this->assertEquals(90, $northPole->getLatitude());
        $this->assertEquals(-90, $southPole->getLatitude());
        
        // Test date line
        $eastDateLine = new GeoPoint(0, 180);
        $westDateLine = new GeoPoint(0, -180);
        
        $this->assertEquals(180, $eastDateLine->getLongitude());
        $this->assertEquals(-180, $westDateLine->getLongitude());
    }
}