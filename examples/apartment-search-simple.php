<?php
/**
 * YetiSearch Tutorial: Simplified Apartment Property Search
 * 
 * This tutorial demonstrates building a real-world apartment search system
 * using YetiSearch's key features with simplified data structures.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use YetiSearch\YetiSearch;
use YetiSearch\DSL\QueryBuilder;

echo "==============================================\n";
echo "   YetiSearch Apartment Search Tutorial\n";
echo "==============================================\n\n";

// ====================================================================
// STEP 1: Initialize YetiSearch
// ====================================================================

$config = [
    'storage' => [
        'path' => 'apartments.db'
    ],
    'search' => [
        'enable_fuzzy' => true,
        'multi_column_fts' => false  // Keep it simple
    ]
];

$yetiSearch = new YetiSearch($config);
$yetiSearch->createIndex('apartments');

// ====================================================================
// STEP 2: Sample Apartment Data (Simplified)
// ====================================================================

$apartments = [
    [
        'id' => 'apt-001',
        'content' => [
            'title' => 'Luxury Downtown Penthouse',
            'description' => 'Spacious modern penthouse in downtown with stunning city views'
        ],
        'metadata' => [
            'price' => 5500,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'sqft' => 2200,
            'location' => 'Downtown'
        ],
        'geo' => ['lat' => 40.7074, 'lng' => -74.0113]
    ],
    [
        'id' => 'apt-002',
        'content' => [
            'title' => 'Cozy Brooklyn Studio',
            'description' => 'Charming studio apartment in trendy Brooklyn neighborhood'
        ],
        'metadata' => [
            'price' => 2200,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'sqft' => 450,
            'location' => 'Brooklyn'
        ],
        'geo' => ['lat' => 40.7142, 'lng' => -73.9614]
    ],
    [
        'id' => 'apt-003',
        'content' => [
            'title' => 'Family 2BR in Queens',
            'description' => 'Spacious two-bedroom apartment perfect for families'
        ],
        'metadata' => [
            'price' => 3200,
            'bedrooms' => 2,
            'bathrooms' => 2,
            'sqft' => 1100,
            'location' => 'Queens'
        ],
        'geo' => ['lat' => 40.7214, 'lng' => -73.8444]
    ],
    [
        'id' => 'apt-004',
        'content' => [
            'title' => 'Modern 1BR Loft',
            'description' => 'Industrial chic loft in converted warehouse'
        ],
        'metadata' => [
            'price' => 3800,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'sqft' => 850,
            'location' => 'DUMBO'
        ],
        'geo' => ['lat' => 40.7033, 'lng' => -73.9910]
    ],
    [
        'id' => 'apt-005',
        'content' => [
            'title' => 'Spacious 3BR Family Home',
            'description' => 'Large three-bedroom apartment with parking'
        ],
        'metadata' => [
            'price' => 3500,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'sqft' => 1400,
            'location' => 'Astoria'
        ],
        'geo' => ['lat' => 40.7764, 'lng' => -73.9125]
    ]
];

// ====================================================================
// STEP 3: Index the Apartments
// ====================================================================

echo "STEP 1: Indexing Apartments\n";
echo "----------------------------\n";

foreach ($apartments as $apt) {
    $yetiSearch->index('apartments', $apt);
    echo "✓ Indexed: {$apt['content']['title']}\n";
}

echo "\nSuccessfully indexed " . count($apartments) . " apartments\n\n";

// ====================================================================
// STEP 4: Direct API Search Examples
// ====================================================================

echo "STEP 2: Search Examples - Direct API\n";
echo "------------------------------------\n\n";

// Example 1: Basic text search
echo "1. Search for 'Brooklyn':\n";
$results = $yetiSearch->search('apartments', 'brooklyn');
echo "   Found {$results['total']} results:\n";
foreach ($results['results'] as $r) {
    $title = $r['document']['title'] ?? $r['title'] ?? 'Unknown';
    echo "   - {$title} (\${$r['metadata']['price']}/mo)\n";
}

// Example 2: Geo search with filters
// Search near Times Square/Midtown with wider radius to catch Queens and Downtown
echo "\n2. Apartments within 5 miles of Times Square (40.7580, -73.9855):\n";
echo "   With 2-3 bedrooms and >= 1100 sqft:\n";

$results = $yetiSearch->search('apartments', '', [
    'geoFilters' => [
        'near' => [
            'point' => ['lat' => 40.7580, 'lng' => -73.9855],
            'radius' => 5,
            'units' => 'mi'
        ]
    ],
    'filters' => [
        ['field' => 'metadata.bedrooms', 'value' => 2, 'operator' => '>='],
        ['field' => 'metadata.bedrooms', 'value' => 3, 'operator' => '<='],
        ['field' => 'metadata.sqft', 'value' => 1100, 'operator' => '>=']
    ],
    'sort' => ['metadata.price' => 'asc']
]);

echo "   Found {$results['total']} results:\n";
foreach ($results['results'] as $r) {
    $title = $r['document']['title'] ?? 'Unknown';
    // Distance is in meters, convert to miles
    $distance = isset($r['distance']) ? sprintf("%.1f mi", $r['distance'] / 1609.344) : 'N/A';
    echo "   - {$title}\n";
    echo "     \${$r['metadata']['price']}/mo | {$r['metadata']['bedrooms']}BR | ";
    echo "{$r['metadata']['sqft']} sqft | Distance: {$distance}\n";
}

// Example 3: Price range filter
echo "\n3. Apartments between \$2000-\$4000:\n";
$results = $yetiSearch->search('apartments', '', [
    'filters' => [
        ['field' => 'metadata.price', 'value' => 2000, 'operator' => '>='],
        ['field' => 'metadata.price', 'value' => 4000, 'operator' => '<=']
    ],
    'sort' => ['metadata.price' => 'asc']
]);

foreach ($results['results'] as $r) {
    $title = $r['document']['title'] ?? 'Unknown';
    echo "   - {$title} (\${$r['metadata']['price']}/mo, {$r['metadata']['sqft']} sqft)\n";
}

// ====================================================================
// STEP 5: DSL Query Examples
// ====================================================================

echo "\n\nSTEP 3: Search Examples - DSL\n";
echo "------------------------------\n\n";

$builder = new QueryBuilder($yetiSearch, [
    'metadata_fields' => ['price', 'bedrooms', 'bathrooms', 'sqft', 'location']
]);

// DSL Example 1: Natural language query
echo "1. DSL Query: 'loft AND bedrooms >= 1 AND price < 4000'\n";
$results = $builder->searchWithDSL('apartments', 
    'loft AND bedrooms >= 1 AND price < 4000'
);

foreach ($results['results'] as $r) {
    $title = $r['document']['title'] ?? 'Unknown';
    echo "   - {$title} (\${$r['metadata']['price']}/mo)\n";
}

// DSL Example 2: Complex conditions
echo "\n2. DSL Query: 'sqft > 800 AND price < 4000 SORT -sqft'\n";
$results = $builder->searchWithDSL('apartments',
    'sqft > 800 AND price < 4000 SORT -sqft'
);

foreach ($results['results'] as $r) {
    $title = $r['document']['title'] ?? 'Unknown';
    echo "   - {$title} ({$r['metadata']['sqft']} sqft, \${$r['metadata']['price']}/mo)\n";
}

// DSL Example 3: Location filter
echo "\n3. DSL Query: 'location IN [Brooklyn, Queens] SORT price'\n";
$results = $builder->searchWithDSL('apartments',
    'location IN [Brooklyn, Queens] SORT price'
);

foreach ($results['results'] as $r) {
    $title = $r['document']['title'] ?? 'Unknown';
    $location = $r['metadata']['location'] ?? 'Unknown';
    echo "   - {$title} in {$location} (\${$r['metadata']['price']}/mo)\n";
}

// ====================================================================
// STEP 6: Fluent Interface Examples
// ====================================================================

echo "\n\nSTEP 4: Fluent Interface\n";
echo "------------------------\n\n";

echo "1. Find 2+ bedroom apartments under \$4000:\n";
$results = $builder->query()
    ->in('apartments')
    ->where('bedrooms', 2, '>=')
    ->where('price', 4000, '<')
    ->orderBy('price', 'asc')
    ->get();

foreach ($results['results'] as $r) {
    $title = $r['document']['title'] ?? 'Unknown';
    echo "   - {$title} ({$r['metadata']['bedrooms']}BR, \${$r['metadata']['price']}/mo)\n";
}

// ====================================================================
// CLEANUP
// ====================================================================

echo "\n✓ Tutorial completed successfully!\n";
unlink('apartments.db');

echo "\nKey Takeaways:\n";
echo "--------------\n";
echo "• Separate searchable content from metadata\n";
echo "• Use geo filters for location searches\n";
echo "• DSL provides natural query syntax\n";
echo "• Combine filters for precise results\n";