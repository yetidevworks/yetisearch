<?php

namespace YetiSearch\Geo;

use YetiSearch\Exceptions\InvalidArgumentException;

/**
 * Encodes as toArray() in JSON, so a query's cache key and its logged form
 * carry the coordinates.
 */
class GeoPoint implements \JsonSerializable
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
     * Calculate the bounding box that encloses a circle of the given radius
     *
     * The box is the smallest one that holds every point within the radius. Its
     * longitude half-width is asin(sin(r / R) / cos(latitude)), which is wider
     * than the radius in degrees at the center's latitude. A box that crosses
     * the antimeridian comes back with west greater than east, the same way
     * GeoBounds represents any box that crosses it. When the circle holds a
     * pole, the box is clamped at the pole and takes every longitude.
     *
     * @param float $radiusInMeters
     * @return GeoBounds
     */
    public function getBoundingBox(float $radiusInMeters): GeoBounds
    {
        $earthRadius = 6371000; // Earth radius in meters

        // Angular distance in radians
        $angularDistance = max(0.0, $radiusInMeters) / $earthRadius;

        $lat = deg2rad($this->latitude);
        $lng = deg2rad($this->longitude);

        $minLat = $lat - $angularDistance;
        $maxLat = $lat + $angularDistance;

        if ($minLat <= -M_PI / 2 || $maxLat >= M_PI / 2) {
            // The circle holds a pole, so it reaches every longitude
            return new GeoBounds(
                min(90.0, rad2deg($maxLat)),
                max(-90.0, rad2deg($minLat)),
                180.0,
                -180.0
            );
        }

        // Half the width of the box in longitude: the circle's widest east-west reach is
        // where a meridian is tangent to it, not at the center's latitude
        $deltaLng = asin(min(1.0, sin($angularDistance) / cos($lat)));

        $west = rad2deg($lng - $deltaLng);
        $east = rad2deg($lng + $deltaLng);

        // Past +-180 the box wraps, which leaves west greater than east
        if ($west < -180.0) {
            $west += 360.0;
        }
        if ($east > 180.0) {
            $east -= 360.0;
        }

        return new GeoBounds(
            rad2deg($maxLat), // north
            rad2deg($minLat), // south
            $east,
            $west
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
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
