<?php

namespace YetiSearch\Storage;

class PreparedStatementCache
{
    private array $statements = [];
    private array $hitCount = [];
    private int $maxSize;

    public function __construct(int $maxSize = 50)
    {
        $this->maxSize = $maxSize;
    }

    public function get(string $key): ?\PDOStatement
    {
        if (isset($this->statements[$key])) {
            $this->hitCount[$key] = ($this->hitCount[$key] ?? 0) + 1;
            return $this->statements[$key];
        }
        return null;
    }

    public function set(string $key, \PDOStatement $statement): void
    {
        // Check if we need to evict
        if (count($this->statements) >= $this->maxSize && !isset($this->statements[$key])) {
            // Evict least recently used (lowest hit count)
            $minHits = PHP_INT_MAX;
            $evictKey = null;

            foreach ($this->hitCount as $k => $hits) {
                if ($hits < $minHits) {
                    $minHits = $hits;
                    $evictKey = $k;
                }
            }

            if ($evictKey !== null) {
                unset($this->statements[$evictKey]);
                unset($this->hitCount[$evictKey]);
            }
        }

        $this->statements[$key] = $statement;
        $this->hitCount[$key] = 0;
    }

    public function clear(): void
    {
        $this->statements = [];
        $this->hitCount = [];
    }

    public function getStats(): array
    {
        return [
            'cached_statements' => count($this->statements),
            'max_size' => $this->maxSize,
            'hit_counts' => $this->hitCount,
            'total_hits' => array_sum($this->hitCount)
        ];
    }
}
