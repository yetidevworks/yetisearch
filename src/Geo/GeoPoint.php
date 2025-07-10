<?php

namespace YetiSearch\Geo;

use YetiSearch\Exceptions\InvalidArgumentException;

class GeoPoint
{
    private float $latitude;
    private float $longitude;
    
    public function __construct(float $latitude, float $longitude)
    {
        $this->setLatitude($latitude);
        $this->setLongitude($longitude);
    }
    
    public function getLatitude(): float
    {
        return $this->latitude;
    }
    
    public function getLongitude(): float
    {
        return $this->longitude;
    }
    
    public function setLatitude(float $latitude): void
    {
        if ($latitude < -90 || $latitude > 90) {
            throw new InvalidArgumentException("Latitude must be between -90 and 90 degrees. Got: {$latitude}");
        }
        $this->latitude = $latitude;
    }
    
    public function setLongitude(float $longitude): void
    {
        if ($longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException("Longitude must be between -180 and 180 degrees. Got: {$longitude}");
        }
        $this->longitude = $longitude;
    }
    
    /**
     * Calculate distance to another point using Haversine formula
     * 
     * @param GeoPoint $other
     * @return float Distance in meters
     */
    public function distanceTo(GeoPoint $other): float
    {
        $earthRadius = 6371000; // Earth radius in meters
        
        $lat1Rad = deg2rad($this->latitude);
        $lat2Rad = deg2rad($other->getLatitude());
        $deltaLatRad = deg2rad($other->getLatitude() - $this->latitude);
        $deltaLngRad = deg2rad($other->getLongitude() - $this->longitude);
        
        $a = sin($deltaLatRad / 2) * sin($deltaLatRad / 2) +
             cos($lat1Rad) * cos($lat2Rad) *
             sin($deltaLngRad / 2) * sin($deltaLngRad / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }
    
    /**
     * Calculate bounding box for a given radius
     * 
     * @param float $radiusInMeters
     * @return GeoBounds
     */
    public function getBoundingBox(float $radiusInMeters): GeoBounds
    {
        $earthRadius = 6371000; // Earth radius in meters
        
        // Angular distance in radians
        $angularDistance = $radiusInMeters / $earthRadius;
        
        $lat = deg2rad($this->latitude);
        $lng = deg2rad($this->longitude);
        
        // Calculate min/max latitude
        $minLat = $lat - $angularDistance;
        $maxLat = $lat + $angularDistance;
        
        // Calculate min/max longitude
        $deltaLng = asin(sin($angularDistance) / cos($lat));
        $minLng = $lng - $deltaLng;
        $maxLng = $lng + $deltaLng;
        
        // Handle poles
        if ($minLat > deg2rad(-90) && $maxLat < deg2rad(90)) {
            $minLng = $lng - $deltaLng;
            $maxLng = $lng + $deltaLng;
        } else {
            // Near poles, use full longitude range
            $minLat = max($minLat, deg2rad(-90));
            $maxLat = min($maxLat, deg2rad(90));
            $minLng = deg2rad(-180);
            $maxLng = deg2rad(180);
        }
        
        return new GeoBounds(
            rad2deg($maxLat), // north
            rad2deg($minLat), // south
            rad2deg($maxLng), // east
            rad2deg($minLng)  // west
        );
    }
    
    public function toArray(): array
    {
        return [
            'lat' => $this->latitude,
            'lng' => $this->longitude
        ];
    }
    
    public static function fromArray(array $data): self
    {
        if (!isset($data['lat']) || !isset($data['lng'])) {
            throw new InvalidArgumentException("Array must contain 'lat' and 'lng' keys");
        }
        
        return new self($data['lat'], $data['lng']);
    }
    
    public function __toString(): string
    {
        return sprintf("%.6f,%.6f", $this->latitude, $this->longitude);
    }
}