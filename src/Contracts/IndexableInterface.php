<?php

namespace YetiSearch\Contracts;

interface IndexableInterface
{
    public function getId(): string;
    
    public function getContent(): array;
    
    public function getLanguage(): ?string;
    
    public function getMetadata(): array;
    
    public function getTimestamp(): int;
    
    public function getType(): string;
}