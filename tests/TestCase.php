<?php

namespace YetiSearch\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use YetiSearch\YetiSearch;
use YetiSearch\Storage\SqliteStorage;

abstract class TestCase extends BaseTestCase
{
    protected ?YetiSearch $search = null;
    protected array $createdIndexes = [];
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestEnvironment();
    }
    
    protected function tearDown(): void
    {
        // Clean up created indexes
        if ($this->search !== null) {
            foreach ($this->createdIndexes as $index) {
                try {
                    $this->search->dropIndex($index);
                } catch (\Exception $e) {
                    // Ignore errors during cleanup
                }
            }
        }
        
        $this->search = null;
        $this->createdIndexes = [];
        $this->cleanupTestEnvironment();
        
        parent::tearDown();
    }
    
    /**
     * Create a YetiSearch instance for testing
     */
    protected function createSearchInstance(array $config = []): YetiSearch
    {
        $defaultConfig = [
            'storage' => [
                'path' => $this->getTestDbPath(),
                // Tests expect legacy FTS schema (id column in FTS table)
                'external_content' => false
            ],
            'analyzer' => [
                'min_word_length' => 2,
                'strip_html' => true,
                'remove_stop_words' => true
            ],
            'search' => [
                'cache_enabled' => false, // Disable caching for tests
                // Ensure geo-only queries are not filtered by default in tests
                'min_score' => 0.0
            ]
        ];
        
        // Use a deep merge that preserves defaults but allows overrides
        $config = (function(array $base, array $overrides) {
            $merge = function(array $a, array $b) use (&$merge): array {
                foreach ($b as $k => $v) {
                    if (is_array($v) && isset($a[$k]) && is_array($a[$k])) {
                        // Replace numeric arrays; merge associative
                        $isAssoc = static function(array $arr): bool {
                            foreach (array_keys($arr) as $kk) { if (!is_int($kk)) return true; }
                            return false;
                        };
                        if ($isAssoc($a[$k]) || $isAssoc($v)) {
                            $a[$k] = $merge($a[$k], $v);
                        } else {
                            $a[$k] = $v;
                        }
                    } else {
                        $a[$k] = $v;
                    }
                }
                return $a;
            };
            return $merge($base, $overrides);
        })($defaultConfig, $config);
        $this->search = new YetiSearch($config);
        
        return $this->search;
    }
    
    /**
     * Create a test index and track it for cleanup
     */
    protected function createTestIndex(string $name): void
    {
        $this->search->createIndex($name);
        $this->createdIndexes[] = $name;
    }
    
    /**
     * Get a unique test database path
     */
    protected function getTestDbPath(): string
    {
        return getTestDbPath(uniqid('test_'));
    }
    
    /**
     * Index sample documents for testing
     */
    protected function indexSampleDocuments(string $indexName, int $count = 10): array
    {
        $documents = [];
        
        for ($i = 1; $i <= $count; $i++) {
            $doc = [
                'id' => "doc-{$i}",
                'content' => [
                    'title' => "Test Document {$i}",
                    'body' => "This is the body content for test document number {$i}. It contains searchable text.",
                    'tags' => ['test', 'document', "tag{$i}"]
                ],
                'metadata' => [
                    'author' => "Author {$i}",
                    'category' => $i % 2 === 0 ? 'even' : 'odd',
                    'score' => $i * 10,
                    'published' => $i % 3 === 0
                ],
                'type' => 'test',
                'language' => 'en'
            ];
            
            $documents[] = $doc;
            $this->search->index($indexName, $doc);
        }
        
        return $documents;
    }
    
    /**
     * Assert search results contain expected document IDs
     */
    protected function assertResultsContainIds(array $results, array $expectedIds): void
    {
        $actualIds = array_column($results['results'], 'id');
        sort($actualIds);
        sort($expectedIds);
        
        $this->assertEquals($expectedIds, $actualIds, 
            'Search results do not contain expected document IDs');
    }
    
    /**
     * Assert search results are in expected order
     */
    protected function assertResultsInOrder(array $results, array $expectedOrder): void
    {
        $actualIds = array_column($results['results'], 'id');
        
        $this->assertEquals($expectedOrder, $actualIds, 
            'Search results are not in expected order');
    }
    
    /**
     * Clean up test environment
     */
    protected function cleanupTestEnvironment(): void
    {
        // Clean test databases
        $dbFiles = glob(YETISEARCH_TEST_TMP . '/db/*.db');
        foreach ($dbFiles as $file) {
            @unlink($file);
        }
        
        // Clean cache files
        $cacheFiles = glob(YETISEARCH_TEST_TMP . '/cache/*');
        foreach ($cacheFiles as $file) {
            @unlink($file);
        }
    }
    
    /**
     * Measure execution time of a callable
     */
    protected function measureTime(callable $callback): array
    {
        $start = microtime(true);
        $result = $callback();
        $time = (microtime(true) - $start) * 1000; // Convert to milliseconds
        
        return [
            'result' => $result,
            'time' => $time
        ];
    }
    
    /**
     * Generate random text of specified length
     */
    protected function generateRandomText(int $words): string
    {
        $lorem = 'Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore et dolore magna aliqua';
        $wordList = explode(' ', $lorem);
        $text = [];
        
        for ($i = 0; $i < $words; $i++) {
            $text[] = $wordList[array_rand($wordList)];
        }
        
        return implode(' ', $text);
    }
    
    /**
     * Assert that an array has all the expected keys
     */
    protected function assertArrayHasKeys(array $keys, array $array, string $message = ''): void
    {
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $array, $message ?: "Array missing expected key: {$key}");
        }
    }
    
    /**
     * Create a storage instance directly for low-level testing
     */
    protected function createStorageInstance(): SqliteStorage
    {
        $storage = new SqliteStorage();
        $storage->connect(['path' => $this->getTestDbPath()]);
        
        return $storage;
    }
}
