<?php
/**
 * Example: Enhanced Fuzzy Search with Modern Typo Correction
 * 
 * This example demonstrates the improved fuzzy search capabilities that behave
 * like modern search engines (Google, Elasticsearch) with automatic typo correction.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use YetiSearch\YetiSearch;

// Initialize YetiSearch with enhanced fuzzy search configuration
$config = [
    'storage' => [
        'path' => '/tmp/enhanced-fuzzy.db',
        'external_content' => true,
    ],
    'search' => [
        'enable_fuzzy' => true,
        'fuzzy_correction_mode' => true,        // Enable modern typo correction
        'fuzzy_algorithm' => 'trigram',         // Best balance of speed/accuracy
        'correction_threshold' => 0.6,          // Lower threshold for better sensitivity
        'trigram_threshold' => 0.35,            // Lower threshold for better matching
        'jaro_winkler_threshold' => 0.85,       // Slightly lower for more matches
        'levenshtein_threshold' => 2,           // Keep current but optimize weighting
        'max_fuzzy_variations' => 15,           // Increase for better coverage
        'fuzzy_score_penalty' => 0.25,          // Reduced penalty for better fuzzy results
        'min_term_frequency' => 1,              // Include all indexed terms
        'indexed_terms_cache_ttl' => 300,       // Cache indexed terms for 5 minutes
        'max_indexed_terms' => 20000,           // Limit indexed terms for performance
    ]
];

$search = new YetiSearch($config);

// Create the demo index
$indexer = $search->createIndex('demo', ['external_content' => true]);

// Index some demo content with common typo patterns
$documents = [
    [
        'id' => 'phone-guide',
        'content' => [
            'title' => 'Phone Number Guide',
            'content' => 'Contact us by phone for customer service. Our phone support is available 24/7.',
            'tags' => 'contact phone support'
        ],
        'metadata' => ['route' => '/phone']
    ],
    [
        'id' => 'quick-tutorial',
        'content' => [
            'title' => 'Quick Start Tutorial',
            'content' => 'A quick guide to get started quickly. Follow these quick steps for success.',
            'tags' => 'tutorial quick guide'
        ],
        'metadata' => ['route' => '/tutorial']
    ],
    [
        'id' => 'their-house',
        'content' => [
            'title' => 'Their House Design',
            'content' => 'This is their house and their design. Their style is modern and clean.',
            'tags' => 'design house their'
        ],
        'metadata' => ['route' => '/house']
    ],
    [
        'id' => 'keyboard-tips',
        'content' => [
            'title' => 'Keyboard Typing Tips',
            'content' => 'Learn to type faster on your keyboard. Proper keyboard technique improves speed.',
            'tags' => 'keyboard typing tips'
        ],
        'metadata' => ['route' => '/keyboard']
    ],
    [
        'id' => 'search-engine',
        'content' => [
            'title' => 'Search Engine Optimization',
            'content' => 'How search engines work and rank content. Search engine marketing strategies.',
            'tags' => 'search engine seo'
        ],
        'metadata' => ['route' => '/search']
    ]
];

// Index the documents
foreach ($documents as $doc) {
    $indexer->insert($doc);
}

// Make sure the index is built
$indexer->flush();

// Check if documents were indexed
$stats = $search->getStats('demo');
echo "Index stats: " . json_encode($stats) . "\n\n";

// Test exact matches first
echo "Testing exact matches:\n";
$exactResults = $search->search('demo', 'phone', ['fuzzy' => false]);
echo "Exact 'phone': {$exactResults['total']} results\n";

$exactResults = $search->search('demo', 'quick', ['fuzzy' => false]);
echo "Exact 'quick': {$exactResults['total']} results\n";

echo "\n=== Enhanced Fuzzy Search Examples ===\n\n";

// Test 1: Phonetic typo correction
echo "Test 1: Phonetic Typo Correction\n";
echo "Query: 'fone' (should correct to 'phone')\n";
$results = $search->search('demo', 'fone', [
    'fuzzy' => true,
    'highlight' => true
]);

echo "Found {$results['total']} results\n";
foreach ($results['results'] as $result) {
    $title = $result['document']['title'] ?? 'No title';
    $score = $result['score'] ?? 0;
    echo "- {$title} (Score: {$score})\n";
    if (isset($result['highlights']['content'])) {
        echo "  Highlight: {$result['highlights']['content'][0]}\n";
    }
}
echo "\n";

// Test 2: Keyboard proximity typo correction
echo "Test 2: Keyboard Proximity Typo Correction\n";
echo "Query: 'qyick tutoral' (should correct to 'quick tutorial')\n";
$results = $search->search('demo', 'qyick tutoral', [
    'fuzzy' => true,
    'highlight' => true
]);

echo "Found {$results['total']} results\n";
foreach ($results['results'] as $result) {
    $title = $result['document']['title'] ?? 'No title';
    $score = $result['score'] ?? 0;
    echo "- {$title} (Score: {$score})\n";
    if (isset($result['highlights']['content'])) {
        echo "  Highlight: {$result['highlights']['content'][0]}\n";
    }
}
echo "\n";

// Test 3: Common typo patterns
echo "Test 3: Common Typo Patterns\n";
echo "Query: 'thier house' (should correct to 'their house')\n";
$results = $search->search('demo', 'thier house', [
    'fuzzy' => true,
    'highlight' => true
]);

echo "Found {$results['total']} results\n";
foreach ($results['results'] as $result) {
    $title = $result['document']['title'] ?? 'No title';
    $score = $result['score'] ?? 0;
    echo "- {$title} (Score: {$score})\n";
    if (isset($result['highlights']['content'])) {
        echo "  Highlight: {$result['highlights']['content'][0]}\n";
    }
}
echo "\n";

// Test 4: Multiple typos in one query
echo "Test 4: Multiple Typos in One Query\n";
echo "Query: 'qyick fone' (should correct to 'quick phone')\n";
$results = $search->search('demo', 'qyick fone', [
    'fuzzy' => true,
    'highlight' => true
]);

echo "Found {$results['total']} results\n";
foreach ($results['results'] as $result) {
    $title = $result['document']['title'] ?? 'No title';
    $score = $result['score'] ?? 0;
    echo "- {$title} (Score: {$score})\n";
    if (isset($result['highlights']['content'])) {
        echo "  Highlight: {$result['highlights']['content'][0]}\n";
    }
}
echo "\n";

// Test 5: "Did You Mean?" suggestions
echo "Test 5: 'Did You Mean?' Suggestions\n";
echo "Query: 'qyick tutoral' (no results expected, should get suggestion)\n";
$results = $search->search('demo', 'qyick tutoral', [
    'fuzzy' => true,
    'limit' => 5
]);

if ($results['total'] === 0 && isset($results['suggestion'])) {
    echo "No results found. Did you mean: '{$results['suggestion']}'?\n\n";
} else {
    echo "Found {$results['total']} results\n\n";
}

// Test 6: Enhanced suggestions with confidence scores
echo "Test 6: Enhanced Suggestions with Confidence\n";
$suggestions = $search->generateSuggestions('qyick', 3);

if (!empty($suggestions)) {
    echo "Suggestions for 'qyick':\n";
    foreach ($suggestions as $suggestion) {
        echo "- {$suggestion['text']} (Confidence: " . round($suggestion['confidence'] * 100, 1) . "%)\n";
        echo "  Type: {$suggestion['type']}, Original: '{$suggestion['original_token']}' -> '{$suggestion['correction']}'\n";
    }
} else {
    echo "No suggestions found for 'qyick'\n";
}
echo "\n";

// Test 7: Performance comparison
echo "Test 7: Performance Comparison\n";
$testQueries = [
    'fone',           // Phonetic typo
    'qyick',          // Keyboard typo
    'thier',          // Common typo
    'qyick fone',     // Multiple typos
    'keyboard tps'    // Partial word + typo
];

foreach ($testQueries as $query) {
    $startTime = microtime(true);
    $results = $search->search('demo', $query, ['fuzzy' => true]);
    $endTime = microtime(true);
    
    $time = round(($endTime - $startTime) * 1000, 2);
    echo "Query: '$query' -> {$results['total']} results ({$time}ms)\n";
}
echo "\n";

// Clean up
unlink('/tmp/enhanced-fuzzy.db');

echo "=== Configuration Notes ===\n";
echo "- fuzzy_correction_mode: true enables modern typo correction\n";
echo "- correction_threshold: 0.6 balances sensitivity and precision\n";
echo "- trigram_threshold: 0.35 improves matching for partial words\n";
echo "- fuzzy_score_penalty: 0.25 reduces penalty for fuzzy matches\n";
echo "- Enhanced with phonetic matching and keyboard proximity analysis\n";
echo "- Multi-algorithm consensus scoring for better accuracy\n";
echo "\nThe enhanced fuzzy search automatically corrects typos like modern search engines!\n";