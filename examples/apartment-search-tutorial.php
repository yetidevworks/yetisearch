<?php
/**
 * YetiSearch Tutorial: Apartment Property Search
 * 
 * This comprehensive tutorial demonstrates building a real-world apartment
 * search system using YetiSearch, showcasing all major features including:
 * - Full-text search
 * - Metadata filtering
 * - Geo-spatial queries
 * - Faceted search
 * - DSL and direct API approaches
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
        'path' => 'apartments.db',  // SQLite database file
        'options' => [
            'enable_wal' => true,    // Better concurrent performance
        ]
    ],
    'search' => [
        'multi_column_fts' => false,  // Disable to avoid issues with numeric fields
        'enable_fuzzy' => true,      // Typo tolerance
        'fuzziness' => 0.8,         // Fuzzy threshold
        'enable_highlighting' => true,
        'enable_suggestions' => true,
        'enable_synonyms' => true,
        'synonyms' => [
            'apt' => ['apartment', 'flat', 'unit'],
            'br' => ['bedroom', 'bed'],
            'ba' => ['bathroom', 'bath'],
            'sq ft' => ['sqft', 'square feet', 'square foot']
        ]
    ],
    'geo' => [
        'units' => 'mi'  // Use miles for distance calculations
    ]
];

$yetiSearch = new YetiSearch($config);

// Create index
$yetiSearch->createIndex('apartments');

// ====================================================================
// STEP 2: Prepare Sample Apartment Data
// ====================================================================

$apartments = [
    [
        'id' => 'apt-001',
        'content' => [
            'title' => 'Luxury Downtown Penthouse with Stunning City Views',
            'description' => 'Spacious modern penthouse in the heart of downtown. Floor-to-ceiling windows, gourmet kitchen, and private terrace. Walking distance to restaurants, shopping, and entertainment.',
            'amenities' => 'gym pool doorman concierge parking garage rooftop deck',
            'neighborhood' => 'Downtown Financial District'
        ],
        'metadata' => [
            'address' => '100 Financial Plaza, New York, NY 10004',
            'price' => 5500,
            'bedrooms' => 3,
            'bathrooms' => 2,  // Use integer for simplicity
            'sqft' => 2200,
            'pet_friendly' => true,
            'parking_included' => true,
            'available_date' => '2024-02-01',
            'property_type' => 'penthouse',
            'lease_term' => 12,
            'furnished' => false,
            'utilities_included' => false,
            'building_age' => 5,
            'floor' => 25,
            'laundry' => 'in-unit'
        ],
        'geo' => [
            'lat' => 40.7074,
            'lng' => -74.0113
        ]
    ],
    [
        'id' => 'apt-002',
        'content' => [
            'title' => 'Cozy Studio in Trendy Brooklyn Neighborhood',
            'description' => 'Charming studio apartment with exposed brick walls and hardwood floors. Recently renovated kitchen and bathroom. Great for young professionals.',
            'amenities' => 'laundry basement storage bike room',
            'neighborhood' => 'Williamsburg Brooklyn'
        ],
        'metadata' => [
            'address' => '234 Bedford Ave, Brooklyn, NY 11211',
            'price' => 2200,
            'bedrooms' => 1,  // Studio counted as 1 bedroom for simplicity
            'bathrooms' => 1,
            'sqft' => 450,
            'pet_friendly' => false,
            'parking_included' => false,
            'available_date' => '2024-01-15',
            'property_type' => 'studio',
            'lease_term' => 12,
            'furnished' => true,
            'utilities_included' => true,
            'building_age' => 45,
            'floor' => 3,
            'laundry' => 'in-building'
        ],
        'geo' => [
            'lat' => 40.7142,
            'lng' => -73.9614
        ]
    ],
    [
        'id' => 'apt-003',
        'content' => [
            'title' => 'Family-Friendly 2BR Garden Apartment in Queens',
            'description' => 'Spacious two-bedroom apartment with private garden access. Perfect for families. Near excellent schools and parks. Quiet residential street.',
            'amenities' => 'garden patio storage washer dryer dishwasher',
            'neighborhood' => 'Forest Hills Queens'
        ],
        'metadata' => [
            'address' => '78-20 Austin St, Forest Hills, NY 11375',
            'price' => 3200,
            'bedrooms' => 2,
            'bathrooms' => 1,
            'sqft' => 1100,
            'pet_friendly' => true,
            'parking_included' => true,
            'available_date' => '2024-02-15',
            'property_type' => 'apartment',
            'lease_term' => 24,
            'furnished' => false,
            'utilities_included' => false,
            'building_age' => 25,
            'floor' => 1,
            'laundry' => 'in-unit'
        ],
        'geo' => [
            'lat' => 40.7214,
            'lng' => -73.8444
        ]
    ],
    [
        'id' => 'apt-004',
        'content' => [
            'title' => 'Modern 1BR Loft in Converted Warehouse',
            'description' => 'Industrial chic loft with high ceilings, exposed ductwork, and large windows. Open floor plan with modern finishes. Hip neighborhood with cafes and galleries.',
            'amenities' => 'gym rooftop common area bike storage',
            'neighborhood' => 'DUMBO Brooklyn'
        ],
        'metadata' => [
            'address' => '55 Water St, Brooklyn, NY 11201',
            'price' => 3800,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'sqft' => 850,
            'pet_friendly' => true,
            'parking_included' => false,
            'available_date' => '2024-01-20',
            'property_type' => 'loft',
            'lease_term' => 12,
            'furnished' => false,
            'utilities_included' => false,
            'building_age' => 10,
            'floor' => 4,
            'laundry' => 'in-building'
        ],
        'geo' => [
            'lat' => 40.7033,
            'lng' => -73.9910
        ]
    ],
    [
        'id' => 'apt-005',
        'content' => [
            'title' => 'Spacious 3BR Family Home in Astoria',
            'description' => 'Large three-bedroom apartment perfect for families. Updated kitchen, two full bathrooms, and plenty of closet space. Near subway and local shops.',
            'amenities' => 'backyard deck storage basement parking',
            'neighborhood' => 'Astoria Queens'
        ],
        'metadata' => [
            'address' => '31-15 Ditmars Blvd, Astoria, NY 11105',
            'price' => 3500,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'sqft' => 1400,
            'pet_friendly' => true,
            'parking_included' => true,
            'available_date' => '2024-03-01',
            'property_type' => 'apartment',
            'lease_term' => 12,
            'furnished' => false,
            'utilities_included' => false,
            'building_age' => 35,
            'floor' => 2,
            'laundry' => 'in-basement'
        ],
        'geo' => [
            'lat' => 40.7764,
            'lng' => -73.9125
        ]
    ],
    [
        'id' => 'apt-006',
        'content' => [
            'title' => 'Affordable 1BR in Upper Manhattan',
            'description' => 'Clean and comfortable one-bedroom apartment in Washington Heights. Great value, near subway and Columbia Medical Center. Building has elevator.',
            'amenities' => 'elevator laundry super on-site',
            'neighborhood' => 'Washington Heights Manhattan'
        ],
        'metadata' => [
            'address' => '560 W 180th St, New York, NY 10033',
            'price' => 2100,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'sqft' => 650,
            'pet_friendly' => false,
            'parking_included' => false,
            'available_date' => '2024-01-25',
            'property_type' => 'apartment',
            'lease_term' => 12,
            'furnished' => false,
            'utilities_included' => false,
            'building_age' => 55,
            'floor' => 5,
            'laundry' => 'in-building'
        ],
        'geo' => [
            'lat' => 40.8496,
            'lng' => -73.9339
        ]
    ]
];

// ====================================================================
// STEP 3: Index the Apartments
// ====================================================================

echo "STEP 1: Indexing Apartments\n";
echo "----------------------------\n\n";

// Index apartments one by one
foreach ($apartments as $apt) {
    $yetiSearch->index('apartments', $apt);
    echo "✓ Indexed: {$apt['content']['title']}\n";
}

echo "\n✓ Successfully indexed " . count($apartments) . " apartments\n\n";

// ====================================================================
// STEP 4: Search Examples - Direct API Approach
// ====================================================================

echo "STEP 2: Search Examples - Direct API\n";
echo "------------------------------------\n\n";

// Example 1: Basic text search
echo "1. Basic text search for 'brooklyn':\n";
$results = $yetiSearch->search('apartments', 'brooklyn');
echo "   Found {$results['total']} results\n";
foreach ($results['results'] as $r) {
    echo "   - {$r['title']} (\${$r['metadata']['price']}/mo)\n";
}

// Example 2: Complex filtering with geo query
echo "\n2. Apartments within 3 miles of Times Square (40.7580, -73.9855):\n";
echo "   With 2-3 bedrooms, at least 1.5 bathrooms, and > 1000 sqft:\n\n";

$results = $yetiSearch->search('apartments', '', [
    'geoFilters' => [
        'near' => [
            'point' => ['lat' => 40.7580, 'lng' => -73.9855],
            'radius' => 3,
            'units' => 'mi'
        ]
    ],
    'filters' => [
        ['field' => 'metadata.bedrooms', 'value' => 2, 'operator' => '>='],
        ['field' => 'metadata.bedrooms', 'value' => 3, 'operator' => '<='],
        ['field' => 'metadata.bathrooms', 'value' => 2, 'operator' => '>='],
        ['field' => 'metadata.sqft', 'value' => 1000, 'operator' => '>']
    ],
    'sort' => ['metadata.price' => 'asc']
]);

echo "   Found {$results['total']} results:\n";
foreach ($results['results'] as $r) {
    $distance = isset($r['distance']) ? sprintf("%.1f mi", $r['distance']) : 'N/A';
    echo "   - {$r['title']}\n";
    echo "     \${$r['metadata']['price']}/mo | {$r['metadata']['bedrooms']}BR/{$r['metadata']['bathrooms']}BA | ";
    echo "{$r['metadata']['sqft']} sqft | Distance: {$distance}\n\n";
}

// Example 3: Faceted search to see distribution
echo "3. Get price distribution (facets):\n";
$results = $yetiSearch->search('apartments', '', [
    'facets' => [
        'metadata.bedrooms' => [],
        'metadata.property_type' => [],
        'metadata.pet_friendly' => []
    ],
    'limit' => 0  // Don't return results, just facets
]);

echo "   Bedrooms distribution:\n";
foreach ($results['facets']['metadata.bedrooms'] as $value => $count) {
    $label = $value == 0 ? 'Studio' : "{$value}BR";
    echo "     - {$label}: {$count} apartments\n";
}

echo "\n   Property types:\n";
foreach ($results['facets']['metadata.property_type'] as $type => $count) {
    echo "     - {$type}: {$count}\n";
}

echo "\n   Pet-friendly:\n";
foreach ($results['facets']['metadata.pet_friendly'] as $value => $count) {
    $label = $value ? 'Yes' : 'No';
    echo "     - {$label}: {$count} apartments\n";
}

// Example 4: Price range search with amenities
echo "\n4. Apartments $2000-$4000 with parking:\n";
$results = $yetiSearch->search('apartments', 'parking', [
    'filters' => [
        ['field' => 'metadata.price', 'value' => 2000, 'operator' => '>='],
        ['field' => 'metadata.price', 'value' => 4000, 'operator' => '<='],
        ['field' => 'metadata.parking_included', 'value' => true, 'operator' => '=']
    ],
    'sort' => ['metadata.price' => 'asc'],
    'highlight' => true
]);

foreach ($results['results'] as $r) {
    echo "   - {$r['title']} (\${$r['metadata']['price']}/mo)\n";
    if (isset($r['highlight'])) {
        echo "     Match: " . strip_tags($r['highlight']) . "\n";
    }
}

// ====================================================================
// STEP 5: Search Examples - DSL Approach
// ====================================================================

echo "\n\nSTEP 3: Search Examples - DSL QueryBuilder\n";
echo "-------------------------------------------\n\n";

// Initialize QueryBuilder with apartment-specific metadata fields
$builder = new QueryBuilder($yetiSearch, [
    'metadata_fields' => [
        'address', 'price', 'bedrooms', 'bathrooms', 'sqft',
        'pet_friendly', 'parking_included', 'available_date',
        'property_type', 'lease_term', 'furnished', 'utilities_included',
        'building_age', 'floor', 'laundry'
    ]
]);

// DSL Example 1: Natural language query
echo "1. DSL Natural Language Query:\n";
echo "   Query: 'brooklyn AND bedrooms >= 1 AND price < 3500 SORT price'\n\n";

$results = $builder->searchWithDSL('apartments', 
    'brooklyn AND bedrooms >= 1 AND price < 3500 SORT price'
);

foreach ($results['results'] as $r) {
    echo "   - {$r['title']}\n";
    echo "     \${$r['metadata']['price']}/mo | {$r['metadata']['bedrooms']}BR | {$r['metadata']['sqft']} sqft\n\n";
}

// DSL Example 2: Complex query with multiple conditions
echo "2. DSL Complex Query:\n";
echo "   Query: 'modern OR luxury AND sqft > 800 AND bathrooms >= 1 AND pet_friendly = true SORT -sqft LIMIT 3'\n\n";

$results = $builder->searchWithDSL('apartments',
    'modern OR luxury AND sqft > 800 AND bathrooms >= 1 AND pet_friendly = true SORT -sqft LIMIT 3'
);

foreach ($results['results'] as $r) {
    echo "   - {$r['title']}\n";
    echo "     {$r['metadata']['sqft']} sqft | {$r['metadata']['bathrooms']} BA | ";
    echo "Pet-friendly: " . ($r['metadata']['pet_friendly'] ? 'Yes' : 'No') . "\n\n";
}

// DSL Example 3: Using IN operator
echo "3. DSL IN Operator:\n";
echo "   Query: 'property_type IN [loft, penthouse, studio] SORT price'\n\n";

$results = $builder->searchWithDSL('apartments',
    'property_type IN [loft, penthouse, studio] SORT price'
);

foreach ($results['results'] as $r) {
    echo "   - {$r['title']} ({$r['metadata']['property_type']})\n";
    echo "     \${$r['metadata']['price']}/mo\n\n";
}

// ====================================================================
// STEP 6: Fluent Interface Examples
// ====================================================================

echo "STEP 4: Fluent Interface Examples\n";
echo "----------------------------------\n\n";

// Fluent Example 1: Chained query building
echo "1. Find 2-bedroom apartments under $3500 with laundry:\n\n";

$results = $builder->query('laundry')
    ->in('apartments')
    ->where('bedrooms', 2, '=')
    ->where('price', 3500, '<')
    ->whereNotNull('laundry')
    ->orderBy('price', 'asc')
    ->limit(5)
    ->get();

foreach ($results['results'] as $r) {
    echo "   - {$r['title']}\n";
    echo "     \${$r['metadata']['price']}/mo | Laundry: {$r['metadata']['laundry']}\n\n";
}

// Fluent Example 2: Available soon apartments
echo "2. Apartments available before Feb 1, 2024:\n\n";

$results = $builder->query()
    ->in('apartments')
    ->where('available_date', '2024-02-01', '<')
    ->where('furnished', false, '=')
    ->orderBy('available_date', 'asc')
    ->get();

foreach ($results['results'] as $r) {
    echo "   - {$r['title']}\n";
    echo "     Available: {$r['metadata']['available_date']} | ";
    echo "Furnished: " . ($r['metadata']['furnished'] ? 'Yes' : 'No') . "\n\n";
}

// ====================================================================
// STEP 7: Advanced Geo Query with Distance Sorting
// ====================================================================

echo "STEP 5: Advanced Geo Queries\n";
echo "-----------------------------\n\n";

// Find apartments near Central Park and sort by distance
$centralParkLat = 40.7829;
$centralParkLng = -73.9654;

echo "1. Apartments within 2 miles of Central Park, sorted by distance:\n\n";

$results = $yetiSearch->search('apartments', '', [
    'geoFilters' => [
        'near' => [
            'point' => ['lat' => $centralParkLat, 'lng' => $centralParkLng],
            'radius' => 2,
            'units' => 'mi'
        ],
        'distance_sort' => [
            'point' => ['lat' => $centralParkLat, 'lng' => $centralParkLng],
            'direction' => 'asc'
        ]
    ]
]);

foreach ($results['results'] as $r) {
    $distance = isset($r['distance']) ? sprintf("%.2f mi", $r['distance']) : 'N/A';
    echo "   - {$r['title']}\n";
    echo "     Distance from Central Park: {$distance}\n";
    echo "     \${$r['metadata']['price']}/mo | {$r['metadata']['bedrooms']}BR\n\n";
}

// ====================================================================
// STEP 8: URL Query Parameters (REST API style)
// ====================================================================

echo "STEP 6: URL Query Parameters (JSON API Style)\n";
echo "----------------------------------------------\n\n";

// Build a URL query string
$urlParams = http_build_query([
    'q' => 'apartment',
    'filter' => [
        'bedrooms' => ['gte' => 1],
        'bedrooms' => ['lte' => 2],
        'price' => ['lt' => 3500],
        'pet_friendly' => ['eq' => 'true']
    ],
    'sort' => '-sqft',
    'page' => [
        'limit' => 3
    ]
]);

echo "URL Query: ?{$urlParams}\n\n";

$results = $builder->searchWithURL('apartments', $urlParams);

echo "Results:\n";
foreach ($results['results'] as $r) {
    echo "   - {$r['title']}\n";
    echo "     {$r['metadata']['sqft']} sqft | {$r['metadata']['bedrooms']}BR | ";
    echo "Pet-friendly: " . ($r['metadata']['pet_friendly'] ? 'Yes' : 'No') . "\n\n";
}

// ====================================================================
// STEP 9: Suggestions Feature
// ====================================================================

echo "STEP 7: Search Suggestions\n";
echo "---------------------------\n\n";

$suggestions = $yetiSearch->suggest('apartments', 'brook');
echo "Suggestions for 'brook':\n";
foreach ($suggestions as $suggestion) {
    echo "   - {$suggestion}\n";
}

// ====================================================================
// STEP 10: Summary Statistics
// ====================================================================

echo "\n\nSTEP 8: Summary Statistics\n";
echo "---------------------------\n\n";

// Get all apartments for statistics
$allApts = $yetiSearch->search('apartments', '', ['limit' => 100]);

// Calculate statistics
$totalApts = count($allApts['results']);
$prices = array_column(array_column($allApts['results'], 'metadata'), 'price');
$avgPrice = array_sum($prices) / count($prices);
$minPrice = min($prices);
$maxPrice = max($prices);

$sqfts = array_column(array_column($allApts['results'], 'metadata'), 'sqft');
$avgSqft = array_sum($sqfts) / count($sqfts);

echo "Database Statistics:\n";
echo "  - Total apartments: {$totalApts}\n";
echo "  - Price range: $" . number_format($minPrice) . " - $" . number_format($maxPrice) . "/mo\n";
echo "  - Average price: $" . number_format($avgPrice, 0) . "/mo\n";
echo "  - Average size: " . number_format($avgSqft, 0) . " sqft\n";

// ====================================================================
// CLEANUP
// ====================================================================

echo "\n✓ Tutorial completed successfully!\n\n";

// Clean up database file
unlink('apartments.db');

echo "Key Takeaways:\n";
echo "--------------\n";
echo "1. Separate searchable content from filterable metadata\n";
echo "2. Use geo filters for location-based searches\n";
echo "3. DSL provides natural query syntax\n";
echo "4. Fluent interface offers programmatic control\n";
echo "5. Facets help understand data distribution\n";
echo "6. Combine text search with metadata filters for precise results\n";
echo "7. URL parameters enable REST API integration\n";