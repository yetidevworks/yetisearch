<?php

namespace YetiSearch\Models;

use YetiSearch\Contracts\IndexableInterface;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Geo\GeoBounds;

class Document implements IndexableInterface
{
    private string $id;
    private array $content;
    private ?string $language;
    private array $metadata;
    private int $timestamp;
    private string $type;
    private ?GeoPoint $geoPoint = null;
    private ?GeoBounds $geoBounds = null;
    
    public function __construct(
        string $id,
        array $content,
        ?string $language = null,
        array $metadata = [],
        ?int $timestamp = null,
        string $type = 'default'
    ) {
        $this->id = $id;
        $this->content = $content;
        $this->language = $language;
        $this->metadata = $metadata;
        $this->timestamp = $timestamp ?? time();
        $this->type = $type;
    }
    
    public function getId(): string
    {
        return $this->id;
    }
    
    public function getContent(): array
    {
        return $this->content;
    }
    
    public function getLanguage(): ?string
    {
        return $this->language;
    }
    
    public function getMetadata(): array
    {
        return $this->metadata;
    }
    
    public function getTimestamp(): int
    {
        return $this->timestamp;
    }
    
    public function getType(): string
    {
        return $this->type;
    }
    
    public function setContent(array $content): void
    {
        $this->content = $content;
    }
    
    public function setLanguage(?string $language): void
    {
        $this->language = $language;
    }
    
    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }
    
    public function addMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }
    
    public function setTimestamp(int $timestamp): void
    {
        $this->timestamp = $timestamp;
    }
    
    public function setType(string $type): void
    {
        $this->type = $type;
    }
    
    public function getGeoPoint(): ?GeoPoint
    {
        return $this->geoPoint;
    }
    
    public function setGeoPoint(?GeoPoint $geoPoint): void
    {
        $this->geoPoint = $geoPoint;
    }
    
    public function getGeoBounds(): ?GeoBounds
    {
        return $this->geoBounds;
    }
    
    public function setGeoBounds(?GeoBounds $geoBounds): void
    {
        $this->geoBounds = $geoBounds;
    }
    
    public function hasGeoData(): bool
    {
        return $this->geoPoint !== null || $this->geoBounds !== null;
    }
    
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'content' => $this->content,
            'language' => $this->language,
            'metadata' => $this->metadata,
            'timestamp' => $this->timestamp,
            'type' => $this->type
        ];
        
        if ($this->geoPoint) {
            $data['geo'] = $this->geoPoint->toArray();
        }
        
        if ($this->geoBounds) {
            $data['geo_bounds'] = $this->geoBounds->toArray();
        }
        
        return $data;
    }
    
    public static function fromArray(array $data): self
    {
        $document = new self(
            $data['id'] ?? uniqid(),
            $data['content'] ?? [],
            $data['language'] ?? null,
            $data['metadata'] ?? [],
            $data['timestamp'] ?? null,
            $data['type'] ?? 'default'
        );
        
        // Handle geo data
        if (isset($data['geo'])) {
            $document->setGeoPoint(GeoPoint::fromArray($data['geo']));
        }
        
        if (isset($data['geo_bounds'])) {
            $document->setGeoBounds(GeoBounds::fromArray($data['geo_bounds']));
        }
        
        return $document;
    }
}