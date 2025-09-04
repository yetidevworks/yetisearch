<?php
/**
 * YetiSearch Pre-chunked Document Indexing Example
 * 
 * This example demonstrates how to provide custom chunks for documents
 * instead of relying on automatic chunking. This is useful when you want
 * to control chunk boundaries based on semantic meaning (e.g., paragraphs,
 * sections, headers).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use YetiSearch\YetiSearch;

echo "========================================\n";
echo "Pre-chunked Document Indexing Example\n";
echo "========================================\n\n";

// Initialize YetiSearch
$yetiSearch = new YetiSearch([
    'storage' => [
        'path' => 'pre-chunked-example.db'
    ]
]);

$yetiSearch->createIndex('articles');

// Example 1: Simple string chunks
echo "Example 1: Simple String Chunks\n";
echo "--------------------------------\n";

$simpleDoc = [
    'id' => 'article-1',
    'content' => [
        'title' => 'Complete Guide to YetiSearch',
        'author' => 'Jane Doe',
        'url' => '/guides/yetisearch'
    ],
    'metadata' => [
        'category' => 'Documentation',
        'published' => '2025-01-01'
    ],
    // Provide pre-chunked content as an array of strings
    'chunks' => [
        'Introduction: YetiSearch is a powerful search engine library for PHP applications. It provides full-text search capabilities with advanced features.',
        'Getting Started: To begin using YetiSearch, install it via Composer. The library requires PHP 7.4 or higher and SQLite with FTS5 support.',
        'Basic Usage: Create an instance of YetiSearch, then create an index. You can index documents with structured content and metadata.',
        'Advanced Features: YetiSearch supports fuzzy matching, geo-spatial search, faceted search, and multiple search algorithms.',
        'Performance: The library is optimized for speed with SQLite FTS5 backend. It supports batch indexing and search result caching.'
    ]
];

$yetiSearch->index('articles', $simpleDoc);
echo "✓ Indexed article with 5 pre-defined chunks\n\n";

// Example 2: Structured chunks with metadata
echo "Example 2: Structured Chunks with Metadata\n";
echo "------------------------------------------\n";

$structuredDoc = [
    'id' => 'article-2',
    'content' => [
        'title' => 'Building Search Applications',
        'author' => 'John Smith',
        'url' => '/tutorials/search-apps'
    ],
    'metadata' => [
        'category' => 'Tutorial',
        'difficulty' => 'Intermediate'
    ],
    // Provide structured chunks with content and metadata
    'chunks' => [
        [
            'content' => '# Introduction\nSearch functionality is crucial for modern applications. Users expect fast, accurate, and relevant search results.',
            'metadata' => [
                'section' => 'introduction',
                'heading' => 'Introduction',
                'heading_level' => 1,
                'word_count' => 15
            ]
        ],
        [
            'content' => '## Why Search Matters\nGood search improves user experience, increases engagement, and helps users find what they need quickly.',
            'metadata' => [
                'section' => 'introduction',
                'heading' => 'Why Search Matters',
                'heading_level' => 2,
                'word_count' => 18
            ]
        ],
        [
            'content' => '# Implementation\nImplementing search requires careful planning. Consider your data structure, search requirements, and performance needs.',
            'metadata' => [
                'section' => 'implementation',
                'heading' => 'Implementation',
                'heading_level' => 1,
                'word_count' => 16
            ]
        ],
        [
            'content' => '## Choosing a Search Solution\nYou can choose between database full-text search, dedicated search servers like Elasticsearch, or embedded solutions like YetiSearch.',
            'metadata' => [
                'section' => 'implementation',
                'heading' => 'Choosing a Search Solution',
                'heading_level' => 2,
                'word_count' => 22
            ]
        ],
        [
            'content' => '## Indexing Strategy\nDecide what to index, how to structure your documents, and when to update the index. Consider real-time vs batch indexing.',
            'metadata' => [
                'section' => 'implementation',
                'heading' => 'Indexing Strategy',
                'heading_level' => 2,
                'word_count' => 24
            ]
        ]
    ]
];

$yetiSearch->index('articles', $structuredDoc);
echo "✓ Indexed article with 5 structured chunks containing metadata\n\n";

// Example 3: HTML content intelligently chunked
echo "Example 3: HTML Content Smart Chunking\n";
echo "---------------------------------------\n";

// Simulate parsing HTML and creating chunks at semantic boundaries
function parseHtmlToChunks($html) {
    // In a real application, you would use a proper HTML parser
    // This is a simplified example
    return [
        [
            'content' => 'Getting Started with Web Development',
            'metadata' => ['tag' => 'h1', 'class' => 'main-title']
        ],
        [
            'content' => 'Web development involves creating websites and web applications. It encompasses front-end development, back-end development, and full-stack development.',
            'metadata' => ['tag' => 'p', 'class' => 'intro']
        ],
        [
            'content' => 'Front-end Development',
            'metadata' => ['tag' => 'h2', 'class' => 'section-title']
        ],
        [
            'content' => 'Front-end development focuses on the user interface and user experience. It involves HTML, CSS, and JavaScript to create interactive web pages.',
            'metadata' => ['tag' => 'p', 'class' => 'content']
        ],
        [
            'content' => 'Back-end Development',
            'metadata' => ['tag' => 'h2', 'class' => 'section-title']
        ],
        [
            'content' => 'Back-end development handles server-side logic, database interactions, and API development. Common languages include PHP, Python, Ruby, and Node.js.',
            'metadata' => ['tag' => 'p', 'class' => 'content']
        ]
    ];
}

$htmlDoc = [
    'id' => 'article-3',
    'content' => [
        'title' => 'Web Development Guide',
        'author' => 'Sarah Johnson',
        'url' => '/guides/web-dev'
    ],
    'metadata' => [
        'category' => 'Web Development',
        'last_updated' => '2025-01-15'
    ],
    'chunks' => parseHtmlToChunks('<html>...</html>') // Simulated HTML parsing
];

$yetiSearch->index('articles', $htmlDoc);
echo "✓ Indexed article with HTML-based smart chunks\n\n";

// Example 4: Mixed mode - some documents pre-chunked, others auto-chunked
echo "Example 4: Mixed Chunking Modes\n";
echo "--------------------------------\n";

// This document has no chunks field, so it will use automatic chunking
$autoChunkedDoc = [
    'id' => 'article-4',
    'content' => [
        'title' => 'Long Article for Auto-chunking',
        'content' => str_repeat('This is a long document that will be automatically chunked based on the configured chunk size. ', 100),
        'author' => 'Auto Chunker'
    ]
];

$yetiSearch->index('articles', $autoChunkedDoc);
echo "✓ Indexed article with automatic chunking\n\n";

// Search examples
echo "Search Examples\n";
echo "===============\n\n";

// Search across all chunks
echo "1. Search for 'search':\n";
$results = $yetiSearch->search('articles', 'search');
echo "   Found {$results['total']} results\n";
foreach (array_slice($results['results'], 0, 3) as $r) {
    $title = $r['document']['title'] ?? 'Chunk';
    $score = round($r['score'], 2);
    echo "   - {$title} (score: {$score})\n";
}

echo "\n2. Search for 'implementation strategy':\n";
$results = $yetiSearch->search('articles', 'implementation strategy');
echo "   Found {$results['total']} results\n";
foreach (array_slice($results['results'], 0, 3) as $r) {
    $title = $r['document']['title'] ?? 'Chunk';
    $section = $r['metadata']['section'] ?? 'N/A';
    echo "   - {$title} (section: {$section})\n";
}

echo "\n3. Search for 'frontend development':\n";
$results = $yetiSearch->search('articles', 'frontend development');
echo "   Found {$results['total']} results\n";
foreach (array_slice($results['results'], 0, 3) as $r) {
    $title = $r['document']['title'] ?? 'Chunk';
    $tag = $r['metadata']['tag'] ?? 'N/A';
    echo "   - {$title} (HTML tag: {$tag})\n";
}

// Clean up
echo "\n✓ Example completed successfully!\n";
unlink('pre-chunked-example.db');
unlink('pre-chunked-example.db-shm');
unlink('pre-chunked-example.db-wal');

echo "\nKey Benefits of Pre-chunked Documents:\n";
echo "--------------------------------------\n";
echo "• Control chunk boundaries at semantic breakpoints (paragraphs, sections)\n";
echo "• Preserve document structure (headings, subsections)\n";
echo "• Add custom metadata to each chunk (section names, heading levels)\n";
echo "• Better search relevance by keeping related content together\n";
echo "• Flexibility to mix pre-chunked and auto-chunked documents\n";