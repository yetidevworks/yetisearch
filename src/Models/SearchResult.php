<?php

namespace YetiSearch\Models;

class SearchResult
{
    private string $id;
    private float $score;
    private array $document;
    private array $highlights = [];
    private array $metadata = [];
    private ?float $distance = null;
    
    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? '';
        $this->score = $data['score'] ?? 0.0;
        $this->document = $data['document'] ?? [];
        $this->highlights = $data['highlights'] ?? [];
        $this->metadata = $data['metadata'] ?? [];
        $this->distance = isset($data['distance']) ? (float)$data['distance'] : null;
    }
    
    public function getId(): string
    {
        return $this->id;
    }
    
    public function getScore(): float
    {
        return $this->score;
    }
    
    public function getDocument(): array
    {
        return $this->document;
    }
    
    public function get(string $field, $default = null)
    {
        return $this->document[$field] ?? $default;
    }
    
    public function getHighlights(): array
    {
        return $this->highlights;
    }
    
    public function getHighlight(string $field): ?string
    {
        return $this->highlights[$field] ?? null;
    }
    
    public function getMetadata(): array
    {
        return $this->metadata;
    }
    
    public function getMeta(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }
    
    public function getDistance(): ?float
    {
        return $this->distance;
    }
    
    public function hasDistance(): bool
    {
        return $this->distance !== null;
    }
    
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'score' => $this->score,
            'document' => $this->document,
            'highlights' => $this->highlights,
            'metadata' => $this->metadata
        ];
        
        if ($this->distance !== null) {
            $data['distance'] = $this->distance;
        }
        
        return $data;
    }
    
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }
}