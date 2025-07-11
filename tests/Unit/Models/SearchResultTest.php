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
            'title' => 'Test Document',
            'content' => 'This is test content',
            'excerpt' => 'This is test...',
            'url' => '/test/doc1',
            'route' => 'test.doc1',
            'author' => 'Test Author',
            'tags' => 'test, document',
            'category' => 'Testing',
            'metadata' => ['views' => 100],
            '_index' => 'test_index',
            'distance' => 1500.5
        ];
        
        $result = new SearchResult($data);
        
        $this->assertEquals('doc1', $result->getId());
        $this->assertEquals(95.5, $result->getScore());
        $this->assertEquals('Test Document', $result->getTitle());
        $this->assertEquals('This is test content', $result->getContent());
        $this->assertEquals('This is test...', $result->getExcerpt());
        $this->assertEquals('/test/doc1', $result->getUrl());
        $this->assertEquals('test.doc1', $result->getRoute());
        $this->assertEquals('Test Author', $result->getAuthor());
        $this->assertEquals('test, document', $result->getTags());
        $this->assertEquals('Testing', $result->getCategory());
        $this->assertEquals(['views' => 100], $result->getMetadata());
        $this->assertEquals('test_index', $result->getIndex());
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
        $this->assertNull($result->getTitle());
        $this->assertNull($result->getContent());
        $this->assertNull($result->getExcerpt());
        $this->assertNull($result->getUrl());
        $this->assertNull($result->getRoute());
        $this->assertNull($result->getAuthor());
        $this->assertNull($result->getTags());
        $this->assertNull($result->getCategory());
        $this->assertEquals([], $result->getMetadata());
        $this->assertNull($result->getIndex());
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
            'title' => 'Test Document',
            'custom_field' => 'Custom Value'
        ];
        
        $result = new SearchResult($data);
        
        $this->assertEquals('doc1', $result->get('id'));
        $this->assertEquals(95.5, $result->get('score'));
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
            'title' => 'Test Document',
            'content' => 'Test content',
            'metadata' => ['views' => 100]
        ];
        
        $result = new SearchResult($data);
        $array = $result->toArray();
        
        $this->assertIsArray($array);
        $this->assertEquals('doc1', $array['id']);
        $this->assertEquals(95.5, $array['score']);
        $this->assertEquals('Test Document', $array['title']);
        $this->assertEquals('Test content', $array['content']);
        $this->assertEquals(['views' => 100], $array['metadata']);
        
        // Check that null values are not included
        $this->assertArrayNotHasKey('url', $array);
        $this->assertArrayNotHasKey('distance', $array);
    }
    
    public function testToJson(): void
    {
        $data = [
            'id' => 'doc1',
            'score' => 95.5,
            'title' => 'Test Document',
            'metadata' => ['views' => 100]
        ];
        
        $result = new SearchResult($data);
        $json = $result->toJson();
        
        $this->assertJson($json);
        
        $decoded = json_decode($json, true);
        $this->assertEquals('doc1', $decoded['id']);
        $this->assertEquals(95.5, $decoded['score']);
        $this->assertEquals('Test Document', $decoded['title']);
        $this->assertEquals(['views' => 100], $decoded['metadata']);
    }
    
    public function testToJsonPretty(): void
    {
        $data = [
            'id' => 'doc1',
            'score' => 95.5,
            'title' => 'Test Document'
        ];
        
        $result = new SearchResult($data);
        $json = $result->toJson(true);
        
        $this->assertJson($json);
        $this->assertStringContainsString("\n", $json); // Pretty print adds newlines
        $this->assertStringContainsString("    ", $json); // Pretty print adds indentation
    }
    
    public function testJsonSerializable(): void
    {
        $data = [
            'id' => 'doc1',
            'score' => 95.5,
            'title' => 'Test Document'
        ];
        
        $result = new SearchResult($data);
        $encoded = json_encode($result);
        
        $this->assertJson($encoded);
        
        $decoded = json_decode($encoded, true);
        $this->assertEquals('doc1', $decoded['id']);
        $this->assertEquals(95.5, $decoded['score']);
        $this->assertEquals('Test Document', $decoded['title']);
    }
}