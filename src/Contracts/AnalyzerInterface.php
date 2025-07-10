<?php

namespace YetiSearch\Contracts;

interface AnalyzerInterface
{
    public function analyze(string $text, ?string $language = null): array;
    
    public function tokenize(string $text): array;
    
    public function stem(string $word, ?string $language = null): string;
    
    public function removeStopWords(array $tokens, ?string $language = null): array;
    
    public function normalize(string $text): string;
    
    public function extractKeywords(string $text, int $limit = 10): array;
    
    public function getStopWords(string $language): array;
    
    public function setCustomStopWords(array $stopWords): void;
    
    public function addCustomStopWord(string $word): void;
    
    public function removeCustomStopWord(string $word): void;
    
    public function getCustomStopWords(): array;
    
    public function isStopWordsDisabled(): bool;
    
    public function setStopWordsDisabled(bool $disabled): void;
}