<?php

namespace YetiSearch\Contracts;

interface IndexerInterface
{
    public function insert($documents): void;
    
    public function update(array $document): void;
    
    public function delete(string $id): void;
    
    public function clear(): void;
    
    public function rebuild(array $documents): void;
    
    public function optimize(): void;
    
    public function getStats(): array;
}