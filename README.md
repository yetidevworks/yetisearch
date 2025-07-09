# YetiSearch

[![CI](https://github.com/yetisearch/yetisearch/workflows/CI/badge.svg)](https://github.com/yetisearch/yetisearch/actions)
[![PHP Version](https://img.shields.io/packagist/php-v/yetisearch/yetisearch)](https://packagist.org/packages/yetisearch/yetisearch)
[![License](https://img.shields.io/github/license/yetisearch/yetisearch)](LICENSE)
[![Latest Stable Version](https://img.shields.io/packagist/v/yetisearch/yetisearch)](https://packagist.org/packages/yetisearch/yetisearch)

A powerful, pure-PHP search engine library with advanced features including:

- = Full-text search with SQLite FTS5
- =Ä Document chunking for large content
- <¯ BM25 relevance scoring
- < Multi-language support with stemming
- ¡ Fast indexing and searching
- =' Extensible architecture

## Requirements

- PHP 7.4 or higher
- SQLite3 extension
- PDO extension
- Mbstring extension

## Installation

```bash
composer require yetisearch/yetisearch
```

## Quick Start

```php
use YetiSearch\YetiSearch;

// Initialize YetiSearch
$config = [
    'storage' => [
        'path' => '/path/to/search.db'
    ]
];
$search = new YetiSearch($config);

// Create an index
$indexer = $search->createIndex('pages');

// Index documents
$indexer->index([
    'id' => 'doc1',
    'title' => 'Introduction to YetiSearch',
    'content' => 'YetiSearch is a powerful search engine...',
    'tags' => 'search php library'
]);

// Search
$results = $search->search('pages', 'powerful search');
foreach ($results['results'] as $result) {
    echo $result['title'] . ' (Score: ' . $result['score'] . ")\n";
}
```

## Features

### Document Chunking

Automatically splits large documents into manageable chunks for better search performance:

```php
$indexer = $search->createIndex('docs', [
    'chunk_size' => 1000,      // Characters per chunk
    'chunk_overlap' => 100     // Overlap between chunks
]);
```

### Multi-language Support

Built-in support for multiple languages with appropriate stemming:

```php
$search->index('pages', [
    'id' => 'doc1',
    'title' => 'Mon Document',
    'content' => 'Ceci est un document en français',
    'language' => 'french'
]);
```

### Advanced Search Options

```php
$results = $search->search('pages', 'search query', [
    'limit' => 20,
    'offset' => 0,
    'fields' => ['title', 'content'],
    'fuzzy' => true,
    'highlight' => true,
    'filters' => [
        ['field' => 'category', 'value' => 'documentation']
    ]
]);
```

## Testing

Run the test suite:

```bash
composer test
```

Run static analysis:

```bash
composer phpstan
```

## License

MIT License. See [LICENSE](LICENSE) file for details.