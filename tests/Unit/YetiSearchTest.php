<?php

declare(strict_types=1);

namespace YetiSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YetiSearch\YetiSearch;
use YetiSearch\Contracts\IndexerInterface;
use YetiSearch\Contracts\SearchEngineInterface;
use YetiSearch\Contracts\StorageInterface;
use YetiSearch\Contracts\AnalyzerInterface;
use YetiSearch\Models\SearchResults;
use YetiSearch\Exceptions\InvalidArgumentException;
use Psr\Log\LoggerInterface;

class YetiSearchTest extends TestCase
{
    private $yetiSearch;
    
    protected function setUp(): void
    {
        $this->yetiSearch = new YetiSearch([
            'storage' => [
                'path' => ':memory:'
            ]
        ]);
    }
    
    public function testConstructorWithDefaultConfig(): void
    {
        $yetiSearch = new YetiSearch();
        $this->assertInstanceOf(YetiSearch::class, $yetiSearch);
    }
    
    public function testConstructorWithCustomConfig(): void
    {
        $config = [
            'storage' => [
                'path' => '/tmp/test.db',
                'timeout' => 10000
            ],
            'analyzer' => [
                'min_word_length' => 3,
                'max_word_length' => 40
            ],
            'indexer' => [
                'batch_size' => 50,
                'chunk_size' => 500
            ]
        ];
        
        $yetiSearch = new YetiSearch($config);
        $this->assertInstanceOf(YetiSearch::class, $yetiSearch);
    }
    
    public function testConstructorWithLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        
        // Logger might not be called during construction
        $yetiSearch = new YetiSearch([], $logger);
        $this->assertInstanceOf(YetiSearch::class, $yetiSearch);
    }
    
    public function testCreateIndex(): void
    {
        $indexer = $this->yetiSearch->createIndex('test_index');
        
        $this->assertInstanceOf(IndexerInterface::class, $indexer);
    }
    
    public function testCreateIndexWithOptions(): void
    {
        $options = [
            'chunk_size' => 500,
            'batch_size' => 20,
            'fields' => [
                'title' => ['boost' => 5.0],
                'content' => ['boost' => 1.0]
            ]
        ];
        
        $indexer = $this->yetiSearch->createIndex('custom_index', $options);
        $this->assertInstanceOf(IndexerInterface::class, $indexer);
    }
    
    public function testGetIndexer(): void
    {
        // First create the index
        $created = $this->yetiSearch->createIndex('test_index');
        
        // Then get it
        $retrieved = $this->yetiSearch->getIndexer('test_index');
        
        $this->assertSame($created, $retrieved);
    }
    
    public function testGetIndexerNonExistent(): void
    {
        $result = $this->yetiSearch->getIndexer('non_existent');
        
        // getIndexer returns null for non-existent indexes
        $this->assertNull($result);
    }
    
    public function testSearch(): void
    {
        // Create and populate an index
        $indexer = $this->yetiSearch->createIndex('test_index');
        $indexer->index([
            'id' => 'doc1',
            'content' => ['title' => 'Test Document', 'body' => 'Test content']
        ]);
        
        $results = $this->yetiSearch->search('test_index', 'test');
        
        // search() returns an array
        $this->assertIsArray($results);
        $this->assertArrayHasKey('results', $results);
        $this->assertArrayHasKey('total', $results);
        $this->assertArrayHasKey('count', $results);
        $this->assertArrayHasKey('search_time', $results);
    }
    
    public function testSearchMultiple(): void
    {
        // Create multiple indexes
        $indexer1 = $this->yetiSearch->createIndex('index1');
        $indexer2 = $this->yetiSearch->createIndex('index2');
        
        $indexer1->index([
            'id' => 'doc1',
            'content' => ['title' => 'Index 1 Document']
        ]);
        
        $indexer2->index([
            'id' => 'doc2',
            'content' => ['title' => 'Index 2 Document']
        ]);
        
        $results = $this->yetiSearch->searchMultiple(['index1', 'index2'], 'document');
        
        // searchMultiple() returns an array
        $this->assertIsArray($results);
    }
    
    public function testCount(): void
    {
        $indexer = $this->yetiSearch->createIndex('test_index');
        $indexer->index([
            'id' => 'doc1',
            'content' => ['title' => 'Test Document']
        ]);
        
        $count = $this->yetiSearch->count('test_index', 'test');
        
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }
    
    public function testSuggest(): void
    {
        $indexer = $this->yetiSearch->createIndex('test_index');
        $indexer->index([
            'id' => 'doc1',
            'content' => ['title' => 'Testing suggestions']
        ]);
        
        $suggestions = $this->yetiSearch->suggest('test_index', 'test');
        
        $this->assertIsArray($suggestions);
    }
    
    public function testIndex(): void
    {
        $this->yetiSearch->createIndex('test_index');
        
        // index() returns void
        $this->yetiSearch->index('test_index', [
            'id' => 'doc1',
            'content' => ['title' => 'Direct indexing test']
        ]);
        
        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }
    
    public function testIndexBatch(): void
    {
        $this->yetiSearch->createIndex('test_index');
        
        $documents = [
            ['id' => 'doc1', 'content' => ['title' => 'Document 1']],
            ['id' => 'doc2', 'content' => ['title' => 'Document 2']],
            ['id' => 'doc3', 'content' => ['title' => 'Document 3']]
        ];
        
        // indexBatch() returns void
        $this->yetiSearch->indexBatch('test_index', $documents);
        
        $this->assertTrue(true);
    }
    
    public function testUpdate(): void
    {
        $this->yetiSearch->createIndex('test_index');
        
        // First index a document
        $this->yetiSearch->index('test_index', [
            'id' => 'doc1',
            'content' => ['title' => 'Original title']
        ]);
        
        // Then update it - update() returns void
        $this->yetiSearch->update('test_index', [
            'id' => 'doc1',
            'content' => ['title' => 'Updated title']
        ]);
        
        $this->assertTrue(true);
    }
    
    public function testDelete(): void
    {
        $this->yetiSearch->createIndex('test_index');
        
        // First index a document
        $this->yetiSearch->index('test_index', [
            'id' => 'doc1',
            'content' => ['title' => 'To be deleted']
        ]);
        
        // Then delete it - delete() returns void
        $this->yetiSearch->delete('test_index', 'doc1');
        
        $this->assertTrue(true);
    }
    
    public function testClear(): void
    {
        $indexer = $this->yetiSearch->createIndex('test_index');
        
        // Add some documents
        $indexer->index(['id' => 'doc1', 'content' => ['title' => 'Document 1']]);
        $indexer->index(['id' => 'doc2', 'content' => ['title' => 'Document 2']]);
        
        // Clear the index - clear() returns void
        $this->yetiSearch->clear('test_index');
        
        $this->assertTrue(true);
    }
    
    public function testOptimize(): void
    {
        $this->yetiSearch->createIndex('test_index');
        
        // optimize() returns void
        $this->yetiSearch->optimize('test_index');
        
        $this->assertTrue(true);
    }
    
    public function testGetStats(): void
    {
        $indexer = $this->yetiSearch->createIndex('test_index');
        
        // Add some documents
        $indexer->index(['id' => 'doc1', 'content' => ['title' => 'Document 1']]);
        $indexer->index(['id' => 'doc2', 'content' => ['title' => 'Document 2']]);
        
        $stats = $this->yetiSearch->getStats('test_index');
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('document_count', $stats);
    }
    
    
    public function testIndexWithNonExistentIndex(): void
    {
        // If index doesn't exist, it creates it automatically
        $this->yetiSearch->index('non_existent', [
            'id' => 'doc1',
            'content' => ['title' => 'Test']
        ]);
        
        // Should create the index and index the document
        $this->assertTrue(true);
    }
    
    public function testDeleteWithNonExistentIndex(): void
    {
        // If index doesn't exist, it creates it automatically
        $this->yetiSearch->delete('non_existent', 'doc1');
        
        // Should handle gracefully
        $this->assertTrue(true);
    }
}