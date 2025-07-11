# YetiSearch

[![CI](https://github.com/yetidevworks/yetisearch/workflows/CI/badge.svg)](https://github.com/yetidevworks/yetisearch/actions)
[![PHP Version](https://img.shields.io/packagist/php-v/yetidevworks/yetisearch)](https://packagist.org/packages/yetidevworks/yetisearch)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Latest Stable Version](https://img.shields.io/packagist/v/yetidevworks/yetisearch)](https://packagist.org/packages/yetidevworks/yetisearch)

A powerful, pure-PHP search engine library with advanced full-text search capabilities, designed for modern PHP applications.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Usage Examples](#usage-examples)
  - [Basic Indexing](#basic-indexing)
  - [Advanced Indexing](#advanced-indexing)
  - [Search Examples](#search-examples)
  - [Document Management](#document-management)
- [Configuration](#configuration)
- [Advanced Features](#advanced-features)
  - [Document Chunking](#document-chunking)
  - [Multi-language Support](#multi-language-support)
  - [Custom Stop Words](#custom-stop-words)
  - [Geo-Spatial Search](#geo-spatial-search)
  - [Search Result Deduplication](#search-result-deduplication)
  - [Highlighting](#highlighting)
  - [Fuzzy Search](#fuzzy-search)
  - [Faceted Search](#faceted-search)
- [Architecture](#architecture)
- [Testing](#testing)
- [API Reference](#api-reference)
- [Future Features](#future-features)
- [Contributing](#contributing)
- [License](#license)

## Features

- 🔍 **Full-text search** powered by SQLite FTS5 with BM25 relevance scoring
- 📄 **Automatic document chunking** for indexing large documents
- 🎯 **Smart result deduplication** - shows best match per document by default
- 🌍 **Multi-language support** with built-in stemming for multiple languages
- ⚡ **Lightning-fast** indexing and searching with SQLite backend
- 🔧 **Flexible architecture** with interfaces for easy extension
- 📊 **Advanced scoring** with field boosting and relevance tuning
- 🎨 **Search highlighting** with customizable tags
- 🔤 **Fuzzy matching** for typo-tolerant searches
- 📈 **Faceted search** and aggregations support
- 📍 **Geo-spatial search** with R-tree indexing for location-based queries
- 🚀 **Zero dependencies** except PHP extensions and small utility packages
- 💾 **Persistent storage** with automatic database management
- 🔐 **Production-ready** with comprehensive test coverage

## Requirements

- PHP 7.4 or higher
- SQLite3 PHP extension
- PDO PHP extension with SQLite driver
- Mbstring PHP extension
- JSON PHP extension

## Installation

Install YetiSearch via Composer:

```bash
composer require yetidevworks/yetisearch
```

## Quick Start

```php
<?php
use YetiSearch\YetiSearch;

// Initialize YetiSearch with configuration
$config = [
    'storage' => [
        'path' => '/path/to/your/search.db'
    ]
];
$search = new YetiSearch($config);

// Create an index
$indexer = $search->createIndex('pages');

// Index a document
$indexer->index([
    'id' => 'doc1',
    'content' => [
        'title' => 'Introduction to YetiSearch',
        'body' => 'YetiSearch is a powerful search engine library for PHP applications...',
        'url' => 'https://example.com/intro',
        'tags' => 'search php library'
    ]
]);

// Search for documents
$results = $search->search('pages', 'powerful search');

// Display results
foreach ($results['results'] as $result) {
    echo $result['title'] . ' (Score: ' . $result['score'] . ")\n";
    echo $result['excerpt'] . "\n\n";
}
```

## Usage Examples

### Basic Indexing

```php
use YetiSearch\YetiSearch;

$search = new YetiSearch([
    'storage' => ['path' => './search.db']
]);

$indexer = $search->createIndex('articles');

// Index a single document
$document = [
    'id' => 'article-1',
    'content' => [
        'title' => 'Getting Started with PHP',
        'body' => 'PHP is a popular general-purpose scripting language...',
        'author' => 'John Doe',
        'category' => 'Programming',
        'tags' => 'php programming tutorial'
    ],
    'metadata' => [
        'date' => time()
    ]
];

$indexer->index($document);

// Index multiple documents
$documents = [
    [
        'id' => 'article-2',
        'content' => [
            'title' => 'Advanced PHP Techniques',
            'body' => 'Let\'s explore advanced PHP programming techniques...',
            'author' => 'Jane Smith',
            'category' => 'Programming',
            'tags' => 'php advanced tips'
        ]
    ],
    [
        'id' => 'article-3',
        'content' => [
            'title' => 'PHP Performance Optimization',
            'body' => 'Optimizing PHP applications for better performance...',
            'author' => 'Bob Johnson',
            'category' => 'Performance',
            'tags' => 'php performance optimization'
        ]
    ]
];

$indexer->indexBatch($documents);

// Flush to ensure all documents are written
$indexer->flush();
```

### Advanced Indexing

```php
// Configure indexer with custom settings
$indexer = $search->createIndex('products', [
    'fields' => [
        'name' => ['boost' => 3.0, 'store' => true],
        'description' => ['boost' => 1.0, 'store' => true],
        'brand' => ['boost' => 2.0, 'store' => true],
        'sku' => ['boost' => 1.0, 'store' => true, 'index' => false],
        'price' => ['boost' => 1.0, 'store' => true, 'index' => false]
    ],
    'chunk_size' => 500,        // Smaller chunks for product descriptions
    'chunk_overlap' => 50,      // Overlap between chunks
    'batch_size' => 100         // Process 100 documents at a time
]);

// Index products with metadata
$product = [
    'id' => 'prod-123',
    'content' => [
        'name' => 'Professional PHP Development Book',
        'description' => 'A comprehensive guide to professional PHP development...',
        'brand' => 'TechBooks Publishing',
        'sku' => 'TB-PHP-001',
        'price' => 49.99
    ],
    'metadata' => [
        'in_stock' => true,
        'rating' => 4.5,
        'reviews' => 127
    ]
];

$indexer->index($product);
```

### Search Examples

```php
// Basic search
$results = $search->search('articles', 'PHP programming');

// Advanced search with options
$results = $search->search('articles', 'advanced techniques', [
    'limit' => 20,
    'offset' => 0,
    'fields' => ['title', 'content', 'tags'],  // Search only in specific fields
    'highlight' => true,                       // Enable highlighting
    'fuzzy' => true,                          // Enable fuzzy matching
    'unique_by_route' => true,                // Deduplicate results (default)
    'filters' => [
        [
            'field' => 'category',
            'value' => 'Programming',
            'operator' => '='
        ],
        [
            'field' => 'date',
            'value' => strtotime('-30 days'),
            'operator' => '>='
        ]
    ],
    'boost' => [
        'title' => 3.0,
        'tags' => 2.0,
        'content' => 1.0
    ]
]);

// Process results
echo "Found {$results['total']} results in {$results['search_time']} seconds\n\n";

foreach ($results['results'] as $result) {
    echo "Title: " . $result['title'] . "\n";
    echo "Score: " . $result['score'] . "\n";
    echo "URL: " . $result['url'] . "\n";
    echo "Excerpt: " . $result['excerpt'] . "\n";
    echo "---\n";
}

// Available filter operators
$results = $search->search('products', 'laptop', [
    'filters' => [
        ['field' => 'category', 'value' => 'Electronics', 'operator' => '='],      // Exact match
        ['field' => 'price', 'value' => 500, 'operator' => '<'],                   // Less than
        ['field' => 'price', 'value' => 100, 'operator' => '>'],                   // Greater than
        ['field' => 'rating', 'value' => 4, 'operator' => '>='],                   // Greater or equal
        ['field' => 'stock', 'value' => 10, 'operator' => '<='],                   // Less or equal
        ['field' => 'brand', 'value' => 'Apple', 'operator' => '!='],              // Not equal
        ['field' => 'tags', 'value' => ['laptop', 'gaming'], 'operator' => 'in'],  // In array
        ['field' => 'title', 'value' => 'Pro', 'operator' => 'contains'],          // Contains text
        ['field' => 'metadata.warranty', 'operator' => 'exists'],                  // Field exists
    ]
]);

// Get all chunks (no deduplication)
$allChunks = $search->search('articles', 'PHP programming', [
    'unique_by_route' => false  // Show all matching chunks
]);

// Search with pagination
$page = 2;
$perPage = 10;
$results = $search->search('articles', 'PHP', [
    'limit' => $perPage,
    'offset' => ($page - 1) * $perPage
]);

// Faceted search
$results = $search->search('products', 'book', [
    'facets' => [
        'category' => ['limit' => 10],
        'brand' => ['limit' => 5],
        'price_range' => [
            'type' => 'range',
            'ranges' => [
                ['to' => 20],
                ['from' => 20, 'to' => 50],
                ['from' => 50]
            ]
        ]
    ]
]);

// Access facets
foreach ($results['facets']['category'] as $facet) {
    echo "{$facet['value']}: {$facet['count']} items\n";
}
```

### Multi-Index Search

Search across multiple indexes simultaneously:

```php
// Search specific indexes
$results = $search->searchMultiple(['products', 'articles'], 'PHP book', [
    'limit' => 20
]);

// Search all indexes matching a pattern
$results = $search->searchMultiple(['content_*'], 'search term', [
    'limit' => 20
]);

// Results include index information
foreach ($results['results'] as $result) {
    echo "From index: " . $result['_index'] . "\n";
    echo "Title: " . $result['title'] . "\n";
}
```

### Document Management

```php
// Update a document
$indexer->update([
    'id' => 'article-1',
    'content' => [
        'title' => 'Getting Started with PHP 8',  // Updated title
        'body' => 'PHP 8 introduces many new features...',
        'author' => 'John Doe',
        'category' => 'Programming',
        'tags' => 'php php8 programming tutorial'
    ]
]);

// Delete a document
$indexer->delete('article-1');

// Clear entire index
$indexer->clear();

// Get index statistics
$stats = $indexer->getStats();
echo "Total documents: " . $stats['total_documents'] . "\n";
echo "Total size: " . $stats['total_size'] . " bytes\n";
echo "Average document size: " . $stats['avg_document_size'] . " bytes\n";

// Optimize index for better performance
$indexer->optimize();
```

## Configuration

### Full Configuration Example

```php
$config = [
    'storage' => [
        'path' => '/path/to/search.db',
        'timeout' => 5000,              // Connection timeout in ms
        'busy_timeout' => 10000,        // Busy timeout in ms
        'journal_mode' => 'WAL',        // Write-Ahead Logging for better concurrency
        'synchronous' => 'NORMAL',      // Sync mode
        'cache_size' => -2000,          // Cache size in KB (negative = KB)
        'temp_store' => 'MEMORY'        // Use memory for temp tables
    ],
    'analyzer' => [
        'min_word_length' => 2,         // Minimum word length to index
        'max_word_length' => 50,        // Maximum word length to index
        'remove_numbers' => false,      // Keep numbers in index
        'lowercase' => true,            // Convert to lowercase
        'strip_html' => true,           // Remove HTML tags
        'strip_punctuation' => true,    // Remove punctuation
        'expand_contractions' => true,  // Expand contractions (e.g., don't -> do not)
        'custom_stop_words' => ['example', 'custom'], // Additional stop words to exclude
        'disable_stop_words' => false   // Set to true to disable all stop word filtering
    ],
    'indexer' => [
        'batch_size' => 100,            // Documents per batch
        'auto_flush' => true,           // Auto-flush after batch_size
        'chunk_size' => 1000,           // Characters per chunk
        'chunk_overlap' => 100,         // Overlap between chunks
        'fields' => [                   // Field configuration
            'title' => ['boost' => 3.0, 'store' => true],
            'content' => ['boost' => 1.0, 'store' => true],
            'excerpt' => ['boost' => 2.0, 'store' => true],
            'tags' => ['boost' => 2.5, 'store' => true],
            'category' => ['boost' => 2.0, 'store' => true],
            'author' => ['boost' => 1.5, 'store' => true],
            'url' => ['boost' => 1.0, 'store' => true, 'index' => false],
            'route' => ['boost' => 1.0, 'store' => true, 'index' => false]
        ]
    ],
    'search' => [
        'min_score' => 0.0,             // Minimum score threshold
        'highlight_tag' => '<mark>',    // Opening highlight tag
        'highlight_tag_close' => '</mark>', // Closing highlight tag
        'snippet_length' => 150,        // Length of snippets
        'max_results' => 1000,          // Maximum results to return
        'enable_fuzzy' => true,         // Enable fuzzy search
        'enable_suggestions' => true,   // Enable search suggestions
        'cache_ttl' => 300,             // Cache TTL in seconds
        'result_fields' => [            // Fields to include in results
            'title', 'content', 'excerpt', 'url', 'author', 'tags', 'route'
        ]
    ]
];

$search = new YetiSearch($config);
```

## Advanced Features

### Document Chunking

YetiSearch automatically splits large documents into smaller chunks for better search performance and relevance:

```php
$indexer = $search->createIndex('books', [
    'chunk_size' => 1000,      // 1000 characters per chunk
    'chunk_overlap' => 100     // 100 character overlap
]);

// Index a large document - it will be automatically chunked
$indexer->index([
    'id' => 'book-1',
    'title' => 'War and Peace',
    'content' => $veryLongBookContent,  // Will be split into chunks
    'author' => 'Leo Tolstoy'
]);

// Search returns the best matching chunk by default
$results = $search->search('books', 'Napoleon');

// Get all matching chunks
$allChunks = $search->search('books', 'Napoleon', [
    'unique_by_route' => false
]);
```

### Multi-language Support

```php
// Index documents in different languages
$indexer->index([
    'id' => 'doc-fr-1',
    'title' => 'Introduction à PHP',
    'content' => 'PHP est un langage de programmation...',
    'language' => 'french'
]);

$indexer->index([
    'id' => 'doc-de-1',
    'title' => 'Einführung in PHP',
    'content' => 'PHP ist eine Programmiersprache...',
    'language' => 'german'
]);

// Search with language-specific stemming
$results = $search->search('pages', 'programmation', [
    'language' => 'french'
]);
```

Supported languages:
- English (default)
- French
- German
- Spanish
- Italian
- Portuguese
- Dutch
- Swedish
- Norwegian
- Danish

### Custom Stop Words

You can add custom stop words to exclude specific terms from being indexed:

```php
// Configure custom stop words during initialization
$search = new YetiSearch([
    'analyzer' => [
        'custom_stop_words' => ['lorem', 'ipsum', 'dolor']
    ]
]);

// Or add them dynamically
$analyzer = $search->getAnalyzerInstance();
$analyzer->addCustomStopWord('example');
$analyzer->addCustomStopWord('test');

// Remove a custom stop word
$analyzer->removeCustomStopWord('test');

// Get all custom stop words
$customWords = $analyzer->getCustomStopWords();

// Disable all stop word filtering (not recommended)
$analyzer->setStopWordsDisabled(true);
```

Custom stop words are applied in addition to the default language-specific stop words. They are case-insensitive and apply across all languages.

### Geo-Spatial Search

YetiSearch supports location-based searching using SQLite's R-tree spatial indexing:

```php
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Geo\GeoBounds;

// Index documents with location data
$indexer->index([
    'id' => 'coffee-shop-1',
    'content' => [
        'title' => 'Blue Bottle Coffee',
        'body' => 'Specialty coffee roaster and cafe'
    ],
    'geo' => [
        'lat' => 37.7825,
        'lng' => -122.4099
    ]
]);

// Search within radius of a point
$searchQuery = new SearchQuery('coffee');
$searchQuery->near(new GeoPoint(37.7749, -122.4194), 5000); // 5km radius
$results = $searchEngine->search($searchQuery);

// Search within bounding box
$searchQuery = new SearchQuery('restaurant');
$searchQuery->withinBounds(37.8, 37.7, -122.3, -122.5);
// Or with a GeoBounds object:
$bounds = new GeoBounds(37.8, 37.7, -122.3, -122.5);
$searchQuery->within($bounds);

// Sort results by distance
$searchQuery = new SearchQuery('food');
$searchQuery->sortByDistance(new GeoPoint(37.7749, -122.4194), 'asc');

// Combine text search with geo filters
$searchQuery = new SearchQuery('italian restaurant')
    ->near(new GeoPoint(37.7749, -122.4194), 3000)
    ->filter('price_range', '$$')
    ->limit(10);

// Results include distance when geo queries are used
foreach ($results->getResults() as $result) {
    echo $result->get('title') . ' - ';
    if ($result->hasDistance()) {
        echo GeoUtils::formatDistance($result->getDistance()) . ' away';
    }
    echo PHP_EOL;
}
```

**Geo Utilities:**

```php
use YetiSearch\Geo\GeoUtils;

// Distance calculations
$distance = GeoUtils::distance($point1, $point2); // meters
$distance = GeoUtils::distanceBetween($lat1, $lng1, $lat2, $lng2);

// Unit conversions
$meters = GeoUtils::kmToMeters(5);
$meters = GeoUtils::milesToMeters(3.1);

// Format distance for display
echo GeoUtils::formatDistance(1500); // "1.5 km"
echo GeoUtils::formatDistance(1500, 'imperial'); // "0.9 mi"

// Parse various coordinate formats
$point = GeoUtils::parsePoint(['lat' => 37.7749, 'lng' => -122.4194]);
$point = GeoUtils::parsePoint([37.7749, -122.4194]);
$point = GeoUtils::parsePoint('37.7749,-122.4194');
```

**Indexing with Bounds:**

```php
// Index areas/regions with bounding boxes
$indexer->index([
    'id' => 'downtown-sf',
    'content' => [
        'title' => 'Downtown San Francisco',
        'body' => 'Financial district and shopping area'
    ],
    'geo_bounds' => [
        'north' => 37.8,
        'south' => 37.77,
        'east' => -122.39,
        'west' => -122.42
    ]
]);
```

### Search Result Deduplication

By default, YetiSearch deduplicates results to show only the best matching chunk per document:

```php
// Default behavior - returns unique documents (best chunk per document)
$uniqueResults = $search->search('pages', 'PHP framework');
echo "Found {$uniqueResults['total']} unique documents\n";

// Get all chunks including duplicates
$allChunks = $search->search('pages', 'PHP framework', [
    'unique_by_route' => false
]);
echo "Found {$allChunks['total']} total matching chunks\n";
```

### Highlighting

Search results can include highlighted matches:

```php
$results = $search->search('pages', 'PHP programming', [
    'highlight' => true,
    'highlight_length' => 200  // Snippet length
]);

foreach ($results['results'] as $result) {
    // Excerpt will contain <mark>PHP</mark> and <mark>programming</mark>
    echo $result['excerpt'] . "\n";
}

// Custom highlight tags
$search = new YetiSearch([
    'search' => [
        'highlight_tag' => '<span class="highlight">',
        'highlight_tag_close' => '</span>'
    ]
]);
```

### Fuzzy Search

Enable fuzzy matching for typo tolerance:

```php
// Find results even with typos
$results = $search->search('pages', 'porgramming', [  // Note the typo
    'fuzzy' => true,
    'fuzziness' => 0.8  // 0.0 to 1.0 (higher = stricter)
]);

// Will still find documents about "programming"
```

### Faceted Search

Get aggregated counts for categories, tags, etc:

```php
$results = $search->search('products', 'laptop', [
    'facets' => [
        'brand' => ['limit' => 10],
        'category' => ['limit' => 5],
        'price' => [
            'type' => 'range',
            'ranges' => [
                ['to' => 500, 'key' => 'budget'],
                ['from' => 500, 'to' => 1000, 'key' => 'mid-range'],
                ['from' => 1000, 'key' => 'premium']
            ]
        ]
    ],
    'aggregations' => [
        'avg_price' => ['type' => 'avg', 'field' => 'price'],
        'max_price' => ['type' => 'max', 'field' => 'price'],
        'min_price' => ['type' => 'min', 'field' => 'price']
    ]
]);

// Display facets
foreach ($results['facets']['brand'] as $brand) {
    echo "{$brand['value']}: {$brand['count']} products\n";
}

// Display aggregations
echo "Average price: $" . $results['aggregations']['avg_price'] . "\n";
```

## Architecture

YetiSearch follows a modular architecture with clear separation of concerns:

```
YetiSearch/
├── Analyzers/          # Text analysis and tokenization
│   └── StandardAnalyzer.php
├── Contracts/          # Interfaces for extensibility
│   ├── AnalyzerInterface.php
│   ├── IndexerInterface.php
│   ├── SearchEngineInterface.php
│   └── StorageInterface.php
├── Index/              # Indexing logic
│   └── Indexer.php
├── Models/             # Data models
│   ├── Document.php
│   ├── SearchQuery.php
│   └── SearchResult.php
├── Search/             # Search implementation
│   └── SearchEngine.php
└── Storage/            # Storage backends
    └── SqliteStorage.php
```

### Key Components

- **Analyzer**: Tokenizes and processes text (stemming, stop words, etc.)
- **Indexer**: Manages document indexing and updates
- **SearchEngine**: Handles search queries and result processing
- **Storage**: Abstracts the storage backend (currently SQLite)

## Testing

YetiSearch includes comprehensive test coverage. Run tests using various commands:

### Basic Testing

```bash
# Run all tests (simple dots output)
composer test

# Run with descriptive output
composer test:verbose

# Run with pretty formatting
composer test:pretty
```

### Coverage Reports

```bash
# Text coverage in terminal
composer test:coverage

# HTML coverage report
composer test:coverage-html
# Open build/coverage/index.html in browser
```

### Filtered Testing

```bash
# Run specific test class
composer test:filter StandardAnalyzer

# Run specific test method
composer test:filter testAnalyzeBasicText
```

### Advanced Testing

```bash
# Run only unit tests
vendor/bin/phpunit --testsuite=Unit

# Run with custom configuration
vendor/bin/phpunit -c phpunit-readable.xml
```

### Static Analysis

```bash
# Run PHPStan analysis
composer phpstan

# Check coding standards
composer cs

# Fix coding standards
composer cs-fix
```

## API Reference

### YetiSearch Class

```php
// Create instance
$search = new YetiSearch(array $config = []);

// Index management
$indexer = $search->createIndex(string $name, array $options = []);
$indexer = $search->getIndexer(string $name);

// Search operations
$results = $search->search(string $indexName, string $query, array $options = []);
$count = $search->count(string $indexName, string $query, array $options = []);
$suggestions = $search->suggest(string $indexName, string $term, array $options = []);

// Index operations
$search->index(string $indexName, array $documentData);
$search->indexBatch(string $indexName, array $documents);
$search->update(string $indexName, array $documentData);
$search->delete(string $indexName, string $documentId);
$search->clear(string $indexName);
$search->optimize(string $indexName);
$search->getStats(string $indexName);
```

### Document Structure

Documents are represented as associative arrays with the following structure:

```php
$document = [
    'id' => 'unique-id',          // Required: unique identifier
    'content' => [                // Required: content fields to index
        'title' => 'Document Title',
        'body' => 'Main content...',
        'author' => 'John Doe',
        // ... any other fields
    ],
    'metadata' => [               // Optional: non-indexed metadata
        'created_at' => time(),
        'status' => 'published',
        // ... any other metadata
    ],
    'language' => 'en',           // Optional: language code
    'type' => 'article',          // Optional: document type
    'timestamp' => time(),        // Optional: defaults to current time
    'geo' => [                    // Optional: geographic point
        'lat' => 37.7749,
        'lng' => -122.4194
    ],
    'geo_bounds' => [             // Optional: geographic bounds
        'north' => 37.8,
        'south' => 37.7,
        'east' => -122.3,
        'west' => -122.5
    ]
];
```

#### Content vs Metadata

Understanding the distinction between `content` and `metadata` fields:

**Content Fields:**
- Are indexed and searchable - these fields are analyzed, tokenized, and can be found via search queries
- Affect relevance scoring - matches in content fields contribute to the document's search score
- Support field boosting - you can make certain fields more important for ranking
- Are returned in search results by default
- Examples: title, body, description, tags, author, category

**Metadata Fields:**
- Are NOT indexed - stored in the database but not searchable
- Don't affect search scoring - won't influence relevance ranking
- Are returned in results - currently included but could be made optional
- Useful for filtering - can still filter results by metadata values using filters
- Examples: prices, stock counts, internal IDs, timestamps, flags, view counts

**When to use metadata:**
```php
$document = [
    'id' => 'product-123',
    'content' => [
        'name' => 'Wireless Headphones',
        'description' => 'High-quality Bluetooth headphones with noise cancellation',
        'brand' => 'TechAudio',
        'features' => 'bluetooth wireless noise-cancelling comfortable'
    ],
    'metadata' => [
        'price' => 149.99,           // Don't want searches for "149.99" to match
        'sku' => 'TA-WH-2024-BK',   // Internal reference code
        'stock_count' => 42,         // Numeric data not meant for text search
        'warehouse_id' => 'WH-03',   // Internal data
        'cost' => 89.50,            // Sensitive data
        'last_restock' => time()     // System tracking
    ]
];
```

This separation improves performance (less data to index), prevents false matches (searching "42" won't find products with 42 in stock), and keeps your search index focused on actual searchable content.

### SearchQuery Model

```php
// Create query
$query = new SearchQuery($queryString, $options);

// Query building
$query->limit($limit)
      ->offset($offset)
      ->inFields(['title', 'content'])
      ->filter('category', 'tech')
      ->sortBy('date', 'desc')
      ->fuzzy(true)
      ->boost('title', 2.0)
      ->highlight(true);
```

### Result Structure

Search results are returned as an associative array:

```php
[
    'results' => [
        [
            'id' => 'doc-123',
            'score' => 85.5,              // Relevance score (0-100)
            'title' => 'Document Title',   // From content fields
            'content' => '...',           // Other content fields
            'excerpt' => '...<mark>highlighted</mark>...', // With highlights if enabled
            'metadata' => [...],          // Metadata fields
            'distance' => 1234.5,         // Distance in meters (if geo search)
            // ... other fields
        ],
        // ... more results
    ],
    'total' => 42,                // Total matching documents
    'count' => 20,                // Results in this page
    'search_time' => 0.023,       // Search time in seconds
    'facets' => [...],            // If facets requested
]
```

### Performance Tips

1. **Index Configuration**
   - Use appropriate field boosts - don't over-boost
   - Only index fields you need to search
   - Use metadata for non-searchable data
   - Configure reasonable chunk sizes (default 1000 chars works well)

2. **Search Optimization**
   - Use field-specific searches when possible: `inFields(['title'])`
   - Enable `unique_by_route` (default) to avoid duplicate documents
   - Use filters instead of text queries for exact matches
   - Limit results with reasonable page sizes

3. **Storage Optimization**
   - Run `optimize()` periodically on large indexes
   - Use WAL mode for better concurrency (default)
   - Consider separate indexes for different content types

### Error Handling

```php
try {
    $results = $search->search('index-name', 'query');
} catch (\YetiSearch\Exceptions\StorageException $e) {
    // Handle storage/database errors
    error_log('Storage error: ' . $e->getMessage());
} catch (\YetiSearch\Exceptions\IndexException $e) {
    // Handle indexing errors
    error_log('Index error: ' . $e->getMessage());
} catch (\Exception $e) {
    // Handle other errors
    error_log('Search error: ' . $e->getMessage());
}
```

## Future Feature Ideas

The following features are ideas for future releases:

### Index Management Enhancements
- **Index Aliases** - Create aliases for indexes to simplify management and allow seamless index switching
- **Index Templates** - Define templates for consistent index configuration across similar content types
- **Automatic Index Routing** - Route documents to appropriate indexes based on document properties
- **Real-time Index Synchronization** - Synchronize data between multiple indexes in real-time
- **Index Versioning and Migrations** - Support for index schema evolution with migration tools

### Language and Analysis
- **Automatic Language Detection** - Detect document language automatically instead of defaulting to English
- **Custom Analyzer Plugins** - Allow custom text analysis plugins for specialized content
- **Phonetic Matching** - Support for soundex/metaphone matching for name searches
- **Synonym Support** - Configure synonyms for enhanced search matching

### Search Enhancements
- **Query DSL** - Advanced query language for complex search expressions
- **Search Templates** - Save and reuse common search patterns
- **More Like This** - Find similar documents based on content similarity
- **Search Analytics** - Built-in analytics for search queries and results
- **Full Content Result** - Option to return full document content in search results

### Performance and Scalability
- **Distributed Search** - Support for searching across multiple YetiSearch instances
- **Index Sharding** - Split large indexes across multiple shards
- **Query Caching Improvements** - More sophisticated caching strategies
- **Bulk Operations API** - Optimized bulk indexing and updates

### Integration Features
- **Webhook Support** - Notify external systems of index changes
- **Import/Export Tools** - Tools for data migration between different search systems
- **REST API** - HTTP API for remote access to YetiSearch functionality
- **GraphQL Support** - GraphQL endpoint for flexible data querying

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request. For major changes, please open an issue first to discuss what you would like to change.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Run tests (`composer test:verbose`)
4. Commit your changes (`git commit -m 'Add amazing feature'`)
5. Push to the branch (`git push origin feature/amazing-feature`)
6. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Credits

YetiSearch is maintained by the YetiSearch Team and contributors.

Special thanks to:
- The SQLite team for the excellent FTS5 extension
- The PHP community for continuous inspiration
- All contributors who help make YetiSearch better
