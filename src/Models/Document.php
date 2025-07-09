<?php

namespace YetiSearch\Models;

use YetiSearch\Contracts\IndexableInterface;

class Document implements IndexableInterface
{
    private string $id;
    private array $content;
    private ?string $language;
    private array $metadata;
    private int $timestamp;
    private string $type;
    
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
    
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'language' => $this->language,
            'metadata' => $this->metadata,
            'timestamp' => $this->timestamp,
            'type' => $this->type
        ];
    }
    
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'] ?? uniqid(),
            $data['content'] ?? [],
            $data['language'] ?? null,
            $data['metadata'] ?? [],
            $data['timestamp'] ?? null,
            $data['type'] ?? 'default'
        );
    }
}