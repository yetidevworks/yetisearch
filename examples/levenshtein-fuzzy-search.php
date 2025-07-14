<?php
/**
 * Example: Using Levenshtein-based fuzzy search in YetiSearch
 * 
 * This example demonstrates how to enable and use the Levenshtein algorithm
 * for improved fuzzy search capabilities.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use YetiSearch\YetiSearch;

// Initialize YetiSearch with Levenshtein fuzzy search enabled
$config = [
    'storage' => [
        'path' => '/tmp/movies-levenshtein.db'
    ],
    'search' => [
        'enable_fuzzy' => true,
        'fuzzy_algorithm' => 'levenshtein',      // Use Levenshtein instead of basic
        'levenshtein_threshold' => 2,            // Maximum edit distance
        'min_term_frequency' => 1,               // Include terms that appear at least once
        'max_fuzzy_variations' => 10,            // Maximum variations per term
        'fuzzy_score_penalty' => 0.3            // Penalty for fuzzy matches (30% reduction)
    ]
];

$search = new YetiSearch($config);

// Create the movies index
$indexer = $search->createIndex('movies');

// Example: Index some movie data with character names
$movies = [
    [
        'id' => 'sw-ep4',
        'title' => 'Star Wars: Episode IV - A New Hope',
        'content' => 'Luke Skywalker joins forces with a Jedi Knight, a cocky pilot, and two droids to save the galaxy from the Empire. Features Anakin Skywalker as Darth Vader.',
        'characters' => 'Luke Skywalker, Princess Leia, Han Solo, Darth Vader, Obi-Wan Kenobi, C-3PO, R2-D2'
    ],
    [
        'id' => 'sw-ep5',
        'title' => 'Star Wars: Episode V - The Empire Strikes Back',
        'content' => 'After the Rebels are brutally overpowered by the Empire, Luke Skywalker begins Jedi training with Yoda.',
        'characters' => 'Luke Skywalker, Darth Vader, Yoda, Han Solo, Princess Leia, Lando Calrissian'
    ],
    [
        'id' => 'sw-ep3',
        'title' => 'Star Wars: Episode III - Revenge of the Sith',
        'content' => 'Anakin Skywalker turns to the dark side and becomes Darth Vader.',
        'characters' => 'Anakin Skywalker, Obi-Wan Kenobi, Padmé Amidala, Yoda, Emperor Palpatine'
    ]
];

// Index the movies
foreach ($movies as $movie) {
    $indexer->insert($movie);
}

echo "=== Levenshtein Fuzzy Search Examples ===\n\n";

// Test 1: Search with typos in character name
echo "Test 1: Searching for 'Amakin Dkywalker' (typos in both words)\n";
$results = $search->search('movies', 'Amakin Dkywalker', [
    'fuzzy' => true,
    'highlight' => true
]);

echo "Found " . $results['total'] . " results\n";
foreach ($results['results'] as $result) {
    echo "- " . $result['title'] . " (Score: " . $result['_score'] . ")\n";
    if (isset($result['highlights']['content'])) {
        echo "  Highlight: " . $result['highlights']['content'][0] . "\n";
    }
}
echo "\n";

// Test 2: Search with different types of typos
echo "Test 2: Searching for 'Luck Skywalker' (substitution typo)\n";
$results = $search->search('movies', 'Luck Skywalker', [
    'fuzzy' => true
]);

echo "Found " . $results['total'] . " results\n";
foreach ($results['results'] as $result) {
    echo "- " . $result['title'] . " (Score: " . $result['_score'] . ")\n";
}
echo "\n";

// Test 3: Search with transposition
echo "Test 3: Searching for 'Drath Vader' (transposition typo)\n";
$results = $search->search('movies', 'Drath Vader', [
    'fuzzy' => true
]);

echo "Found " . $results['total'] . " results\n";
foreach ($results['results'] as $result) {
    echo "- " . $result['title'] . " (Score: " . $result['_score'] . ")\n";
}
echo "\n";

// Test 4: Compare with basic fuzzy search
echo "Test 4: Comparing Levenshtein vs Basic Fuzzy\n\n";

// Create another instance with basic fuzzy search
$basicConfig = $config;
$basicConfig['storage']['path'] = '/tmp/movies-basic.db';
$basicConfig['search']['fuzzy_algorithm'] = 'basic';

$basicSearch = new YetiSearch($basicConfig);
$basicIndexer = $basicSearch->createIndex('movies');

// Index the same data
foreach ($movies as $movie) {
    $basicIndexer->insert($movie);
}

// Compare results
$testQuery = 'Amakin Dkywalker';

echo "Query: '$testQuery'\n\n";

echo "Basic Fuzzy Results:\n";
$basicResults = $basicSearch->search('movies', $testQuery, ['fuzzy' => true]);
echo "Found " . $basicResults['total'] . " results\n";

echo "\nLevenshtein Fuzzy Results:\n";
$levenshteinResults = $search->search('movies', $testQuery, ['fuzzy' => true]);
echo "Found " . $levenshteinResults['total'] . " results\n";
foreach ($levenshteinResults['results'] as $result) {
    echo "- " . $result['title'] . "\n";
}

// Clean up
unlink('/tmp/movies-levenshtein.db');
unlink('/tmp/movies-basic.db');

echo "\n=== Configuration Notes ===\n";
echo "- fuzzy_algorithm: 'levenshtein' enables edit-distance based matching\n";
echo "- levenshtein_threshold: 2 allows up to 2 character edits\n";
echo "- min_term_frequency: 1 includes all indexed terms\n";
echo "- fuzzy_score_penalty: 0.3 reduces scores for fuzzy matches by 30%\n";
echo "\nThe Levenshtein algorithm finds matches that basic fuzzy search misses!\n";