<?php

declare(strict_types=1);

namespace YetiSearch\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use YetiSearch\Search\SearchEngine;
use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Analyzers\StandardAnalyzer;
use YetiSearch\Models\SearchQuery;
use YetiSearch\Models\SearchResults;
use YetiSearch\Exceptions\SearchException;
use Psr\Log\LoggerInterface;

class SearchEngineTest extends TestCase
{
    private $storage;
    private $analyzer;
    private $logger;
    private $searchEngine;
    
    protected function setUp(): void
    {
        $this->storage = $this->createMock(SqliteStorage::class);
        $this->analyzer = $this->createMock(StandardAnalyzer::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->searchEngine = new SearchEngine(
            $this->storage,
            $this->analyzer,
            'test_index',
            [
                'min_score' => 0.0,
                'highlight_tag' => '<mark>',
                'highlight_tag_close' => '</mark>',
                'snippet_length' => 150,
                'max_results' => 1000,
                'enable_fuzzy' => true,
                'enable_suggestions' => true,
                'cache_ttl' => 300
            ],
            $this->logger
        );
    }
    
    public function testSearchBasic(): void
    {
        $query = new SearchQuery('test query');
        
        $storageResults = [
            [
                'id' => 'doc1',
                'score' => 95.0,
                'document' => [
                    'title' => 'Test Document',
                    'content' => 'This is a test document with query terms',
                    'route' => '/test/doc1'
                ]
            ],
            [
                'id' => 'doc2',
                'score' => 85.0,
                'document' => [
                    'title' => 'Another Test',
                    'content' => 'Another document with test content',
                    'route' => '/test/doc2'
                ]
            ]
        ];
        
        $this->analyzer->expects($this->once())
            ->method('analyze')
            ->with('test query')
            ->willReturn(['test', 'query']);
        
        $this->storage->expects($this->once())
            ->method('search')
            ->with('test_index', $this->anything(), $this->anything())
            ->willReturn($storageResults);
        
        $results = $this->searchEngine->search($query);
        
        $this->assertInstanceOf(SearchResults::class, $results);
        $this->assertCount(2, $results);
        $this->assertEquals('doc1', $results[0]->getId());
        $this->assertEquals('doc2', $results[1]->getId());
    }
    
    
    public function testCount(): void
    {
        $query = new SearchQuery('test');
        
        $this->analyzer->expects($this->once())
            ->method('analyze')
            ->willReturn(['test']);
        
        $this->storage->expects($this->once())
            ->method('count')
            ->with('test_index', $this->anything(), $this->anything())
            ->willReturn(42);
        
        $count = $this->searchEngine->count($query);
        
        $this->assertEquals(42, $count);
    }
    
}