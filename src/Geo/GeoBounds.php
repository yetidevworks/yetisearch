<?php

namespace YetiSearch\Geo;

use YetiSearch\Exceptions\InvalidArgumentException;

/**
 * Encodes as toArray() in JSON, so a query's cache key and its logged form
 * carry the coordinates.
 */
class GeoBounds implements \JsonSerializable
{
    private float $north;
    private float $south;
    private float $east;
    private float $west;

    public function __construct(float $north, float $south, float $east, float $west)
    {
        $this->setNorth($north);
        $this->setSouth($south);
        $this->setEast($east);
        $this->setWest($west);

        $this->validate();
    }

    public function getNorth(): float
    {
        return $this->north;
    }

    public function getSouth(): float
    {
        return $this->south;
    }

    public function getEast(): float
    {
        return $this->east;
    }

    public function getWest(): float
    {
        return $this->west;
    }

    public function setNorth(float $north): void
    {
        if ($north < -90 || $north > 90) {
            throw new InvalidArgumentException("North latitude must be between -90 and 90 degrees. Got: {$north}");
        }
        $this->north = $north;
    }

    public function setSouth(float $south): void
    {
        if ($south < -90 || $south > 90) {
            throw new InvalidArgumentException("South latitude must be between -90 and 90 degrees. Got: {$south}");
        }
        $this->south = $south;
    }

    public function setEast(float $east): void
    {
        if ($east < -180 || $east > 180) {
            throw new InvalidArgumentException("East longitude must be between -180 and 180 degrees. Got: {$east}");
        }
        $this->east = $east;
    }

    public function setWest(float $west): void
    {
        if ($west < -180 || $west > 180) {
            throw new InvalidArgumentException("West longitude must be between -180 and 180 degrees. Got: {$west}");
        }
        $this->west = $west;
    }

    private function validate(): void
    {
        if ($this->north < $this->south) {
            throw new InvalidArgumentException("North latitude ({$this->north}) must be greater than south latitude ({$this->south})");
        }
    }

    /**
     * Check if a point is within these bounds
     */
    public function contains(GeoPoint $point): bool
    {
        $lat = $point->getLatitude();
        $lng = $point->getLongitude();

        $latInBounds = $lat >= $this->south && $lat <= $this->north;

        // Handle date line crossing
        if ($this->west > $this->east) {
            // Bounds cross the date line
            $lngInBounds = $lng >= $this->west || $lng <= $this->east;
        } else {
            // Normal bounds
            $lngInBounds = $lng >= $this->west && $lng <= $this->east;
        }

        return $latInBounds && $lngInBounds;
    }

    /**
     * Check if these bounds intersect with another bounds
     */
    public function intersects(GeoBounds $other): bool
    {
        // Check latitude overlap
        if ($this->north < $other->getSouth() || $this->south > $other->getNorth()) {
            return false;
        }

        // A box that crosses the date line spans two ranges of longitude
        foreach ($this->longitudeRanges() as [$west, $east]) {
            foreach ($other->longitudeRanges() as [$otherWest, $otherEast]) {
                if ($west <= $otherEast && $otherWest <= $east) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The longitudes the box spans, as [west, east] ranges: one, or two when it
     * crosses the date line
     */
    private function longitudeRanges(): array
    {
        if ($this->crossesDateLine()) {
            return [[$this->west, 180.0], [-180.0, $this->east]];
        }

        return [[$this->west, $this->east]];
    }

    /**
     * Check if bounds cross the international date line
     */
    public function crossesDateLine(): bool
    {
        return $this->west > $this->east;
    }

    /**
     * Get the center point of the bounds
     */
    public function getCenter(): GeoPoint
    {
        $centerLat = ($this->north + $this->south) / 2;

        // Handle date line crossing for longitude
        if ($this->crossesDateLine()) {
            $centerLng = (($this->west - 360) + $this->east) / 2;
            if ($centerLng < -180) {
                $centerLng += 360;
            }
        } else {
            $centerLng = ($this->west + $this->east) / 2;
        }

        return new GeoPoint($centerLat, $centerLng);
    }

    /**
     * Expand bounds by a given distance in meters
     */
    public function expand(float $distanceInMeters): self
    {
        $center = $this->getCenter();
        $newBounds = $center->getBoundingBox($distanceInMeters);

        return new self(
            max($this->north, $newBounds->getNorth()),
            min($this->south, $newBounds->getSouth()),
            max($this->east, $newBounds->getEast()),
            min($this->west, $newBounds->getWest())
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toArray(): array
    {
        return [
            'north' => $this->north,
            'south' => $this->south,
            'east' => $this->east,
            'west' => $this->west
        ];
    }

    public static function fromArray(array $data): self
    {
        $required = ['north', 'south', 'east', 'west'];
        foreach ($required as $key) {
            if (!isset($data[$key])) {
                throw new InvalidArgumentException("Array must contain '{$key}' key");
            }
        }

        return new self($data['north'], $data['south'], $data['east'], $data['west']);
    }

    public function __toString(): string
    {
        return sprintf(
            "N:%.6f, S:%.6f, E:%.6f, W:%.6f",
            $this->north,
            $this->south,
            $this->east,
            $this->west
        );
    }
}
