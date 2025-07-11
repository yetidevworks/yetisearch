<?php

declare(strict_types=1);

namespace YetiSearch\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use YetiSearch\Models\SearchResult;

class SearchResultTest extends TestCase
{
    public function testConstructorWithAllFields(): void
    {
        $data = [
            'id' => 'doc1',
            'score' => 95.5,
            'document' => [
                'title' => 'Test Document',
                'content' => 'This is test content',
                'excerpt' => 'This is test...',
                'url' => '/test/doc1',
                'route' => 'test.doc1',
                'author' => 'Test Author',
                'tags' => 'test, document',
                'category' => 'Testing',
                '_index' => 'test_index'
            ],
            'metadata' => ['views' => 100],
            'distance' => 1500.5
        ];
        
        $result = new SearchResult($data);
        
        $this->assertEquals('doc1', $result->getId());
        $this->assertEquals(95.5, $result->getScore());
        $this->assertEquals('Test Document', $result->get('title'));
        $this->assertEquals('This is test content', $result->get('content'));
        $this->assertEquals('This is test...', $result->get('excerpt'));
        $this->assertEquals('/test/doc1', $result->get('url'));
        $this->assertEquals('test.doc1', $result->get('route'));
        $this->assertEquals('Test Author', $result->get('author'));
        $this->assertEquals('test, document', $result->get('tags'));
        $this->assertEquals('Testing', $result->get('category'));
        $this->assertEquals(['views' => 100], $result->getMetadata());
        $this->assertEquals('test_index', $result->get('_index'));
        $this->assertEquals(1500.5, $result->getDistance());
    }
    
    public function testConstructorWithMinimalFields(): void
    {
        $data = [
            'id' => 'doc1',
            'score' => 50.0
        ];
        
        $result = new SearchResult($data);
        
        $this->assertEquals('doc1', $result->getId());
        $this->assertEquals(50.0, $result->getScore());
        $this->assertNull($result->get('title'));
        $this->assertNull($result->get('content'));
        $this->assertNull($result->get('excerpt'));
        $this->assertNull($result->get('url'));
        $this->assertNull($result->get('route'));
        $this->assertNull($result->get('author'));
        $this->assertNull($result->get('tags'));
        $this->assertNull($result->get('category'));
        $this->assertEquals([], $result->getMetadata());
        $this->assertNull($result->get('_index'));
        $this->assertNull($result->getDistance());
    }
    
    public function testHasDistance(): void
    {
        $resultWithDistance = new SearchResult([
            'id' => 'doc1',
            'score' => 50.0,
            'distance' => 1000.0
        ]);
        
        $resultWithoutDistance = new SearchResult([
            'id' => 'doc2',
            'score' => 50.0
        ]);
        
        $this->assertTrue($resultWithDistance->hasDistance());
        $this->assertFalse($resultWithoutDistance->hasDistance());
    }
    
    public function testGet(): void
    {
        $data = [
            'id' => 'doc1',
            'score' => 95.5,
            'document' => [
                'title' => 'Test Document',
                'custom_field' => 'Custom Value'
            ]
        ];
        
        $result = new SearchResult($data);
        
        $this->assertEquals('Test Document', $result->get('title'));
        $this->assertEquals('Custom Value', $result->get('custom_field'));
        $this->assertNull($result->get('non_existent'));
        $this->assertEquals('default', $result->get('non_existent', 'default'));
    }
    
    public function testToArray(): void
    {
        $data = [
            'id' => 'doc1',
            'score' => 95.5,
            'document' => [
                'title' => 'Test Document',
                'content' => 'Test content'
            ],
            'metadata' => ['views' => 100]
        ];
        
        $result = new SearchResult($data);
        $array = $result->toArray();
        
        $this->assertIsArray($array);
        $this->assertEquals('doc1', $array['id']);
        $this->assertEquals(95.5, $array['score']);
        $this->assertEquals(['title' => 'Test Document', 'content' => 'Test content'], $array['document']);
        $this->assertEquals(['views' => 100], $array['metadata']);
        
        // Check that null values are not included
        $this->assertArrayNotHasKey('distance', $array);
    }
    
    public function testToJson(): void
    {
        $data = [
            'id' => 'doc1',
            'score' => 95.5,
            'document' => [
                'title' => 'Test Document'
            ],
            'metadata' => ['views' => 100]
        ];
        
        $result = new SearchResult($data);
        $json = $result->toJson();
        
        $this->assertJson($json);
        
        $decoded = json_decode($json, true);
        $this->assertEquals('doc1', $decoded['id']);
        $this->assertEquals(95.5, $decoded['score']);
        $this->assertEquals(['title' => 'Test Document'], $decoded['document']);
        $this->assertEquals(['views' => 100], $decoded['metadata']);
    }
    
    public function testToJsonPretty(): void
    {
        $data = [
            'id' => 'doc1',
            'score' => 95.5,
            'document' => [
                'title' => 'Test Document'
            ]
        ];
        
        $result = new SearchResult($data);
        $json = $result->toJson(JSON_PRETTY_PRINT);
        
        $this->assertJson($json);
        $this->assertStringContainsString("\n", $json); // Pretty print adds newlines
        $this->assertStringContainsString("    ", $json); // Pretty print adds indentation
    }
    
}