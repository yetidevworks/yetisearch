# YetiSearch Multi-Index Features

This document describes the enhanced multi-index capabilities added to YetiSearch, enabling more powerful and flexible search functionality.

## Overview

YetiSearch now supports:
- Multiple named indexes with isolated data
- Metadata field filtering with JSON extraction
- Cross-index search with result merging
- Index discovery and management
- Language-specific indexing strategies

## Key Features

### 1. Multiple Indexes

Create and manage multiple search indexes for different content types or purposes:

```php
$search = new YetiSearch($config);

// Create different indexes
$search->createIndex('products');
$search->createIndex('articles');
$search->createIndex('users');

// Or create language-specific indexes
$search->createIndex('content_en');
$search->createIndex('content_fr');
$search->createIndex('content_de');
```

### 2. Enhanced Metadata Filtering

Filter search results based on metadata fields using various operators:

```php
// Filter by price (numeric comparison)
$results = $search->search('products', 'camera', [
    'filters' => [
        [
            'field' => 'metadata.price',
            'operator' => '<',
            'value' => 500
        ]
    ]
]);

// Filter by category (exact match)
$results = $search->search('products', '*', [
    'filters' => [
        [
            'field' => 'metadata.category',
            'operator' => '=',
            'value' => 'electronics'
        ]
    ]
]);

// Filter by multiple values
$results = $search->search('products', '*', [
    'filters' => [
        [
            'field' => 'metadata.brand',
            'operator' => 'in',
            'value' => ['Sony', 'Canon', 'Nikon']
        ]
    ]
]);
```

#### Supported Filter Operators

- `=` - Exact match
- `!=` - Not equal
- `>`, `<`, `>=`, `<=` - Numeric comparisons
- `in` - Match any value in array
- `contains` - Partial text match (LIKE)
- `exists` - Check if field exists

#### Metadata Field Syntax

Access metadata fields using dot notation:
- `metadata.price` - Access price field in metadata
- `metadata.author.name` - Access nested fields
- `type`, `language`, `id`, `timestamp` - Direct document fields

### 3. Cross-Index Search

Search across multiple indexes simultaneously:

```php
// Search specific indexes
$results = $search->searchMultiple(['products', 'articles'], 'technology');

// Search indexes matching a pattern
$results = $search->searchMultiple(['content_*'], 'search term');

// Access results
foreach ($results['results'] as $result) {
    echo $result['_index']; // Which index this result came from
    echo $result['content']['title'];
    echo $result['score'];
}
```

### 4. Index Discovery and Management

List and inspect all available indexes:

```php
// List all indexes with basic info
$indexes = $search->listIndices();
foreach ($indexes as $index) {
    echo $index['name'];
    echo $index['document_count'];
    echo implode(', ', $index['languages']);
    echo implode(', ', $index['types']);
}

// Get detailed statistics for an index
$stats = $search->getStats('products');
```

## Multi-Language Support Strategies

YetiSearch supports two approaches for multi-language content:

### Option 1: Single Index with Language Field

Store all languages in one index and filter by language:

```php
// Index documents with language field
$search->index('content', [
    'id' => 'doc-001',
    'content' => ['title' => 'Hello World'],
    'language' => 'en'
]);

$search->index('content', [
    'id' => 'doc-002',
    'content' => ['title' => 'Bonjour le Monde'],
    'language' => 'fr'
]);

// Search in specific language
$results = $search->search('content', 'hello', [
    'language' => 'en'
]);
```

### Option 2: Separate Indexes per Language

Create dedicated indexes for each language:

```php
// Create language-specific indexes
$search->createIndex('content_en');
$search->createIndex('content_fr');

// Index to appropriate language index
$search->index('content_en', $englishDocument);
$search->index('content_fr', $frenchDocument);

// Search across all language indexes
$results = $search->searchMultiple(['content_*'], 'search term');
```

## Best Practices

### 1. Index Naming Conventions

Use descriptive names that indicate content type and purpose:
- `products` - General product catalog
- `products_archived` - Archived products
- `blog_posts_en` - English blog posts
- `docs_v2` - Documentation version 2

### 2. Metadata Structure

Design consistent metadata schemas:

```php
// Good: Consistent structure
$productMetadata = [
    'category' => 'electronics',
    'price' => 299.99,
    'brand' => 'TechCorp',
    'in_stock' => true,
    'tags' => ['wireless', 'bluetooth']
];

// Good: Nested organization
$articleMetadata = [
    'author' => [
        'id' => 'user-123',
        'name' => 'John Doe'
    ],
    'publication' => [
        'date' => '2024-01-15',
        'status' => 'published'
    ]
];
```

### 3. Performance Considerations

- **Index Size**: Keep indexes focused on specific content types
- **Metadata Filtering**: Index frequently filtered fields for better performance
- **Cross-Index Search**: Limit the number of indexes searched simultaneously
- **Result Limits**: Use appropriate limits when searching multiple indexes

### 4. Migration Strategy

When implementing multi-index architecture:

1. Start with a single index for testing
2. Identify logical content groupings
3. Create new indexes based on:
   - Content type (products, articles, users)
   - Language (en, fr, de)
   - Time period (current, archived)
   - Access level (public, private)
4. Migrate data incrementally
5. Update search queries to use appropriate indexes

## Example Use Cases

### E-commerce Platform

```php
// Product search with inventory filtering
$inStockProducts = $search->search('products', 'laptop', [
    'filters' => [
        ['field' => 'metadata.in_stock', 'operator' => '=', 'value' => true],
        ['field' => 'metadata.price', 'operator' => '<=', 'value' => 1500]
    ]
]);

// Search across product categories
$results = $search->searchMultiple([
    'products_electronics',
    'products_accessories',
    'products_refurbished'
], 'wireless charging');
```

### Content Management System

```php
// Find recent articles by author
$articles = $search->search('articles', '*', [
    'filters' => [
        ['field' => 'metadata.author.id', 'operator' => '=', 'value' => 'auth-123'],
        ['field' => 'timestamp', 'operator' => '>', 'value' => strtotime('-30 days')]
    ],
    'sort' => ['timestamp' => 'desc']
]);

// Search all content types
$results = $search->searchMultiple(['articles', 'pages', 'posts'], 'climate change');
```

### Multi-tenant Application

```php
// Tenant-specific indexes
$search->createIndex('tenant_123_products');
$search->createIndex('tenant_456_products');

// Search within tenant
$results = $search->search('tenant_123_products', 'search query');

// Admin search across all tenants
$results = $search->searchMultiple(['tenant_*_products'], 'compliance issue');
```

## Technical Implementation Details

### Storage Layer

The `SqliteStorage` class implements:
- **listIndices()**: Queries SQLite master table for valid indexes
- **searchMultiple()**: Executes searches on each index and merges results
- **Metadata filtering**: Uses SQLite's JSON1 extension for JSON field extraction

### Index Structure

Each index consists of three tables:
- `{index_name}`: Main document storage with metadata as JSON
- `{index_name}_fts`: FTS5 virtual table for full-text search
- `{index_name}_terms`: Term frequency and position tracking

### Query Processing

1. **Filter Translation**: Metadata filters are converted to SQL JSON operations
2. **Result Merging**: Cross-index results are merged and re-sorted by relevance
3. **Index Discovery**: Pattern matching supports wildcards for flexible index selection

## Backward Compatibility

All existing YetiSearch functionality remains unchanged:
- Single index operations work as before
- Existing search queries don't require modification
- New features are opt-in through additional parameters

## Future Enhancements

Potential improvements for consideration:
- Index aliases for easier management
- Index templates for consistent configuration
- Automatic index routing based on document properties
- Real-time index synchronization
- Index versioning and migrations