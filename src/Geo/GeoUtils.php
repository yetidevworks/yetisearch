<?php

namespace YetiSearch\Geo;

class GeoUtils
{
    /**
     * Earth radius in meters
     */
    const EARTH_RADIUS_METERS = 6371000;
    
    /**
     * Calculate the Haversine distance between two points
     * 
     * @param GeoPoint $from
     * @param GeoPoint $to
     * @return float Distance in meters
     */
    public static function distance(GeoPoint $from, GeoPoint $to): float
    {
        return $from->distanceTo($to);
    }
    
    /**
     * Calculate the Haversine distance using raw coordinates
     * 
     * @param float $lat1
     * @param float $lng1
     * @param float $lat2
     * @param float $lng2
     * @return float Distance in meters
     */
    public static function distanceBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLatRad = deg2rad($lat2 - $lat1);
        $deltaLngRad = deg2rad($lng2 - $lng1);
        
        $a = sin($deltaLatRad / 2) * sin($deltaLatRad / 2) +
             cos($lat1Rad) * cos($lat2Rad) *
             sin($deltaLngRad / 2) * sin($deltaLngRad / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return self::EARTH_RADIUS_METERS * $c;
    }
    
    /**
     * Convert kilometers to meters
     */
    public static function kmToMeters(float $km): float
    {
        return $km * 1000;
    }
    
    /**
     * Convert miles to meters
     */
    public static function milesToMeters(float $miles): float
    {
        return $miles * 1609.344;
    }
    
    /**
     * Convert meters to kilometers
     */
    public static function metersToKm(float $meters): float
    {
        return $meters / 1000;
    }
    
    /**
     * Convert meters to miles
     */
    public static function metersToMiles(float $meters): float
    {
        return $meters / 1609.344;
    }
    
    /**
     * Create a bounding box from a center point and radius
     * 
     * @param GeoPoint $center
     * @param float $radiusInMeters
     * @return GeoBounds
     */
    public static function getBoundingBox(GeoPoint $center, float $radiusInMeters): GeoBounds
    {
        return $center->getBoundingBox($radiusInMeters);
    }
    
    /**
     * Check if a point is within a given radius of another point
     * 
     * @param GeoPoint $point
     * @param GeoPoint $center
     * @param float $radiusInMeters
     * @return bool
     */
    public static function isWithinRadius(GeoPoint $point, GeoPoint $center, float $radiusInMeters): bool
    {
        return $point->distanceTo($center) <= $radiusInMeters;
    }
    
    /**
     * Parse various coordinate formats into GeoPoint
     * Supports:
     * - Array with 'lat' and 'lng' keys
     * - Array with 'latitude' and 'longitude' keys
     * - Array with numeric indices [lat, lng]
     * - String "lat,lng"
     * 
     * @param mixed $input
     * @return GeoPoint|null
     */
    public static function parsePoint($input): ?GeoPoint
    {
        if ($input instanceof GeoPoint) {
            return $input;
        }
        
        if (is_array($input)) {
            // Try 'lat' and 'lng' keys
            if (isset($input['lat']) && isset($input['lng'])) {
                return new GeoPoint((float)$input['lat'], (float)$input['lng']);
            }
            
            // Try 'latitude' and 'longitude' keys
            if (isset($input['latitude']) && isset($input['longitude'])) {
                return new GeoPoint((float)$input['latitude'], (float)$input['longitude']);
            }
            
            // Try numeric indices
            if (isset($input[0]) && isset($input[1])) {
                return new GeoPoint((float)$input[0], (float)$input[1]);
            }
        }
        
        if (is_string($input)) {
            // Try to parse "lat,lng" format
            $parts = explode(',', $input);
            if (count($parts) === 2) {
                $lat = trim($parts[0]);
                $lng = trim($parts[1]);
                if (is_numeric($lat) && is_numeric($lng)) {
                    return new GeoPoint((float)$lat, (float)$lng);
                }
            }
        }
        
        return null;
    }
    
    /**
     * Format a distance for display
     * 
     * @param float $meters
     * @param string $unit 'metric' or 'imperial'
     * @param int $decimals
     * @return string
     */
    public static function formatDistance(float $meters, string $unit = 'metric', int $decimals = 1): string
    {
        if ($unit === 'imperial') {
            $miles = self::metersToMiles($meters);
            if ($miles < 0.1) {
                $feet = $meters * 3.28084;
                return round($feet) . ' ft';
            }
            return number_format($miles, $decimals) . ' mi';
        }
        
        // Metric
        if ($meters < 1000) {
            return round($meters) . ' m';
        }
        
        $km = self::metersToKm($meters);
        return number_format($km, $decimals) . ' km';
    }
}