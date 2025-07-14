<?php

namespace YetiSearch\Storage;

/**
 * Cache for fuzzy term mappings to improve search performance
 */
class FuzzyTermCache
{
    private string $cacheFile;
    private array $cache = [];
    private bool $loaded = false;
    private int $maxCacheSize;
    
    public function __construct(string $indexName, string $storagePath, int $maxCacheSize = 10000)
    {
        $this->cacheFile = dirname($storagePath) . '/' . $indexName . '_fuzzy_cache.json';
        $this->maxCacheSize = $maxCacheSize;
    }
    
    /**
     * Load cache from disk
     */
    private function loadCache(): void
    {
        if ($this->loaded) {
            return;
        }
        
        if (file_exists($this->cacheFile)) {
            $data = json_decode(file_get_contents($this->cacheFile), true);
            if (is_array($data)) {
                $this->cache = $data;
            }
        }
        
        $this->loaded = true;
    }
    
    /**
     * Get fuzzy variations for a term
     */
    public function get(string $term): ?array
    {
        $this->loadCache();
        $key = strtolower($term);
        return $this->cache[$key] ?? null;
    }
    
    /**
     * Store fuzzy variations for a term
     */
    public function set(string $term, array $variations): void
    {
        $this->loadCache();
        $key = strtolower($term);
        
        // Limit cache size
        if (count($this->cache) >= $this->maxCacheSize && !isset($this->cache[$key])) {
            // Remove oldest entries (simple FIFO)
            $this->cache = array_slice($this->cache, -($this->maxCacheSize - 100), null, true);
        }
        
        $this->cache[$key] = $variations;
    }
    
    /**
     * Save cache to disk
     */
    public function save(): void
    {
        if (!$this->loaded || empty($this->cache)) {
            return;
        }
        
        file_put_contents($this->cacheFile, json_encode($this->cache, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Clear the cache
     */
    public function clear(): void
    {
        $this->cache = [];
        $this->loaded = true;
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }
}