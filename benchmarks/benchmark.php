<?php
/**
 * YetiSearch Benchmark Script
 *
 * This script benchmarks the YetiSearch library by indexing a dataset of movies
 * and performing search queries with and without fuzzy matching.
 *
 * Usage:
 * php benchmark.php [--skip-indexing]
 *
 * Options:
 * --skip-indexing: Skip the indexing step and only run searches on existing data.
 */

// Use the existing vendor autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use YetiSearch\YetiSearch;

// Check command line arguments
$skipIndexing = in_array('--skip-indexing', $argv);

// Start timing
$startTime = microtime(true);
$startMemory = memory_get_usage();
$fuzzy_algorithm = 'trigram'; // basic | jaro_winkler | trigram | levenshtein

echo "YetiSearch Benchmark Test\n";
echo "========================\n";
echo "Using $fuzzy_algorithm fuzzy search algorithm\n\n";

if (!$skipIndexing) {
    // Load the movies data
    $jsonFile = __DIR__ . '/movies.json';
    
    // Download movies.json if it doesn't exist
    if (!file_exists($jsonFile)) {
        echo "movies.json not found. Downloading from MeiliSearch...\n";
        $downloadStart = microtime(true);
        
        $movieData = @file_get_contents('https://www.meilisearch.com/movies.json');
        if ($movieData === false) {
            die("Error: Failed to download movies.json from https://www.meilisearch.com/movies.json\n");
        }
        
        if (@file_put_contents($jsonFile, $movieData) === false) {
            die("Error: Failed to save movies.json to $jsonFile\n");
        }
        
        $downloadTime = microtime(true) - $downloadStart;
        $fileSize = filesize($jsonFile) / 1024 / 1024; // Convert to MB
        echo "Downloaded " . number_format($fileSize, 2) . " MB in " . number_format($downloadTime, 2) . " seconds\n\n";
    }
    
    echo "Loading movies.json... ";
    $jsonContent = file_get_contents($jsonFile);
    $movies = json_decode($jsonContent, true);
    
    if ($movies === null) {
        die("Error: Failed to parse movies.json\n");
    }
    
    $loadTime = microtime(true) - $startTime;
    echo "Done! (" . count($movies) . " movies loaded in " . number_format($loadTime, 4) . " seconds)\n\n";
}

// Initialize YetiSearch
echo "Initializing YetiSearch... ";
$indexStartTime = microtime(true);
$config = [
    'storage' => [
        'path' => __DIR__ . '/benchmark.db'
    ],
    'analyzer' => [
        'min_word_length' => 2,
        'strip_html' => true,
        'remove_stop_words' => true
    ],
    'indexer' => [
        'fields' => [
            'title' => ['boost' => 3.0, 'store' => true],
            'overview' => ['boost' => 1.0, 'store' => true],  // Add overview field
            'genres' => ['boost' => 2.0, 'store' => true]
        ]
    ],
    'search' => [
        'enable_fuzzy' => true,
        'fuzzy_algorithm' => $fuzzy_algorithm,    // Use Jaro-Winkler for better name/title matching
        'jaro_winkler_threshold' => 0.92,       // Very high threshold to reduce false matches
        'jaro_winkler_prefix_scale' => 0.1,     // Standard prefix bonus weight
        'min_term_frequency' => 2,              // Include moderately common terms
        'max_indexed_terms' => 10000,           // Balance between performance and coverage
        'max_fuzzy_variations' => 5,            // Limit variations for performance
        'fuzzy_score_penalty' => 0.3,           // Moderate penalty since we now prioritize exact matches in query
        'indexed_terms_cache_ttl' => 300        // Cache indexed terms for 5 minutes
    ]
    // Note: Jaro-Winkler is 2.5x faster than Levenshtein and better for names/titles
];
$search = new YetiSearch($config);
echo "Done!\n";

if (!$skipIndexing) {
    // Create index for movies (will use existing if available)
    $indexer = $search->createIndex('movies');
    
    // Clear existing index data
    echo "Clearing existing index... ";
    try {
        $search->clear('movies');
        echo "Done!\n";
    } catch (Exception $e) {
        echo "Error clearing index: " . $e->getMessage() . "\n";
    }

    // Index all movies
    echo "Indexing movies...\n";
    $indexed = 0;
    $errors = 0;
    $progressInterval = 1000; // Show progress every 1000 movies
    $batch = [];
    $batchSize = 250; // Process in batches of 100

    foreach ($movies as $index => $movie) {
        try {
            // Create document structure for indexing
            $document = [
                'id' => 'movie-' . $movie['id'],
                'content' => [
                    'title' => $movie['title'],
                    'overview' => $movie['overview'],
                    'genres' => implode(' ', $movie['genres'] ?? [])
                ],
                'metadata' => [
                    'poster' => $movie['poster'] ?? '',
                    'release_date' => $movie['release_date'] ?? 0,
                    'genres' => $movie['genres'] ?? []
                ]
            ];
            
            // Add to batch
            $batch[] = $document;
            
            // Process batch when it reaches the size limit or at the end
            if (count($batch) >= $batchSize || $index === count($movies) - 1) {
                $indexer->insert($batch);
                $indexed += count($batch);
                $batch = []; // Reset batch
                
                // Show progress
                if ($indexed % $progressInterval === 0 || $index === count($movies) - 1) {
                    $elapsed = microtime(true) - $indexStartTime;
                    $rate = $indexed / $elapsed;
                    echo "  Indexed: " . $indexed . " movies | Rate: " . number_format($rate, 0) . " movies/sec | Elapsed: " . number_format($elapsed, 2) . "s\n";
                }
            }
        } catch (Exception $e) {
            $errors++;
            echo "  Error indexing movie ID {$movie['id']}: " . $e->getMessage() . "\n";
        }
    }

    // Flush to ensure all documents are written
    $indexer->flush();
} else {
    echo "Skipping indexing (--skip-indexing flag provided)\n\n";
}

// Calculate final statistics
$totalTime = microtime(true) - $startTime;
$endMemory = memory_get_usage();
$memoryUsed = ($endMemory - $startMemory) / 1024 / 1024; // Convert to MB
$peakMemory = memory_get_peak_usage() / 1024 / 1024; // Convert to MB

if (!$skipIndexing) {
    $indexTime = microtime(true) - $indexStartTime;
    echo "\nBenchmark Results\n";
    echo "=================\n";
    echo "Total movies processed: " . count($movies) . "\n";
    echo "Successfully indexed: $indexed\n";
    echo "Errors: $errors\n";
    echo "Total time: " . number_format($totalTime, 4) . " seconds\n";
    echo "Loading time: " . number_format($loadTime, 4) . " seconds\n";
    echo "Indexing time: " . number_format($indexTime, 4) . " seconds\n";
    echo "Average indexing rate: " . number_format($indexed / $indexTime, 2) . " movies/second\n";
    echo "Memory used: " . number_format($memoryUsed, 2) . " MB\n";
    echo "Peak memory: " . number_format($peakMemory, 2) . " MB\n";
}

// Test search functionality
echo "\nTesting Search Functionality\n";
echo "===========================\n";

$testQueries = [
    'star wars',
    'action',
    'drama crime',
    'nemo',
    'matrix',
    'Anakin Skywalker',
    'Harry Potter',
];

// First test with fuzzy OFF
echo "\n--- Standard Search (fuzzy: OFF) ---\n";
foreach ($testQueries as $query) {
    $searchStart = microtime(true);
    
    $searchOptions = [
        'limit' => 5,
        'fuzzy' => false
    ];
    
    $results = $search->search('movies', $query, $searchOptions);
    
    $searchTime = microtime(true) - $searchStart;
    
    echo "\nQuery: '$query' (took " . number_format($searchTime * 1000, 2) . " ms)\n";
    echo "Results found: " . count($results['results']) . " (Total hits: " . ($results['total'] ?? 0) . ")\n";
    
    if (count($results['results']) > 0) {
        foreach ($results['results'] as $i => $result) {
            $doc = $result['document'] ?? [];
            $title = $doc['title'] ?? 'Unknown';
            $score = $result['score'] ?? 0;
            echo "  " . ($i + 1) . ". " . $title . " (Score: " . number_format($score, 4) . ")\n";
        }
    }
}

// Then test with fuzzy ON
echo "\n\n--- Fuzzy Search (fuzzy: ON) ---\n";
foreach ($testQueries as $query) {
    $searchStart = microtime(true);
    
    $searchOptions = [
        'limit' => 5,
        'fuzzy' => true
    ];
    
    $results = $search->search('movies', $query, $searchOptions);
    
    $searchTime = microtime(true) - $searchStart;
    
    echo "\nQuery: '$query' (took " . number_format($searchTime * 1000, 2) . " ms)\n";
    echo "Results found: " . count($results['results']) . " (Total hits: " . ($results['total'] ?? 0) . ")\n";
    
    if (count($results['results']) > 0) {
        foreach ($results['results'] as $i => $result) {
            $doc = $result['document'] ?? [];
            $title = $doc['title'] ?? 'Unknown';
            $score = $result['score'] ?? 0;
            echo "  " . ($i + 1) . ". " . $title . " (Score: " . number_format($score, 4) . ")\n";
        }
    }
}

// Additional fuzzy search tests
echo "\n\nFuzzy Search Tests\n";
echo "=============================\n";

$fuzzyTests = [
    ['query' => 'Amakin Dkywalker', 'expected' => 'Anakin Skywalker'],
    ['query' => 'Skywaker', 'expected' => 'Skywalker'],
    ['query' => 'Star Wrs', 'expected' => 'Star Wars'],
    ['query' => 'The Godfater', 'expected' => 'The Godfather'],
    ['query' => 'Inceptionn', 'expected' => 'Inception'],
    ['query' => 'The Dark Knigh', 'expected' => 'The Dark Knight'],
    ['query' => 'Pulp Fictin', 'expected' => 'Pulp Fiction'],
    ['query' => 'Forrest Gump', 'expected' => 'Forrest Gump'],
    ['query' => 'The Shawshank Redemtion', 'expected' => 'The Shawshank Redemption'],
    ['query' => 'Lilo and Stich', 'expected' => 'Lilo and Stitch'],
    ['query' => 'Cristopher Nolan', 'expected' => 'Christopher Nolan'],
];

foreach ($fuzzyTests as $test) {
    $searchStart = microtime(true);
    $results = $search->search('movies', $test['query'], [
        'limit' => 5,
        'fuzzy' => true
    ]);
    $searchTime = microtime(true) - $searchStart;
    
    echo "\nQuery: '{$test['query']}' (took " . number_format($searchTime * 1000, 2) . " ms)\n";
    echo "Results found: " . count($results['results']) . " (Total hits: " . ($results['total'] ?? 0) . ")\n";
    echo "[Looking for: '{$test['expected']}']\n";
    
    if (count($results['results']) > 0) {
        foreach ($results['results'] as $i => $result) {
            // Get document data
            $doc = $result['document'] ?? [];
            $title = $doc['title'] ?? 'Unknown';
            $score = $result['score'] ?? 0;
            echo "  " . ($i + 1) . ". " . $title . " (Score: " . number_format($score, 4) . ")\n";
            
            // Check if we found what we expected
            if (stripos($title, $test['expected']) !== false || 
                stripos($doc['overview'] ?? '', $test['expected']) !== false) {
                echo "     *** Found expected result! ***\n";
            }
        }
    }
}

echo "\nBenchmark complete!\n";
echo "\nNote: Database file saved at: " . realpath(__DIR__ . '/benchmark.db') . "\n";
