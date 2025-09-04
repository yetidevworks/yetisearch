<?php

namespace YetiSearch\Tests;

use PHPUnit\Framework\TestCase;
use YetiSearch\YetiSearch;

class PreChunkedDocumentTest extends TestCase
{
    private YetiSearch $search;
    
    protected function setUp(): void
    {
        $this->search = new YetiSearch([
            'storage' => ['path' => ':memory:']
        ]);
        $this->search->createIndex('test');
    }
    
    public function testPreChunkedDocumentWithSimpleStrings(): void
    {
        $document = [
            'id' => 'doc1',
            'content' => [
                'title' => 'Pre-chunked Document',
                'content' => 'This is the main content that would normally be chunked automatically.'
            ],
            'metadata' => [
                'author' => 'Test Author'
            ],
            'chunks' => [
                'Chapter 1: Introduction. This is the introduction to the document.',
                'Chapter 2: Main Content. This section contains the main content.',
                'Chapter 3: Conclusion. This is where we wrap things up.'
            ]
        ];
        
        $this->search->index('test', $document);
        
        // Search for content in a specific chunk
        $results = $this->search->search('test', 'introduction');
        $this->assertGreaterThan(0, $results['total']);
        
        $results = $this->search->search('test', 'conclusion');
        $this->assertGreaterThan(0, $results['total']);
    }
    
    public function testPreChunkedDocumentWithStructuredChunks(): void
    {
        $document = [
            'id' => 'doc2',
            'content' => [
                'title' => 'Structured Pre-chunked Document',
                'content' => 'Main document content'
            ],
            'chunks' => [
                [
                    'content' => '## Introduction\nThis is the introduction paragraph that provides context.',
                    'metadata' => [
                        'section' => 'introduction',
                        'heading_level' => 2
                    ]
                ],
                [
                    'content' => '## Methodology\nHere we describe the methodology used in this research.',
                    'metadata' => [
                        'section' => 'methodology',
                        'heading_level' => 2
                    ]
                ],
                [
                    'content' => '### Data Collection\nData was collected from various sources.',
                    'metadata' => [
                        'section' => 'methodology',
                        'subsection' => 'data_collection',
                        'heading_level' => 3
                    ]
                ],
                [
                    'content' => '## Results\nThe results show significant improvements.',
                    'metadata' => [
                        'section' => 'results',
                        'heading_level' => 2
                    ]
                ]
            ]
        ];
        
        $this->search->index('test', $document);
        
        // Search for content
        $results = $this->search->search('test', 'methodology research');
        $this->assertGreaterThan(0, $results['total']);
        
        // The chunk should have the custom metadata
        $firstResult = $results['results'][0];
        $this->assertTrue(isset($firstResult['metadata']['section']) || isset($firstResult['metadata']['is_chunk']));
    }
    
    public function testMixedChunkingModes(): void
    {
        // Document with pre-chunks
        $preChunked = [
            'id' => 'pre1',
            'content' => [
                'title' => 'Pre-chunked Article',
                'content' => 'Short main content'
            ],
            'chunks' => [
                'First paragraph content.',
                'Second paragraph content.'
            ]
        ];
        
        // Document that will be auto-chunked
        $autoChunked = [
            'id' => 'auto1',
            'content' => [
                'title' => 'Auto-chunked Article',
                'content' => str_repeat('This is a long document that will be automatically chunked. ', 200)
            ]
        ];
        
        // Document that won't be chunked (too short)
        $notChunked = [
            'id' => 'short1',
            'content' => [
                'title' => 'Short Article',
                'content' => 'This is too short to chunk.'
            ]
        ];
        
        $this->search->index('test', $preChunked);
        $this->search->index('test', $autoChunked);
        $this->search->index('test', $notChunked);
        
        // All documents should be searchable
        $results = $this->search->search('test', 'Article');
        $this->assertGreaterThanOrEqual(3, $results['total']);
    }
    
    public function testPreChunkedWithHtmlContent(): void
    {
        // Example of intelligent chunking based on HTML structure
        $document = [
            'id' => 'html-doc',
            'content' => [
                'title' => 'HTML Document with Smart Chunks',
                'content' => 'Full HTML content here'
            ],
            'chunks' => [
                [
                    'content' => 'Getting Started. Welcome to our comprehensive guide.',
                    'metadata' => [
                        'heading' => 'Getting Started',
                        'tag' => 'h1',
                        'position' => 0
                    ]
                ],
                [
                    'content' => 'Installation. To install the software, follow these steps...',
                    'metadata' => [
                        'heading' => 'Installation',
                        'tag' => 'h2',
                        'position' => 1
                    ]
                ],
                [
                    'content' => 'First, download the package from our website. Then extract the files.',
                    'metadata' => [
                        'tag' => 'p',
                        'position' => 2
                    ]
                ],
                [
                    'content' => 'Configuration. After installation, you need to configure...',
                    'metadata' => [
                        'heading' => 'Configuration',
                        'tag' => 'h2',
                        'position' => 3
                    ]
                ]
            ]
        ];
        
        $this->search->index('test', $document);
        
        $results = $this->search->search('test', 'installation software');
        $this->assertGreaterThan(0, $results['total']);
        
        // Check that metadata is preserved
        $found = false;
        foreach ($results['results'] as $result) {
            if (isset($result['metadata']['heading'])) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Should find result with heading metadata');
    }
}