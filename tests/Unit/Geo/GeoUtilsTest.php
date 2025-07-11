<?php

declare(strict_types=1);

namespace YetiSearch\Tests\Unit\Geo;

use PHPUnit\Framework\TestCase;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Geo\GeoBounds;
use YetiSearch\Geo\GeoUtils;
use YetiSearch\Exceptions\InvalidArgumentException;

class GeoUtilsTest extends TestCase
{
    public function testDistanceBetweenPoints(): void
    {
        // Test distance between two GeoPoint objects
        $point1 = new GeoPoint(37.7749, -122.4194); // San Francisco
        $point2 = new GeoPoint(37.7849, -122.4094); // ~1.4km away
        
        $distance = GeoUtils::distance($point1, $point2);
        
        // Should be approximately 1414 meters
        $this->assertGreaterThan(1400, $distance);
        $this->assertLessThan(1450, $distance);
    }
    
    public function testDistanceBetweenCoordinates(): void
    {
        // Test distance using raw coordinates
        $distance = GeoUtils::distanceBetween(
            37.7749, -122.4194, // San Francisco
            37.7849, -122.4094  // ~1.4km away
        );
        
        $this->assertGreaterThan(1400, $distance);
        $this->assertLessThan(1450, $distance);
    }
    
    public function testDistanceZeroForSamePoint(): void
    {
        $point = new GeoPoint(37.7749, -122.4194);
        $distance = GeoUtils::distance($point, $point);
        
        $this->assertEquals(0, $distance);
    }
    
    public function testDistanceAcrossDateLine(): void
    {
        // Test distance calculation across the international date line
        $point1 = new GeoPoint(0, 179.9);
        $point2 = new GeoPoint(0, -179.9);
        
        $distance = GeoUtils::distance($point1, $point2);
        
        // Should be very small distance, not half the world
        $this->assertLessThan(25000, $distance); // Less than 25km
    }
    
    public function testIsWithinRadius(): void
    {
        $center = new GeoPoint(37.7749, -122.4194);
        $nearPoint = new GeoPoint(37.7849, -122.4094); // ~1.4km away
        $farPoint = new GeoPoint(37.8049, -122.4194);  // ~3.3km away
        
        $this->assertTrue(GeoUtils::isWithinRadius($nearPoint, $center, 2000)); // 2km radius
        $this->assertFalse(GeoUtils::isWithinRadius($nearPoint, $center, 1000)); // 1km radius
        $this->assertFalse(GeoUtils::isWithinRadius($farPoint, $center, 2000)); // 2km radius
        $this->assertTrue(GeoUtils::isWithinRadius($farPoint, $center, 5000)); // 5km radius
    }
    
    public function testGetBoundingBox(): void
    {
        $center = new GeoPoint(37.7749, -122.4194);
        $radiusMeters = 1000; // 1km
        
        $bounds = GeoUtils::getBoundingBox($center, $radiusMeters);
        
        $this->assertInstanceOf(GeoBounds::class, $bounds);
        
        // Check that bounds are approximately correct
        $this->assertGreaterThan(37.765, $bounds->getSouth());
        $this->assertLessThan(37.785, $bounds->getNorth());
        $this->assertGreaterThan(-122.435, $bounds->getWest());
        $this->assertLessThan(-122.405, $bounds->getEast());
        
        // Verify the center is within the bounds
        $this->assertTrue($bounds->contains($center));
    }
    
    public function testGetBoundingBoxAtPoles(): void
    {
        // Test bounding box near north pole
        $northPole = new GeoPoint(89.9, 0);
        $bounds = GeoUtils::getBoundingBox($northPole, 10000);
        
        // At poles, bounds may be more constrained
        $this->assertInstanceOf(GeoBounds::class, $bounds);
        $this->assertTrue($bounds->contains($northPole));
    }
    
    public function testKmToMeters(): void
    {
        $this->assertEquals(1000, GeoUtils::kmToMeters(1));
        $this->assertEquals(5000, GeoUtils::kmToMeters(5));
        $this->assertEquals(1500, GeoUtils::kmToMeters(1.5));
        $this->assertEquals(0, GeoUtils::kmToMeters(0));
    }
    
    public function testMilesToMeters(): void
    {
        $this->assertEqualsWithDelta(1609.34, GeoUtils::milesToMeters(1), 0.01);
        $this->assertEqualsWithDelta(8046.72, GeoUtils::milesToMeters(5), 0.01);
        $this->assertEqualsWithDelta(2414.02, GeoUtils::milesToMeters(1.5), 0.01);
        $this->assertEquals(0, GeoUtils::milesToMeters(0));
    }
    
    public function testMetersToKm(): void
    {
        $this->assertEquals(1, GeoUtils::metersToKm(1000));
        $this->assertEquals(5, GeoUtils::metersToKm(5000));
        $this->assertEquals(1.5, GeoUtils::metersToKm(1500));
        $this->assertEquals(0, GeoUtils::metersToKm(0));
    }
    
    public function testMetersToMiles(): void
    {
        $this->assertEqualsWithDelta(0.621371, GeoUtils::metersToMiles(1000), 0.00001);
        $this->assertEqualsWithDelta(3.10686, GeoUtils::metersToMiles(5000), 0.00001);
        $this->assertEqualsWithDelta(0.932057, GeoUtils::metersToMiles(1500), 0.00001);
        $this->assertEquals(0, GeoUtils::metersToMiles(0));
    }
    
    public function testFormatDistanceMetric(): void
    {
        // Test metric formatting
        $this->assertEquals('500 m', GeoUtils::formatDistance(500));
        $this->assertEquals('1.0 km', GeoUtils::formatDistance(1000));
        $this->assertEquals('1.5 km', GeoUtils::formatDistance(1500));
        $this->assertEquals('10.0 km', GeoUtils::formatDistance(10000));
        $this->assertEquals('0 m', GeoUtils::formatDistance(0));
        
        // Test rounding
        $this->assertEquals('1.2 km', GeoUtils::formatDistance(1234));
        $this->assertEquals('999 m', GeoUtils::formatDistance(999));
    }
    
    public function testFormatDistanceImperial(): void
    {
        // Test imperial formatting
        $this->assertEquals('0.3 mi', GeoUtils::formatDistance(500, 'imperial'));
        $this->assertEquals('0.6 mi', GeoUtils::formatDistance(1000, 'imperial'));
        $this->assertEquals('0.9 mi', GeoUtils::formatDistance(1500, 'imperial'));
        $this->assertEquals('6.2 mi', GeoUtils::formatDistance(10000, 'imperial'));
        $this->assertEquals('0 ft', GeoUtils::formatDistance(0, 'imperial'));
        
        // Test short distances in feet
        $this->assertEquals('164 ft', GeoUtils::formatDistance(50, 'imperial'));
        
        // Test rounding
        $this->assertEquals('0.8 mi', GeoUtils::formatDistance(1234, 'imperial'));
        $this->assertEquals('0.6 mi', GeoUtils::formatDistance(999, 'imperial'));
    }
    
    public function testFormatDistanceInvalidUnit(): void
    {
        // Invalid unit should default to metric
        $result = GeoUtils::formatDistance(1000, 'invalid');
        $this->assertEquals('1.0 km', $result);
    }
    
    public function testParsePointFromGeoPoint(): void
    {
        $original = new GeoPoint(37.7749, -122.4194);
        $parsed = GeoUtils::parsePoint($original);
        
        $this->assertSame($original, $parsed);
    }
    
    public function testParsePointFromArray(): void
    {
        // Test associative array with lat/lng
        $point = GeoUtils::parsePoint(['lat' => 37.7749, 'lng' => -122.4194]);
        $this->assertEquals(37.7749, $point->getLatitude());
        $this->assertEquals(-122.4194, $point->getLongitude());
        
        // Test associative array with latitude/longitude
        $point = GeoUtils::parsePoint(['latitude' => 37.7749, 'longitude' => -122.4194]);
        $this->assertEquals(37.7749, $point->getLatitude());
        $this->assertEquals(-122.4194, $point->getLongitude());
        
        // Test numeric array
        $point = GeoUtils::parsePoint([37.7749, -122.4194]);
        $this->assertEquals(37.7749, $point->getLatitude());
        $this->assertEquals(-122.4194, $point->getLongitude());
    }
    
    public function testParsePointFromString(): void
    {
        // Test comma-separated string
        $point = GeoUtils::parsePoint('37.7749,-122.4194');
        $this->assertEquals(37.7749, $point->getLatitude());
        $this->assertEquals(-122.4194, $point->getLongitude());
        
        // Test with spaces
        $point = GeoUtils::parsePoint('37.7749, -122.4194');
        $this->assertEquals(37.7749, $point->getLatitude());
        $this->assertEquals(-122.4194, $point->getLongitude());
        
        // Test with extra spaces
        $point = GeoUtils::parsePoint('  37.7749  ,  -122.4194  ');
        $this->assertEquals(37.7749, $point->getLatitude());
        $this->assertEquals(-122.4194, $point->getLongitude());
    }
    
    public function testParsePointInvalidFormat(): void
    {
        $result = GeoUtils::parsePoint('invalid');
        $this->assertNull($result);
    }
    
    public function testParsePointInvalidArraySize(): void
    {
        $result = GeoUtils::parsePoint([37.7749]);
        $this->assertNull($result);
    }
    
    public function testParsePointMissingLatitude(): void
    {
        $result = GeoUtils::parsePoint(['lng' => -122.4194]);
        $this->assertNull($result);
    }
    
    public function testParsePointMissingLongitude(): void
    {
        $result = GeoUtils::parsePoint(['lat' => 37.7749]);
        $this->assertNull($result);
    }
    
    public function testParsePointInvalidType(): void
    {
        $result = GeoUtils::parsePoint(123);
        $this->assertNull($result);
    }
}