<?php

require_once __DIR__ . '/../vendor/autoload.php';

use YetiSearch\YetiSearch;

class CacheBenchmark
{
    private YetiSearch $search;
    private string $indexName = 'test_cache_benchmark';
    private array $testQueries = [
        'search engine optimization',
        'database performance',
        'php programming',
        'web development',
        'machine learning',
        'artificial intelligence',
        'cloud computing',
        'data science',
        'software engineering',
        'mobile development'
    ];
    
    public function __construct()
    {
        // Initialize with cache enabled
        $this->search = new YetiSearch([
            'storage' => [
                'path' => __DIR__ . '/cache_benchmark.db',
                'external_content' => true
            ],
            'cache' => [
                'enabled' => true,
                'ttl' => 300,
                'max_size' => 1000
            ]
        ]);
    }
    
    public function setup(): void
    {
        echo "Setting up test index...\n";
        
        // Create index
        $indexer = $this->search->createIndex($this->indexName);
        
        // Generate test documents
        $documents = [];
        for ($i = 1; $i <= 1000; $i++) {
            $documents[] = [
                'id' => "doc_{$i}",
                'content' => $this->generateContent($i),
                'metadata' => ['category' => 'tech', 'author' => "Author {$i}"]
            ];
            
            if (count($documents) >= 100) {
                $indexer->indexBatch($documents);
                $documents = [];
            }
        }
        
        if (count($documents) > 0) {
            $indexer->indexBatch($documents);
        }
        
        $indexer->flush();
        echo "Created 1000 test documents\n";
    }
    
    private function generateContent(int $id): string
    {
        $topics = [
            'search engine optimization techniques',
            'database performance tuning strategies',
            'php programming best practices',
            'web development frameworks comparison',
            'machine learning algorithms explained',
            'artificial intelligence applications',
            'cloud computing services overview',
            'data science methodologies',
            'software engineering principles',
            'mobile development platforms'
        ];
        
        $content = $topics[$id % count($topics)];
        $content .= " Document number {$id}. ";
        $content .= "Lorem ipsum dolor sit amet, consectetur adipiscing elit. ";
        $content .= "This is additional content to make the document longer and more realistic.";
        
        return $content;
    }
    
    public function runBenchmark(): void
    {
        echo "\n=== Cache Performance Benchmark ===\n\n";
        
        // Clear cache first
        $this->search->clearCache();
        echo "Cache cleared\n\n";
        
        // Benchmark without cache (cold queries)
        echo "1. Cold Queries (no cache):\n";
        $coldResults = $this->benchmarkQueries(false);
        $this->printResults($coldResults);
        
        // Benchmark with cache (warm queries - second run)
        echo "\n2. Warm Queries (with cache):\n";
        $warmResults = $this->benchmarkQueries(false);
        $this->printResults($warmResults);
        
        // Show cache statistics
        echo "\n3. Cache Statistics:\n";
        $cacheStats = $this->search->getCacheStats();
        $this->printCacheStats($cacheStats);
        
        // Test cache invalidation
        echo "\n4. Cache Invalidation Test:\n";
        $this->testCacheInvalidation();
        
        // Performance comparison
        echo "\n5. Performance Summary:\n";
        $this->printComparison($coldResults, $warmResults);
    }
    
    private function benchmarkQueries(bool $bypassCache): array
    {
        $results = [];
        $totalTime = 0;
        
        foreach ($this->testQueries as $query) {
            $start = microtime(true);
            
            $searchResults = $this->search->search($this->indexName, $query, [
                'limit' => 20,
                'bypass_cache' => $bypassCache
            ]);
            
            $elapsed = (microtime(true) - $start) * 1000; // Convert to ms
            $totalTime += $elapsed;
            
            $results[] = [
                'query' => $query,
                'time_ms' => $elapsed,
                'hits' => count($searchResults['results'] ?? [])
            ];
        }
        
        return [
            'queries' => $results,
            'total_time_ms' => $totalTime,
            'avg_time_ms' => $totalTime / count($this->testQueries)
        ];
    }
    
    private function printResults(array $results): void
    {
        foreach ($results['queries'] as $result) {
            printf("  - '%s': %.2fms (%d hits)\n", 
                $result['query'], 
                $result['time_ms'],
                $result['hits']
            );
        }
        printf("  Total: %.2fms, Avg: %.2fms\n", 
            $results['total_time_ms'],
            $results['avg_time_ms']
        );
    }
    
    private function printCacheStats(array $stats): void
    {
        if (isset($stats['query_cache'])) {
            $qc = $stats['query_cache'];
            echo "  Query Cache:\n";
            echo "    - Hits: " . ($qc['hits'] ?? 0) . "\n";
            echo "    - Misses: " . ($qc['misses'] ?? 0) . "\n";
            echo "    - Hit Rate: " . ($qc['hit_rate'] ?? 0) . "%\n";
            echo "    - Cached Entries: " . ($qc['cache_entries'] ?? 0) . "\n";
        }
        
        if (isset($stats['statement_cache'])) {
            $sc = $stats['statement_cache'];
            echo "  Statement Cache:\n";
            echo "    - Cached Statements: " . ($sc['cached_statements'] ?? 0) . "\n";
            echo "    - Total Hits: " . ($sc['total_hits'] ?? 0) . "\n";
        }
        
        if (isset($stats['summary'])) {
            $summary = $stats['summary'];
            echo "  Overall:\n";
            echo "    - Total Cache Hits: " . $summary['total_cache_hits'] . "\n";
            echo "    - Total Cache Misses: " . $summary['total_cache_misses'] . "\n";
            echo "    - Cache Efficiency: " . $summary['cache_efficiency'] . "%\n";
        }
    }
    
    private function testCacheInvalidation(): void
    {
        // Perform a search to cache it
        $query = 'cache invalidation test';
        $this->search->search($this->indexName, $query, ['limit' => 10]);
        echo "  - Cached query: '{$query}'\n";
        
        // Add a new document (should invalidate cache)
        $this->search->indexDocument($this->indexName, 'new_doc', [
            'content' => 'This is a new document for cache invalidation test'
        ]);
        echo "  - Added new document (cache invalidated)\n";
        
        // Check if cache was invalidated
        $stats1 = $this->search->getCacheStats();
        $missesBefore = $stats1['query_cache']['misses'] ?? 0;
        
        // Search again (should be a cache miss)
        $this->search->search($this->indexName, $query, ['limit' => 10]);
        
        $stats2 = $this->search->getCacheStats();
        $missesAfter = $stats2['query_cache']['misses'] ?? 0;
        
        if ($missesAfter > $missesBefore) {
            echo "  ✓ Cache invalidation working correctly\n";
        } else {
            echo "  ✗ Cache invalidation may not be working\n";
        }
    }
    
    private function printComparison(array $cold, array $warm): void
    {
        $improvement = (($cold['avg_time_ms'] - $warm['avg_time_ms']) / $cold['avg_time_ms']) * 100;
        
        echo "  Cold Query Avg: " . sprintf("%.2fms", $cold['avg_time_ms']) . "\n";
        echo "  Warm Query Avg: " . sprintf("%.2fms", $warm['avg_time_ms']) . "\n";
        echo "  Performance Improvement: " . sprintf("%.1f%%", $improvement) . "\n";
        echo "  Speedup Factor: " . sprintf("%.2fx", $cold['avg_time_ms'] / $warm['avg_time_ms']) . "\n";
    }
    
    public function cleanup(): void
    {
        echo "\nCleaning up...\n";
        $this->search->dropIndex($this->indexName);
        $this->search->close();
        
        // Remove test database
        $dbPath = __DIR__ . '/cache_benchmark.db';
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }
        if (file_exists($dbPath . '-wal')) {
            unlink($dbPath . '-wal');
        }
        if (file_exists($dbPath . '-shm')) {
            unlink($dbPath . '-shm');
        }
    }
}

// Run the benchmark
$benchmark = new CacheBenchmark();

try {
    $benchmark->setup();
    $benchmark->runBenchmark();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
} finally {
    $benchmark->cleanup();
}

echo "\nBenchmark completed!\n";