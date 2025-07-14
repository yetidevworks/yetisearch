<?php

declare(strict_types=1);

namespace YetiSearch\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;
use YetiSearch\Index\Indexer;
use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Analyzers\StandardAnalyzer;
use YetiSearch\Exceptions\IndexException;
use YetiSearch\Exceptions\InvalidArgumentException;
use YetiSearch\Geo\GeoPoint;

class IndexerTest extends TestCase
{
    private $storage;
    private $analyzer;
    private $indexer;
    
    protected function setUp(): void
    {
        $this->storage = $this->createMock(SqliteStorage::class);
        $this->analyzer = $this->createMock(StandardAnalyzer::class);
        
        $this->indexer = new Indexer(
            $this->storage,
            $this->analyzer,
            'test_index',
            [
                'chunk_size' => 1000,
                'chunk_overlap' => 100,
                'batch_size' => 10,
                'auto_flush' => true,
                'fields' => [
                    'title' => ['boost' => 3.0, 'store' => true],
                    'content' => ['boost' => 1.0, 'store' => true],
                    'author' => ['boost' => 1.5, 'store' => true],
                ]
            ]
        );
    }
    
    public function testIndexSingleDocument(): void
    {
        $document = [
            'id' => 'doc1',
            'content' => [
                'title' => 'Test Document',
                'content' => 'This is a test document',
                'author' => 'Test Author'
            ]
        ];
        
        $this->analyzer->expects($this->any())
            ->method('analyze')
            ->willReturnCallback(function($text) {
                return explode(' ', strtolower($text));
            });
        
        $this->storage->expects($this->once())
            ->method('insertBatch');
        
        // insert() returns void, not boolean
        $this->indexer->insert($document);
        
        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }
    
    public function testIndexWithGeoData(): void
    {
        $document = [
            'id' => 'geo1',
            'content' => [
                'title' => 'Geo Document',
                'content' => 'Document with location'
            ],
            'geo' => [
                'lat' => 37.7749,
                'lng' => -122.4194
            ]
        ];
        
        $this->analyzer->expects($this->any())
            ->method('analyze')
            ->willReturn(['geo', 'document']);
        
        $this->storage->expects($this->once())
            ->method('insertBatch');
        
        $this->indexer->insert($document);
        $this->assertTrue(true);
    }
    
    public function testIndexWithGeoBounds(): void
    {
        $document = [
            'id' => 'bounds1',
            'content' => [
                'title' => 'Area Document',
                'content' => 'Document with bounds'
            ],
            'geo_bounds' => [
                'north' => 37.8,
                'south' => 37.7,
                'east' => -122.3,
                'west' => -122.5
            ]
        ];
        
        $this->analyzer->expects($this->any())
            ->method('analyze')
            ->willReturn(['area', 'document']);
        
        $this->storage->expects($this->once())
            ->method('insertBatch');
        
        $this->indexer->insert($document);
        $this->assertTrue(true);
    }
    
    public function testIndexBatch(): void
    {
        $documents = [
            ['id' => 'doc1', 'content' => ['title' => 'Document 1']],
            ['id' => 'doc2', 'content' => ['title' => 'Document 2']],
            ['id' => 'doc3', 'content' => ['title' => 'Document 3']]
        ];
        
        $this->analyzer->expects($this->any())
            ->method('analyze')
            ->willReturn(['test']);
        
        $this->storage->expects($this->once())
            ->method('insertBatch')
            ->with($this->equalTo('test_index'), $this->anything());
        
        // insert() handles arrays too
        $this->indexer->insert($documents);
        $this->assertTrue(true);
    }
    
    public function testIndexBatchWithFailures(): void
    {
        $documents = [
            ['id' => 'doc1', 'content' => ['title' => 'Document 1']],
            ['not_array' => 'invalid'],  // This should be skipped
            ['id' => 'doc3', 'content' => ['title' => 'Document 3']]
        ];
        
        $this->analyzer->expects($this->any())
            ->method('analyze')
            ->willReturn(['test']);
        
        $this->storage->expects($this->any())
            ->method('insertBatch');
        
        // Should handle invalid documents gracefully
        $this->indexer->insert($documents);
        $this->assertTrue(true);
    }
    
    public function testChunking(): void
    {
        $longContent = str_repeat('This is a long content. ', 100); // ~2400 chars
        
        $document = [
            'id' => 'long1',
            'content' => [
                'title' => 'Long Document',
                'content' => $longContent
            ]
        ];
        
        $this->analyzer->expects($this->any())
            ->method('analyze')
            ->willReturn(['test']);
        
        // Chunking may result in multiple insert calls
        $this->storage->expects($this->atLeast(1))
            ->method('insertBatch');
        
        $this->indexer->insert($document);
        $this->assertTrue(true);
    }
    
    public function testUpdate(): void
    {
        $document = [
            'id' => 'doc1',
            'content' => [
                'title' => 'Updated Document',
                'content' => 'Updated content'
            ]
        ];
        
        $this->analyzer->expects($this->any())
            ->method('analyze')
            ->willReturn(['updated']);
        
        $this->storage->expects($this->once())
            ->method('update')
            ->with('test_index', 'doc1', $this->anything());
        
        // update() returns void
        $this->indexer->update($document);
        $this->assertTrue(true);
    }
    
    public function testDelete(): void
    {
        $this->storage->expects($this->once())
            ->method('delete')
            ->with('test_index', 'doc1');
        
        // delete() returns void
        $this->indexer->delete('doc1');
        $this->assertTrue(true);
    }
    
    public function testClear(): void
    {
        $this->storage->expects($this->once())
            ->method('dropIndex')
            ->with('test_index');
            
        $this->storage->expects($this->once())
            ->method('createIndex')
            ->with('test_index');
        
        // clear() returns void
        $this->indexer->clear();
        $this->assertTrue(true);
    }
    
    public function testOptimize(): void
    {
        $this->storage->expects($this->once())
            ->method('optimize')
            ->with('test_index');
        
        // optimize() returns void
        $this->indexer->optimize();
        $this->assertTrue(true);
    }
    
    public function testRebuild(): void
    {
        $documents = [
            ['id' => 'doc1', 'content' => ['title' => 'Document 1']],
            ['id' => 'doc2', 'content' => ['title' => 'Document 2']]
        ];
        
        $this->analyzer->expects($this->any())
            ->method('analyze')
            ->willReturn(['test']);
            
        $this->storage->expects($this->once())
            ->method('dropIndex');
            
        $this->storage->expects($this->once())
            ->method('createIndex');
            
        $this->storage->expects($this->any())
            ->method('insertBatch');
            
        $this->storage->expects($this->once())
            ->method('optimize');
        
        // rebuild() returns void
        $this->indexer->rebuild($documents);
        $this->assertTrue(true);
    }
    
    public function testGetStats(): void
    {
        $expectedStats = [
            'total_documents' => 100,
            'total_size' => 50000,
            'avg_document_size' => 500
        ];
        
        $this->storage->expects($this->once())
            ->method('getIndexStats')
            ->with('test_index')
            ->willReturn($expectedStats);
        
        $stats = $this->indexer->getStats();
        $this->assertEquals($expectedStats, $stats);
    }
    
    public function testFlush(): void
    {
        // Disable auto_flush to test manual flush
        $indexer = new Indexer(
            $this->storage,
            $this->analyzer,
            'test_index',
            ['batch_size' => 10, 'auto_flush' => false]
        );
        
        $this->analyzer->expects($this->any())
            ->method('analyze')
            ->willReturn(['test']);
        
        // Storage should receive insertBatch when we flush
        $this->storage->expects($this->once())
            ->method('insertBatch')
            ->with($this->equalTo('test_index'), $this->anything());
        
        // Add 5 documents
        for ($i = 1; $i <= 5; $i++) {
            $indexer->insert([
                'id' => "doc{$i}",
                'content' => ['title' => "Document {$i}"]
            ]);
        }
        
        // Manually flush
        $indexer->flush();
        $this->assertTrue(true);
    }
    
    public function testIndexWithInvalidId(): void
    {
        $this->analyzer->expects($this->any())
            ->method('analyze')
            ->willReturn(['test']);
            
        $this->storage->expects($this->once())
            ->method('insertBatch');
        
        // Documents without ID get a generated ID
        $this->indexer->insert(['content' => ['title' => 'No ID']]);
        $this->assertTrue(true);
    }
    
    public function testIndexWithEmptyId(): void
    {
        $this->analyzer->expects($this->any())
            ->method('analyze')
            ->willReturn(['test']);
            
        $this->storage->expects($this->once())
            ->method('insertBatch');
        
        // Empty ID gets replaced with generated ID
        $this->indexer->insert(['id' => '', 'content' => ['title' => 'Empty ID']]);
        $this->assertTrue(true);
    }
    
    public function testIndexWithoutContent(): void
    {
        $this->analyzer->expects($this->any())
            ->method('analyze')
            ->willReturn(['test']);
            
        $this->storage->expects($this->once())
            ->method('insertBatch');
        
        // Missing content field defaults to empty array
        $this->indexer->insert(['id' => 'doc1']);
        $this->assertTrue(true);
    }
    
    public function testIndexWithNonArrayContent(): void
    {
        $this->analyzer->expects($this->any())
            ->method('analyze')
            ->willReturn(['test']);
            
        $this->storage->expects($this->once())
            ->method('insertBatch');
        
        // Non-array content should still be processed
        $this->indexer->insert(['id' => 'doc1', 'content' => 'string content']);
        $this->assertTrue(true);
    }
    
    public function testProcessDocumentWithMetadata(): void
    {
        $document = [
            'id' => 'meta1',
            'content' => [
                'title' => 'Document with Metadata',
                'content' => 'Test content'
            ],
            'metadata' => [
                'views' => 100,
                'rating' => 4.5,
                'featured' => true
            ]
        ];
        
        $this->analyzer->expects($this->any())
            ->method('analyze')
            ->willReturn(['test']);
        
        $this->storage->expects($this->once())
            ->method('insertBatch');
        
        $this->indexer->insert($document);
        $this->assertTrue(true);
    }
}