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
            ->method('insert')
            ->with(
                $this->equalTo('test_index'),
                $this->callback(function($docs) {
                    return count($docs) === 1 && 
                           $docs[0]['id'] === 'doc1_chunk_0' &&
                           isset($docs[0]['content']['title']);
                })
            );
        
        $result = $this->indexer->index($document);
        $this->assertTrue($result);
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
            ->method('insert')
            ->with(
                $this->equalTo('test_index'),
                $this->callback(function($docs) {
                    return isset($docs[0]['geo']) &&
                           $docs[0]['geo']['lat'] === 37.7749 &&
                           $docs[0]['geo']['lng'] === -122.4194;
                })
            );
        
        $result = $this->indexer->index($document);
        $this->assertTrue($result);
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
            ->method('insert')
            ->with(
                $this->equalTo('test_index'),
                $this->callback(function($docs) {
                    return isset($docs[0]['geo_bounds']) &&
                           $docs[0]['geo_bounds']['north'] === 37.8;
                })
            );
        
        $result = $this->indexer->index($document);
        $this->assertTrue($result);
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
            ->method('insert')
            ->with(
                $this->equalTo('test_index'),
                $this->callback(function($docs) {
                    return count($docs) === 3;
                })
            );
        
        $result = $this->indexer->indexBatch($documents);
        $this->assertEquals(3, $result);
    }
    
    public function testIndexBatchWithFailures(): void
    {
        $documents = [
            ['id' => 'doc1', 'content' => ['title' => 'Document 1']],
            ['id' => '', 'content' => ['title' => 'Invalid Document']],  // Invalid
            ['id' => 'doc3', 'content' => ['title' => 'Document 3']]
        ];
        
        $this->analyzer->expects($this->any())
            ->method('analyze')
            ->willReturn(['test']);
        
        $this->storage->expects($this->once())
            ->method('insert')
            ->with(
                $this->equalTo('test_index'),
                $this->callback(function($docs) {
                    return count($docs) === 2; // Only valid documents
                })
            );
        
        $result = $this->indexer->indexBatch($documents);
        $this->assertEquals(2, $result);
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
        
        $this->storage->expects($this->once())
            ->method('insert')
            ->with(
                $this->equalTo('test_index'),
                $this->callback(function($docs) {
                    // Should create 3 chunks with 1000 char size
                    return count($docs) === 3 &&
                           $docs[0]['id'] === 'long1_chunk_0' &&
                           $docs[1]['id'] === 'long1_chunk_1' &&
                           $docs[2]['id'] === 'long1_chunk_2';
                })
            );
        
        $result = $this->indexer->index($document);
        $this->assertTrue($result);
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
            ->method('delete')
            ->with('test_index', 'doc1');
        
        $this->storage->expects($this->once())
            ->method('insert')
            ->with(
                $this->equalTo('test_index'),
                $this->callback(function($docs) {
                    return $docs[0]['content']['title'] === 'Updated Document';
                })
            );
        
        $result = $this->indexer->update($document);
        $this->assertTrue($result);
    }
    
    public function testDelete(): void
    {
        $this->storage->expects($this->once())
            ->method('delete')
            ->with('test_index', 'doc1')
            ->willReturn(true);
        
        $result = $this->indexer->delete('doc1');
        $this->assertTrue($result);
    }
    
    public function testClear(): void
    {
        $this->storage->expects($this->once())
            ->method('clear')
            ->with('test_index')
            ->willReturn(true);
        
        $result = $this->indexer->clear();
        $this->assertTrue($result);
    }
    
    public function testOptimize(): void
    {
        $this->storage->expects($this->once())
            ->method('optimize')
            ->with('test_index')
            ->willReturn(true);
        
        $result = $this->indexer->optimize();
        $this->assertTrue($result);
    }
    
    public function testRebuild(): void
    {
        $this->storage->expects($this->once())
            ->method('rebuild')
            ->with('test_index')
            ->willReturn(true);
        
        $result = $this->indexer->rebuild();
        $this->assertTrue($result);
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
        // Add documents to queue
        $documents = [];
        for ($i = 1; $i <= 5; $i++) {
            $documents[] = [
                'id' => "doc{$i}",
                'content' => ['title' => "Document {$i}"]
            ];
        }
        
        $this->analyzer->expects($this->any())
            ->method('analyze')
            ->willReturn(['test']);
        
        // First 5 documents should not trigger insert (batch_size is 10)
        $this->storage->expects($this->never())
            ->method('insert');
        
        // Disable auto_flush temporarily
        $indexer = new Indexer(
            $this->storage,
            $this->analyzer,
            'test_index',
            ['batch_size' => 10, 'auto_flush' => false]
        );
        
        foreach ($documents as $doc) {
            $indexer->index($doc);
        }
        
        // Now flush should trigger insert
        $this->storage->expects($this->once())
            ->method('insert')
            ->with(
                $this->equalTo('test_index'),
                $this->callback(function($docs) {
                    return count($docs) === 5;
                })
            );
        
        $indexer->flush();
    }
    
    public function testIndexWithInvalidId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Document must have a non-empty id field');
        
        $this->indexer->index(['content' => ['title' => 'No ID']]);
    }
    
    public function testIndexWithEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Document must have a non-empty id field');
        
        $this->indexer->index(['id' => '', 'content' => ['title' => 'Empty ID']]);
    }
    
    public function testIndexWithoutContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Document must have a content field');
        
        $this->indexer->index(['id' => 'doc1']);
    }
    
    public function testIndexWithNonArrayContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Document content must be an array');
        
        $this->indexer->index(['id' => 'doc1', 'content' => 'string content']);
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
            ->method('insert')
            ->with(
                $this->equalTo('test_index'),
                $this->callback(function($docs) {
                    return isset($docs[0]['metadata']) &&
                           $docs[0]['metadata']['views'] === 100 &&
                           $docs[0]['metadata']['rating'] === 4.5 &&
                           $docs[0]['metadata']['featured'] === true;
                })
            );
        
        $result = $this->indexer->index($document);
        $this->assertTrue($result);
    }
}