<?php

namespace YetiSearch\Tests\Unit\Geo;

use YetiSearch\Tests\TestCase;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Geo\GeoBounds;
use YetiSearch\Exceptions\InvalidArgumentException;

class GeoBoundsTest extends TestCase
{
    public function testValidBounds(): void
    {
        $bounds = new GeoBounds(45.6, 45.4, -122.5, -122.8);
        
        $this->assertEquals(45.6, $bounds->getNorth());
        $this->assertEquals(45.4, $bounds->getSouth());
        $this->assertEquals(-122.5, $bounds->getEast());
        $this->assertEquals(-122.8, $bounds->getWest());
    }
    
    public function testInvalidNorthSouthOrder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('North latitude (45.4) must be greater than south latitude (45.6)');
        
        new GeoBounds(45.4, 45.6, -122.5, -122.8);
    }
    
    public function testInvalidLatitude(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('North latitude must be between -90 and 90 degrees');
        
        new GeoBounds(91, 45, -122.5, -122.8);
    }
    
    public function testInvalidLongitude(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('West longitude must be between -180 and 180 degrees');
        
        new GeoBounds(45.6, 45.4, -122.5, -181);
    }
    
    public function testContainsPoint(): void
    {
        $bounds = new GeoBounds(45.6, 45.4, -122.5, -122.8);
        
        // Point inside bounds
        $inside = new GeoPoint(45.5, -122.65);
        $this->assertTrue($bounds->contains($inside));
        
        // Point outside bounds (north)
        $outsideNorth = new GeoPoint(45.7, -122.65);
        $this->assertFalse($bounds->contains($outsideNorth));
        
        // Point outside bounds (east)
        $outsideEast = new GeoPoint(45.5, -122.4);
        $this->assertFalse($bounds->contains($outsideEast));
        
        // Point on boundary
        $onBoundary = new GeoPoint(45.6, -122.5);
        $this->assertTrue($bounds->contains($onBoundary));
    }
    
    public function testDateLineCrossing(): void
    {
        // Bounds that cross the date line
        $bounds = new GeoBounds(45, -45, -170, 170);
        
        $this->assertTrue($bounds->crossesDateLine());
        
        // Point on the "wrapped" side
        $point1 = new GeoPoint(0, 175);
        $this->assertTrue($bounds->contains($point1));
        
        // Point on the other side
        $point2 = new GeoPoint(0, -175);
        $this->assertTrue($bounds->contains($point2));
        
        // Point in the "gap"
        $point3 = new GeoPoint(0, 0);
        $this->assertFalse($bounds->contains($point3));
    }
    
    public function testGetCenter(): void
    {
        $bounds = new GeoBounds(45.6, 45.4, -122.5, -122.8);
        $center = $bounds->getCenter();
        
        $this->assertInstanceOf(GeoPoint::class, $center);
        $this->assertEquals(45.5, $center->getLatitude());
        $this->assertEquals(-122.65, $center->getLongitude());
    }
    
    public function testGetCenterDateLineCrossing(): void
    {
        // Bounds crossing date line
        $bounds = new GeoBounds(10, -10, -170, 170);
        $center = $bounds->getCenter();
        
        $this->assertEquals(0, $center->getLatitude());
        // Center longitude should be 180 (or -180)
        $this->assertEqualsWithDelta(180, abs($center->getLongitude()), 0.001);
    }
    
    public function testIntersects(): void
    {
        $bounds1 = new GeoBounds(45.6, 45.4, -122.5, -122.8);
        
        // Overlapping bounds
        $bounds2 = new GeoBounds(45.55, 45.35, -122.45, -122.75);
        $this->assertTrue($bounds1->intersects($bounds2));
        
        // Non-overlapping bounds (too far north)
        $bounds3 = new GeoBounds(45.8, 45.7, -122.5, -122.8);
        $this->assertFalse($bounds1->intersects($bounds3));
        
        // Adjacent bounds
        $bounds4 = new GeoBounds(45.6, 45.4, -122.3, -122.5);
        $this->assertTrue($bounds1->intersects($bounds4));
    }
    
    public function testExpand(): void
    {
        $bounds = new GeoBounds(45.5, 45.5, -122.65, -122.65);
        
        // Expand by 5km
        $expanded = $bounds->expand(5000);
        
        $this->assertGreaterThan(45.5, $expanded->getNorth());
        $this->assertLessThan(45.5, $expanded->getSouth());
        $this->assertGreaterThan(-122.65, $expanded->getEast());
        $this->assertLessThan(-122.65, $expanded->getWest());
    }
    
    public function testToArray(): void
    {
        $bounds = new GeoBounds(45.6, 45.4, -122.5, -122.8);
        
        $this->assertEquals([
            'north' => 45.6,
            'south' => 45.4,
            'east' => -122.5,
            'west' => -122.8
        ], $bounds->toArray());
    }
    
    public function testFromArray(): void
    {
        $data = [
            'north' => 45.6,
            'south' => 45.4,
            'east' => -122.5,
            'west' => -122.8
        ];
        
        $bounds = GeoBounds::fromArray($data);
        
        $this->assertEquals(45.6, $bounds->getNorth());
        $this->assertEquals(45.4, $bounds->getSouth());
        $this->assertEquals(-122.5, $bounds->getEast());
        $this->assertEquals(-122.8, $bounds->getWest());
    }
    
    public function testFromArrayMissingKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Array must contain 'north' key");
        
        GeoBounds::fromArray(['south' => 45.4]);
    }
    
    public function testToString(): void
    {
        $bounds = new GeoBounds(45.6, 45.4, -122.5, -122.8);
        
        $this->assertEquals('N:45.600000, S:45.400000, E:-122.500000, W:-122.800000', (string)$bounds);
    }
}