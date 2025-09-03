<?php
/**
 * YetiSearch DSL - Metadata Fields Example
 * 
 * This example demonstrates how to properly use metadata fields for
 * filtering and sorting in YetiSearch with the DSL QueryBuilder.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use YetiSearch\YetiSearch;
use YetiSearch\DSL\QueryBuilder;

// Initialize YetiSearch
$config = [
    'storage' => ['path' => 'search_example.db']
];
$yetiSearch = new YetiSearch($config);

// Configure QueryBuilder with custom metadata fields
// This example shows an e-commerce product catalog
$builder = new QueryBuilder($yetiSearch, [
    'metadata_fields' => [
        // Add custom fields specific to your domain
        'brand', 'manufacturer', 'model', 'sku',
        'price', 'sale_price', 'discount_percentage',
        'stock_quantity', 'warehouse_location',
        'weight', 'dimensions', 'color', 'size',
        'release_date', 'warranty_months',
        'is_featured', 'is_on_sale', 'is_new_arrival',
        'seller_id', 'fulfillment_method'
    ]
]);

// Create index for products
$yetiSearch->createIndex('products');

// Index products with proper content/metadata separation
$products = [
    [
        'id' => 'SKU-12345',
        'content' => [
            // Searchable text fields (for full-text search)
            'title' => 'Professional DSLR Camera',
            'description' => 'High-resolution digital SLR camera with advanced autofocus system',
            'features' => 'Weather-sealed body, 4K video recording, dual memory card slots',
            'keywords' => 'photography camera DSLR professional photographer'
        ],
        'metadata' => [
            // Filterable/sortable fields (for precise filtering and sorting)
            'brand' => 'CameraTech',
            'model' => 'CT-9000',
            'price' => 2499.99,
            'sale_price' => 1999.99,
            'discount_percentage' => 20,
            'stock_quantity' => 15,
            'warehouse_location' => 'NYC-01',
            'weight' => 1.2, // kg
            'color' => 'Black',
            'release_date' => '2024-03-15',
            'warranty_months' => 24,
            'rating' => 4.7,
            'review_count' => 234,
            'is_featured' => true,
            'is_on_sale' => true,
            'seller_id' => 'seller_123',
            'category' => 'Electronics',
            'subcategory' => 'Cameras'
        ]
    ],
    [
        'id' => 'SKU-67890',
        'content' => [
            'title' => 'Compact Mirrorless Camera',
            'description' => 'Lightweight mirrorless camera perfect for travel photography',
            'features' => 'In-body stabilization, touchscreen LCD, WiFi connectivity',
            'keywords' => 'mirrorless compact travel camera portable'
        ],
        'metadata' => [
            'brand' => 'PhotoPro',
            'model' => 'PP-500',
            'price' => 899.99,
            'sale_price' => null,
            'discount_percentage' => 0,
            'stock_quantity' => 42,
            'warehouse_location' => 'LAX-02',
            'weight' => 0.4,
            'color' => 'Silver',
            'release_date' => '2024-01-10',
            'warranty_months' => 12,
            'rating' => 4.3,
            'review_count' => 89,
            'is_featured' => false,
            'is_on_sale' => false,
            'seller_id' => 'seller_456',
            'category' => 'Electronics',
            'subcategory' => 'Cameras'
        ]
    ]
];

// Index the products
foreach ($products as $product) {
    $yetiSearch->index('products', $product);
}

echo "=== YetiSearch DSL - Metadata Fields Examples ===\n\n";

// Example 1: Filter by price range
echo "1. Products under $1500:\n";
$results = $builder->searchWithDSL('products', 'price < 1500');
foreach ($results['results'] as $r) {
    echo "   - {$r['title']} (${$r['metadata']['price']})\n";
}

// Example 2: Multiple metadata filters
echo "\n2. On-sale products with high ratings:\n";
$results = $builder->searchWithDSL('products', 
    'is_on_sale = true AND rating >= 4.5 SORT -discount_percentage'
);
foreach ($results['results'] as $r) {
    echo "   - {$r['title']} ({$r['metadata']['discount_percentage']}% off, rating: {$r['metadata']['rating']})\n";
}

// Example 3: Combining text search with metadata filters
echo "\n3. Search 'camera' with filters and sorting:\n";
$results = $builder->searchWithDSL('products',
    'camera AND stock_quantity > 10 AND warranty_months >= 12 SORT -rating LIMIT 5'
);
foreach ($results['results'] as $r) {
    echo "   - {$r['title']} (stock: {$r['metadata']['stock_quantity']}, rating: {$r['metadata']['rating']})\n";
}

// Example 4: Using IN operator with metadata
echo "\n4. Products from specific warehouses:\n";
$results = $builder->searchWithDSL('products',
    'warehouse_location IN [NYC-01, LAX-02] SORT stock_quantity'
);
foreach ($results['results'] as $r) {
    echo "   - {$r['title']} (warehouse: {$r['metadata']['warehouse_location']}, stock: {$r['metadata']['stock_quantity']})\n";
}

// Example 5: Date-based filtering
echo "\n5. New arrivals (released after 2024-02-01):\n";
$results = $builder->searchWithDSL('products',
    'release_date > "2024-02-01" SORT -release_date'
);
foreach ($results['results'] as $r) {
    echo "   - {$r['title']} (released: {$r['metadata']['release_date']})\n";
}

// Example 6: Using URL query parameters (JSON API style)
echo "\n6. URL query parameters example:\n";
$urlQuery = http_build_query([
    'q' => 'camera',
    'filter' => [
        'brand' => ['eq' => 'CameraTech'],
        'price' => ['lte' => 2500],
        'is_featured' => ['eq' => 'true']
    ],
    'sort' => '-rating',
    'page' => ['limit' => 10]
]);
$results = $builder->searchWithURL('products', $urlQuery);
foreach ($results['results'] as $r) {
    echo "   - {$r['title']} ({$r['metadata']['brand']}, ${$r['metadata']['price']})\n";
}

// Example 7: Fluent interface with metadata
echo "\n7. Fluent interface example:\n";
$results = $builder->query('camera')
    ->in('products')
    ->where('stock_quantity', 5, '>')
    ->where('rating', 4.0, '>=')
    ->whereIn('color', ['Black', 'Silver'])
    ->orderBy('price', 'asc')
    ->limit(3)
    ->get();
foreach ($results['results'] as $r) {
    echo "   - {$r['title']} ({$r['metadata']['color']}, ${$r['metadata']['price']})\n";
}

// Example 8: Adding custom metadata fields on the fly
echo "\n8. Adding custom metadata fields:\n";
$builder->addMetadataField('supplier_code')
        ->addMetadataField('import_batch');

// Now you can use these new fields in queries
// $results = $builder->searchWithDSL('products', 'supplier_code = "SUP-123"');

echo "\n=== Configuration Tips ===\n";
echo "1. Always store filterable/sortable fields in 'metadata' array\n";
echo "2. Store searchable text content in 'content' array\n";
echo "3. Configure metadata_fields for automatic prefix handling\n";
echo "4. Use explicit 'metadata.' prefix for non-configured fields\n";
echo "5. Numeric comparisons work best with actual numbers, not strings\n";

// Clean up
unlink('search_example.db');

echo "\n✓ Example completed successfully!\n";