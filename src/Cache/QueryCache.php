<?php

namespace YetiSearch\Cache;

use YetiSearch\Exceptions\CacheException;

class QueryCache
{
    private ?\PDO $connection = null;
    private string $cacheTable;
    private int $defaultTtl;
    private int $maxCacheSize;
    private bool $enabled;
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0,
        'evictions' => 0,
        'errors' => 0
    ];

    public function __construct(\PDO $connection, array $config = [])
    {
        $this->connection = $connection;
        $this->cacheTable = $config['table_name'] ?? '_query_cache';
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $this->cacheTable)) {
            throw new CacheException(
                "Invalid cache table name '{$this->cacheTable}': must match /^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/"
            );
        }
        $this->defaultTtl = $config['ttl'] ?? 300; // 5 minutes default
        $this->maxCacheSize = $config['max_size'] ?? 1000;
        $this->enabled = $config['enabled'] ?? true;

        if ($this->enabled) {
            $this->initializeCacheTable();
        }
    }

    private function initializeCacheTable(): void
    {
        try {
            $sql = "
                CREATE TABLE IF NOT EXISTS {$this->cacheTable} (
                    cache_key TEXT PRIMARY KEY,
                    index_name TEXT NOT NULL,
                    query_hash TEXT NOT NULL,
                    result_data TEXT NOT NULL,
                    result_count INTEGER,
                    expires_at INTEGER NOT NULL,
                    created_at INTEGER DEFAULT (strftime('%s', 'now')),
                    hit_count INTEGER DEFAULT 0,
                    last_accessed INTEGER DEFAULT (strftime('%s', 'now'))
                )
            ";
            $this->connection->exec($sql);

            // Create indexes for efficient lookups and cleanup
            $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_{$this->cacheTable}_expires ON {$this->cacheTable}(expires_at)");
            $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_{$this->cacheTable}_index ON {$this->cacheTable}(index_name)");
            $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_{$this->cacheTable}_accessed ON {$this->cacheTable}(last_accessed)");
        } catch (\PDOException $e) {
            $this->stats['errors']++;
            throw new CacheException("Failed to initialize cache table: " . $e->getMessage());
        }
    }

    public function get(string $indexName, array $queryParams): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $key = $this->generateCacheKey($indexName, $queryParams);

        try {
            // Clean expired entries periodically (1% chance)
            if (mt_rand(1, 100) === 1) {
                $this->cleanExpired();
            }

            $sql = "
                SELECT result_data, expires_at 
                FROM {$this->cacheTable} 
                WHERE cache_key = ? AND expires_at > strftime('%s', 'now')
            ";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$key]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                // Update hit count and last accessed time
                $updateSql = "
                    UPDATE {$this->cacheTable} 
                    SET hit_count = hit_count + 1, 
                        last_accessed = strftime('%s', 'now') 
                    WHERE cache_key = ?
                ";
                $this->connection->prepare($updateSql)->execute([$key]);

                $this->stats['hits']++;
                return json_decode($row['result_data'], true);
            }

            $this->stats['misses']++;
            return null;
        } catch (\PDOException $e) {
            $this->stats['errors']++;
            // Log error but don't throw - cache should be transparent
            return null;
        }
    }

    public function set(string $indexName, array $queryParams, array $results, ?int $ttl = null): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $key = $this->generateCacheKey($indexName, $queryParams);
        $ttl = $ttl ?? $this->defaultTtl;

        try {
            // Check cache size and evict if necessary
            $this->enforceMaxSize();

            $sql = "
                INSERT OR REPLACE INTO {$this->cacheTable} 
                (cache_key, index_name, query_hash, result_data, result_count, expires_at)
                VALUES (?, ?, ?, ?, ?, strftime('%s', 'now') + ?)
            ";

            $stmt = $this->connection->prepare($sql);
            $success = $stmt->execute([
                $key,
                $indexName,
                $this->getQueryHash($queryParams),
                json_encode($results),
                count($results['results'] ?? []),
                $ttl
            ]);

            if ($success) {
                $this->stats['writes']++;
            }

            return $success;
        } catch (\PDOException $e) {
            $this->stats['errors']++;
            return false;
        }
    }

    public function invalidate(string $indexName): int
    {
        if (!$this->enabled) {
            return 0;
        }

        try {
            $sql = "DELETE FROM {$this->cacheTable} WHERE index_name = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$indexName]);

            $deleted = $stmt->rowCount();
            $this->stats['evictions'] += $deleted;

            return $deleted;
        } catch (\PDOException $e) {
            $this->stats['errors']++;
            return 0;
        }
    }

    public function invalidateByQuery(string $indexName, string $queryPattern): int
    {
        if (!$this->enabled) {
            return 0;
        }

        try {
            $sql = "DELETE FROM {$this->cacheTable} WHERE index_name = ? AND query_hash LIKE ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$indexName, '%' . $queryPattern . '%']);

            $deleted = $stmt->rowCount();
            $this->stats['evictions'] += $deleted;

            return $deleted;
        } catch (\PDOException $e) {
            $this->stats['errors']++;
            return 0;
        }
    }

    public function clear(): int
    {
        if (!$this->enabled) {
            return 0;
        }

        try {
            $sql = "DELETE FROM {$this->cacheTable}";
            $this->connection->exec($sql);

            $deleted = $this->connection->query("SELECT changes()")->fetchColumn();
            $this->stats['evictions'] += $deleted;

            return $deleted;
        } catch (\PDOException $e) {
            $this->stats['errors']++;
            return 0;
        }
    }

    private function cleanExpired(): int
    {
        try {
            $sql = "DELETE FROM {$this->cacheTable} WHERE expires_at <= strftime('%s', 'now')";
            $this->connection->exec($sql);

            return $this->connection->query("SELECT changes()")->fetchColumn();
        } catch (\PDOException $e) {
            $this->stats['errors']++;
            return 0;
        }
    }

    private function enforceMaxSize(): void
    {
        try {
            $sql = "SELECT COUNT(*) FROM {$this->cacheTable}";
            $count = (int)$this->connection->query($sql)->fetchColumn();

            if ($count >= $this->maxCacheSize) {
                // Evict least recently accessed entries (keep 80% of max size)
                $keepCount = (int)($this->maxCacheSize * 0.8);
                $evictSql = "
                    DELETE FROM {$this->cacheTable}
                    WHERE cache_key IN (
                        SELECT cache_key 
                        FROM {$this->cacheTable}
                        ORDER BY last_accessed ASC
                        LIMIT ?
                    )
                ";

                $stmt = $this->connection->prepare($evictSql);
                $stmt->execute([$count - $keepCount]);

                $this->stats['evictions'] += $stmt->rowCount();
            }
        } catch (\PDOException $e) {
            $this->stats['errors']++;
        }
    }

    private function generateCacheKey(string $indexName, array $queryParams): string
    {
        // Create a stable, unique key from query parameters
        $normalized = $this->normalizeQueryParams($queryParams);
        return $indexName . ':' . md5(json_encode($normalized));
    }

    private function getQueryHash(array $queryParams): string
    {
        $normalized = $this->normalizeQueryParams($queryParams);
        return md5(json_encode($normalized));
    }

    private function normalizeQueryParams(array $params): array
    {
        // Sort parameters for consistent key generation
        ksort($params);

        // Include only cache-relevant parameters
        $relevant = [
            'query', 'filters', 'limit', 'offset', 'sort',
            'language', 'geoFilters', 'field_weights', 'fields',
            'fuzzy', 'fuzziness', 'boost', 'unique_by_route'
        ];

        $normalized = [];
        foreach ($relevant as $key) {
            if (isset($params[$key])) {
                $normalized[$key] = $params[$key];
            }
        }

        return $normalized;
    }

    public function getStats(): array
    {
        if (!$this->enabled) {
            return ['enabled' => false];
        }

        try {
            $sql = "
                SELECT 
                    COUNT(*) as total_entries,
                    SUM(hit_count) as total_hits,
                    AVG(hit_count) as avg_hits_per_entry,
                    SUM(result_count) as total_cached_results,
                    MIN(created_at) as oldest_entry,
                    MAX(created_at) as newest_entry,
                    AVG(strftime('%s', 'now') - created_at) as avg_age_seconds
                FROM {$this->cacheTable}
            ";

            $dbStats = $this->connection->query($sql)->fetch(\PDO::FETCH_ASSOC) ?: [];

            return array_merge($this->stats, [
                'enabled' => true,
                'cache_entries' => (int)($dbStats['total_entries'] ?? 0),
                'total_hits_db' => (int)($dbStats['total_hits'] ?? 0),
                'avg_hits_per_entry' => (float)($dbStats['avg_hits_per_entry'] ?? 0),
                'total_cached_results' => (int)($dbStats['total_cached_results'] ?? 0),
                'oldest_entry' => $dbStats['oldest_entry'],
                'newest_entry' => $dbStats['newest_entry'],
                'avg_age_seconds' => (float)($dbStats['avg_age_seconds'] ?? 0),
                'hit_rate' => $this->calculateHitRate(),
                'config' => [
                    'ttl' => $this->defaultTtl,
                    'max_size' => $this->maxCacheSize,
                    'table' => $this->cacheTable
                ]
            ]);
        } catch (\PDOException $e) {
            $this->stats['errors']++;
            return array_merge($this->stats, ['enabled' => true, 'error' => $e->getMessage()]);
        }
    }

    private function calculateHitRate(): float
    {
        $hits = is_numeric($this->stats['hits']) ? (float)$this->stats['hits'] : 0.0;
        $misses = is_numeric($this->stats['misses']) ? (float)$this->stats['misses'] : 0.0;
        $total = $hits + $misses;
        return $total > 0 ? round($hits / $total * 100, 2) : 0.0;
    }

    public function warmUp(string $indexName, array $popularQueries): int
    {
        if (!$this->enabled) {
            return 0;
        }

        $warmed = 0;

        foreach ($popularQueries as $queryParams) {
            if (!$this->get($indexName, $queryParams)) {
                // Cache miss - this would normally trigger a real search
                // The actual warming would be done by the search engine
                $warmed++;
            }
        }

        return $warmed;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
