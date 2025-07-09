<?php

/**
 * YetiSearch Multi-Index Usage Examples
 * 
 * This file demonstrates the enhanced multi-index capabilities of YetiSearch,
 * including metadata filtering, cross-index search, and language-specific indexing.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use YetiSearch\YetiSearch;

// Initialize YetiSearch
$config = [
    'storage' => [
        'path' => __DIR__ . '/data/search.db'
    ],
    'analyzer' => [
        'min_word_length' => 3,
        'strip_html' => true,
        'remove_stop_words' => true
    ]
];

$search = new YetiSearch($config);

// Example 1: Creating Multiple Indexes for Different Content Types
echo "=== Example 1: Creating Multiple Indexes ===\n";

// Create separate indexes for different content types
$search->createIndex('products');
$search->createIndex('blog_posts');
$search->createIndex('documentation');

// Create language-specific indexes
$search->createIndex('products_en');
$search->createIndex('products_fr');
$search->createIndex('products_de');

echo "Indexes created successfully.\n\n";

// Example 2: Indexing Documents with Metadata
echo "=== Example 2: Indexing Documents with Metadata ===\n";

// Index products with rich metadata
$products = [
    [
        'id' => 'prod-001',
        'content' => [
            'title' => 'Professional DSLR Camera',
            'description' => 'High-resolution digital camera with advanced features for professional photography',
            'specs' => '24MP sensor, 4K video, weather-sealed body'
        ],
        'metadata' => [
            'category' => 'electronics',
            'price' => 1299.99,
            'brand' => 'CameraPro',
            'in_stock' => true,
            'rating' => 4.5,
            'tags' => ['camera', 'photography', 'professional']
        ],
        'type' => 'product',
        'language' => 'en'
    ],
    [
        'id' => 'prod-002',
        'content' => [
            'title' => 'Wireless Noise-Canceling Headphones',
            'description' => 'Premium headphones with active noise cancellation and superior audio quality',
            'specs' => 'Bluetooth 5.0, 30-hour battery, ANC technology'
        ],
        'metadata' => [
            'category' => 'audio',
            'price' => 299.99,
            'brand' => 'AudioTech',
            'in_stock' => false,
            'rating' => 4.8,
            'tags' => ['headphones', 'audio', 'wireless']
        ],
        'type' => 'product',
        'language' => 'en'
    ]
];

foreach ($products as $product) {
    $search->index('products', $product);
}

// Index blog posts
$blogPosts = [
    [
        'id' => 'blog-001',
        'content' => [
            'title' => 'Getting Started with Machine Learning',
            'body' => 'Machine learning is transforming how we build applications...',
            'excerpt' => 'An introduction to ML concepts and tools'
        ],
        'metadata' => [
            'author' => 'Jane Smith',
            'published_date' => '2024-01-15',
            'category' => 'technology',
            'read_time' => 5,
            'views' => 1523
        ],
        'type' => 'article',
        'language' => 'en'
    ]
];

foreach ($blogPosts as $post) {
    $search->index('blog_posts', $post);
}

echo "Documents indexed successfully.\n\n";

// Example 3: Searching with Metadata Filters
echo "=== Example 3: Searching with Metadata Filters ===\n";

// Search for products under $500
$results = $search->search('products', 'camera', [
    'filters' => [
        [
            'field' => 'metadata.price',
            'operator' => '<',
            'value' => 500
        ]
    ]
]);

echo "Products under $500:\n";
print_r($results);

// Search for in-stock products
$results = $search->search('products', 'headphones', [
    'filters' => [
        [
            'field' => 'metadata.in_stock',
            'operator' => '=',
            'value' => true
        ]
    ]
]);

echo "\nIn-stock products:\n";
print_r($results);

// Search with multiple filters
$results = $search->search('products', '*', [
    'filters' => [
        [
            'field' => 'metadata.category',
            'operator' => '=',
            'value' => 'electronics'
        ],
        [
            'field' => 'metadata.rating',
            'operator' => '>=',
            'value' => 4.0
        ]
    ]
]);

echo "\nHigh-rated electronics:\n";
print_r($results);

// Example 4: Cross-Index Search
echo "\n=== Example 4: Cross-Index Search ===\n";

// Search across multiple specific indexes
$results = $search->searchMultiple(['products', 'blog_posts'], 'technology', [
    'limit' => 10
]);

echo "Results from products and blog posts:\n";
foreach ($results['results'] as $result) {
    echo "- [{$result['_index']}] {$result['content']['title']} (Score: {$result['score']})\n";
}

// Search across indexes matching a pattern
$results = $search->searchMultiple(['products_*'], 'camera', [
    'limit' => 20
]);

echo "\nResults from all product language indexes:\n";
print_r($results['indices_searched']);

// Example 5: Language-Specific Indexing
echo "\n=== Example 5: Language-Specific Indexing ===\n";

// Index content in different languages
$multilingualProducts = [
    [
        'id' => 'prod-001-en',
        'content' => [
            'title' => 'Professional Camera',
            'description' => 'Advanced digital camera for professionals'
        ],
        'metadata' => ['sku' => 'CAM-001'],
        'language' => 'en'
    ],
    [
        'id' => 'prod-001-fr',
        'content' => [
            'title' => 'Appareil Photo Professionnel',
            'description' => 'Appareil photo numérique avancé pour professionnels'
        ],
        'metadata' => ['sku' => 'CAM-001'],
        'language' => 'fr'
    ],
    [
        'id' => 'prod-001-de',
        'content' => [
            'title' => 'Professionelle Kamera',
            'description' => 'Fortschrittliche Digitalkamera für Profis'
        ],
        'metadata' => ['sku' => 'CAM-001'],
        'language' => 'de'
    ]
];

// Option 1: Single index with language field
foreach ($multilingualProducts as $product) {
    $search->index('products', $product);
}

// Search in specific language
$results = $search->search('products', 'camera', [
    'language' => 'en'
]);

echo "English results only:\n";
print_r($results);

// Option 2: Separate indexes per language
$search->index('products_en', $multilingualProducts[0]);
$search->index('products_fr', $multilingualProducts[1]);
$search->index('products_de', $multilingualProducts[2]);

// Example 6: Advanced Metadata Filtering
echo "\n=== Example 6: Advanced Metadata Filtering ===\n";

// Filter with 'in' operator
$results = $search->search('products', '*', [
    'filters' => [
        [
            'field' => 'metadata.category',
            'operator' => 'in',
            'value' => ['electronics', 'audio']
        ]
    ]
]);

echo "Products in electronics or audio categories:\n";
print_r($results);

// Filter with 'contains' operator for text search in metadata
$results = $search->search('blog_posts', '*', [
    'filters' => [
        [
            'field' => 'metadata.author',
            'operator' => 'contains',
            'value' => 'Smith'
        ]
    ]
]);

echo "\nPosts by authors containing 'Smith':\n";
print_r($results);

// Filter checking if metadata field exists
$results = $search->search('products', '*', [
    'filters' => [
        [
            'field' => 'metadata.discount',
            'operator' => 'exists'
        ]
    ]
]);

echo "\nProducts with discount information:\n";
print_r($results);

// Example 7: Listing and Managing Indexes
echo "\n=== Example 7: Listing and Managing Indexes ===\n";

// List all indexes with their statistics
$indexes = $search->listIndices();

echo "Available indexes:\n";
foreach ($indexes as $index) {
    echo "- {$index['name']}: {$index['document_count']} documents\n";
    if (!empty($index['languages'])) {
        echo "  Languages: " . implode(', ', $index['languages']) . "\n";
    }
    if (!empty($index['types'])) {
        echo "  Types: " . implode(', ', $index['types']) . "\n";
    }
}

// Get detailed stats for a specific index
$stats = $search->getStats('products');
echo "\nDetailed stats for 'products' index:\n";
print_r($stats);

// Example 8: Complex Multi-Index Search Scenarios
echo "\n=== Example 8: Complex Multi-Index Search Scenarios ===\n";

// Search across all indexes for urgent content
$allIndexes = array_column($search->listIndices(), 'name');
$results = $search->searchMultiple($allIndexes, 'important urgent', [
    'filters' => [
        [
            'field' => 'metadata.priority',
            'operator' => '=',
            'value' => 'high'
        ]
    ],
    'limit' => 50
]);

echo "High-priority content across all indexes:\n";
echo "Searched indexes: " . implode(', ', $results['indices_searched']) . "\n";
echo "Total results: {$results['total']}\n";

// Clean up (optional)
echo "\n=== Cleanup ===\n";
// $search->clear('products');
// $search->dropIndex('products');
echo "Example completed!\n";