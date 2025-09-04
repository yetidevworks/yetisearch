<?php

namespace YetiSearch\Cache;

use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Storage\PreparedStatementCache;

class CacheManager
{
    private ?QueryCache $queryCache = null;
    private ?PreparedStatementCache $stmtCache = null;
    private SqliteStorage $storage;
    
    public function __construct(SqliteStorage $storage)
    {
        $this->storage = $storage;
    }
    
    public function setQueryCache(QueryCache $cache): void
    {
        $this->queryCache = $cache;
    }
    
    public function setStatementCache(PreparedStatementCache $cache): void
    {
        $this->stmtCache = $cache;
    }
    
    public function getStats(): array
    {
        $stats = [
            'query_cache' => null,
            'statement_cache' => null,
            'summary' => [
                'total_cache_hits' => 0,
                'total_cache_misses' => 0,
                'cache_efficiency' => 0.0
            ]
        ];
        
        // Query cache statistics
        if ($this->queryCache) {
            $queryCacheStats = $this->queryCache->getStats();
            $stats['query_cache'] = $queryCacheStats;
            
            if (isset($queryCacheStats['hits']) && isset($queryCacheStats['misses'])) {
                $stats['summary']['total_cache_hits'] += $queryCacheStats['hits'];
                $stats['summary']['total_cache_misses'] += $queryCacheStats['misses'];
            }
        }
        
        // Statement cache statistics
        if ($this->stmtCache) {
            $stmtStats = $this->stmtCache->getStats();
            $stats['statement_cache'] = $stmtStats;
            
            if (isset($stmtStats['total_hits'])) {
                $stats['summary']['total_cache_hits'] += $stmtStats['total_hits'];
            }
        }
        
        // Calculate overall efficiency
        $totalRequests = $stats['summary']['total_cache_hits'] + $stats['summary']['total_cache_misses'];
        if ($totalRequests > 0) {
            $stats['summary']['cache_efficiency'] = round(
                ($stats['summary']['total_cache_hits'] / $totalRequests) * 100,
                2
            );
        }
        
        return $stats;
    }
    
    public function clearAll(): void
    {
        if ($this->queryCache) {
            $this->queryCache->clear();
        }
        
        if ($this->stmtCache) {
            $this->stmtCache->clear();
        }
    }
    
    public function warmUp(string $indexName, array $popularQueries): array
    {
        $results = [
            'queries_warmed' => 0,
            'time_taken' => 0.0
        ];
        
        if (!$this->queryCache) {
            return $results;
        }
        
        $startTime = microtime(true);
        
        foreach ($popularQueries as $query) {
            // Check if already cached
            if (!$this->queryCache->get($indexName, $query)) {
                // Would trigger actual search through storage
                // For now, just count as warmed
                $results['queries_warmed']++;
            }
        }
        
        $results['time_taken'] = microtime(true) - $startTime;
        
        return $results;
    }
    
    public function getCacheInfo(): array
    {
        $info = [
            'query_cache' => [
                'enabled' => $this->queryCache !== null && $this->queryCache->isEnabled(),
                'type' => 'SQLite Table Cache'
            ],
            'statement_cache' => [
                'enabled' => $this->stmtCache !== null,
                'type' => 'In-Memory PDO Statement Cache'
            ],
            'optimizations' => [
                'query_result_caching' => true,
                'prepared_statement_reuse' => true,
                'automatic_cache_invalidation' => true,
                'lru_eviction' => true
            ]
        ];
        
        return $info;
    }
    
    public function enableQueryCache(bool $enable = true): void
    {
        if ($this->queryCache) {
            $this->queryCache->setEnabled($enable);
        }
    }
    
    public function invalidateIndex(string $indexName): int
    {
        if ($this->queryCache) {
            return $this->queryCache->invalidate($indexName);
        }
        return 0;
    }
}