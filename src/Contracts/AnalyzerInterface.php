<?php

namespace YetiSearch\Contracts;

interface AnalyzerInterface
{
    public function analyze(string $text, string $language = null): array;
    
    public function tokenize(string $text): array;
    
    public function stem(string $word, string $language = null): string;
    
    public function removeStopWords(array $tokens, string $language = null): array;
    
    public function normalize(string $text): string;
    
    public function extractKeywords(string $text, int $limit = 10): array;
    
    public function getStopWords(string $language): array;
}