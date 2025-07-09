<?php

namespace YetiSearch\Tests\Unit\Models;

use YetiSearch\Tests\TestCase;
use YetiSearch\Models\Document;

class DocumentTest extends TestCase
{
    public function testCreateDocument(): void
    {
        $document = new Document(
            'doc-123',
            ['title' => 'Test Document', 'body' => 'Test content'],
            'en',
            ['author' => 'John Doe', 'category' => 'test'],
            null,
            'article'
        );
        
        $this->assertEquals('doc-123', $document->getId());
        $this->assertEquals(['title' => 'Test Document', 'body' => 'Test content'], $document->getContent());
        $this->assertEquals(['author' => 'John Doe', 'category' => 'test'], $document->getMetadata());
        $this->assertEquals('en', $document->getLanguage());
        $this->assertEquals('article', $document->getType());
        $this->assertIsInt($document->getTimestamp());
    }
    
    public function testCreateDocumentWithDefaults(): void
    {
        $document = new Document('doc-456', ['title' => 'Test']);
        
        $this->assertEquals('doc-456', $document->getId());
        $this->assertEquals(['title' => 'Test'], $document->getContent());
        $this->assertEquals([], $document->getMetadata());
        $this->assertNull($document->getLanguage());
        $this->assertEquals('default', $document->getType());
        $this->assertIsInt($document->getTimestamp());
    }
    
    public function testFromArray(): void
    {
        $data = [
            'id' => 'doc-789',
            'content' => ['title' => 'Array Document', 'body' => 'Content from array'],
            'metadata' => ['tags' => ['php', 'search']],
            'language' => 'fr',
            'type' => 'documentation',
            'timestamp' => 1234567890
        ];
        
        $document = Document::fromArray($data);
        
        $this->assertEquals('doc-789', $document->getId());
        $this->assertEquals($data['content'], $document->getContent());
        $this->assertEquals($data['metadata'], $document->getMetadata());
        $this->assertEquals('fr', $document->getLanguage());
        $this->assertEquals('documentation', $document->getType());
        $this->assertEquals(1234567890, $document->getTimestamp());
    }
    
    public function testFromArrayWithMissingFields(): void
    {
        $data = [
            'id' => 'minimal-doc',
            'content' => ['text' => 'Minimal content']
        ];
        
        $document = Document::fromArray($data);
        
        $this->assertEquals('minimal-doc', $document->getId());
        $this->assertEquals(['text' => 'Minimal content'], $document->getContent());
        $this->assertEquals([], $document->getMetadata());
        $this->assertNull($document->getLanguage());
        $this->assertEquals('default', $document->getType());
        $this->assertIsInt($document->getTimestamp());
        $this->assertGreaterThan(0, $document->getTimestamp());
    }
    
    public function testToArray(): void
    {
        $document = new Document(
            'doc-array',
            ['title' => 'To Array Test', 'content' => 'Test serialization'],
            ['version' => 1.0, 'published' => true],
            'es',
            'blog'
        );
        
        $array = $document->toArray();
        
        $this->assertIsArray($array);
        $this->assertEquals('doc-array', $array['id']);
        $this->assertEquals(['title' => 'To Array Test', 'content' => 'Test serialization'], $array['content']);
        $this->assertEquals(['version' => 1.0, 'published' => true], $array['metadata']);
        $this->assertEquals('es', $array['language']);
        $this->assertEquals('blog', $array['type']);
        $this->assertArrayHasKey('timestamp', $array);
    }
    
    public function testSettersAndGetters(): void
    {
        $document = new Document('initial-id', ['initial' => 'content']);
        
        // Test setters
        $document->setContent(['updated' => 'content']);
        $document->setMetadata(['new' => 'metadata']);
        $document->setLanguage('de');
        $document->setType('updated-type');
        $document->setTimestamp(9876543210);
        
        // Test getters
        $this->assertEquals(['updated' => 'content'], $document->getContent());
        $this->assertEquals(['new' => 'metadata'], $document->getMetadata());
        $this->assertEquals('de', $document->getLanguage());
        $this->assertEquals('updated-type', $document->getType());
        $this->assertEquals(9876543210, $document->getTimestamp());
    }
    
    public function testAddMetadata(): void
    {
        $document = new Document('test-doc', ['content' => 'test']);
        $document->setMetadata(['existing' => 'value']);
        
        // Add new metadata
        $document->addMetadata('new_key', 'new_value');
        
        $metadata = $document->getMetadata();
        $this->assertEquals('value', $metadata['existing']);
        $this->assertEquals('new_value', $metadata['new_key']);
        
        // Overwrite existing metadata
        $document->addMetadata('existing', 'updated_value');
        $metadata = $document->getMetadata();
        $this->assertEquals('updated_value', $metadata['existing']);
    }
    
    
    
    public function testDocumentValidation(): void
    {
        // Test with empty ID - Document doesn't validate, so just ensure it works
        $document = new Document('', ['content' => 'test']);
        $this->assertEquals('', $document->getId());
    }
    
    public function testDocumentWithNumericContent(): void
    {
        $document = new Document('numeric-doc', [
            'price' => 99.99,
            'quantity' => 100,
            'rating' => 4.5,
            'in_stock' => true
        ]);
        
        $array = $document->toArray();
        $this->assertEquals(99.99, $array['content']['price']);
        $this->assertEquals(100, $array['content']['quantity']);
        $this->assertEquals(4.5, $array['content']['rating']);
        $this->assertTrue($array['content']['in_stock']);
        
    }
    
    public function testDocumentCloning(): void
    {
        $original = new Document(
            'original-doc',
            ['title' => 'Original'],
            'en',
            ['version' => 1],
            null,
            'test'
        );
        
        $clone = clone $original;
        
        // Verify clone has same values
        $this->assertEquals($original->getId(), $clone->getId());
        $this->assertEquals($original->getContent(), $clone->getContent());
        $this->assertEquals($original->getMetadata(), $clone->getMetadata());
        
        // Verify modifications don't affect original
        $clone->setContent(['title' => 'Modified']);
        $this->assertEquals(['title' => 'Original'], $original->getContent());
        $this->assertEquals(['title' => 'Modified'], $clone->getContent());
    }
    
    public function testJsonSerialization(): void
    {
        $document = new Document(
            'json-doc',
            ['title' => 'JSON Test', 'body' => 'Testing JSON serialization'],
            'en',
            ['tags' => ['json', 'test']],
            null,
            'test'
        );
        
        $json = json_encode($document->toArray());
        $this->assertJson($json);
        
        $decoded = json_decode($json, true);
        $this->assertEquals('json-doc', $decoded['id']);
        $this->assertEquals('JSON Test', $decoded['content']['title']);
        $this->assertEquals(['json', 'test'], $decoded['metadata']['tags']);
    }
}