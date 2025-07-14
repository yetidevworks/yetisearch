<?php

namespace YetiSearch\Contracts;

interface StorageInterface
{
    public function connect(array $config): void;
    
    public function disconnect(): void;
    
    public function createIndex(string $name, array $options = []): void;
    
    public function dropIndex(string $name): void;
    
    public function indexExists(string $name): bool;
    
    public function insert(string $index, array $document): void;
    
    public function insertBatch(string $index, array $documents): void;
    
    public function update(string $index, string $id, array $document): void;
    
    public function delete(string $index, string $id): void;
    
    public function search(string $index, array $query): array;
    
    public function count(string $index, array $query): int;
    
    public function getDocument(string $index, string $id): ?array;
    
    public function optimize(string $index): void;
    
    public function getIndexStats(string $index): array;
    
    public function listIndices(): array;
    
    public function searchMultiple(array $indices, array $query): array;
    
    public function ensureSpatialTableExists(string $name): void;
    
    public function getIndexedTerms(?string $indexName = null, int $minFrequency = 2, int $limit = 10000): array;
}