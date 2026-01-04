<?php
/**
 * YetiSearch Benchmark Script
 *
 * Validates performance and correctness of YetiSearch across indexing and search operations.
 * Results are saved as JSON for historical comparison.
 *
 * Usage:
 *   php benchmark.php                 # Full benchmark (index + search)
 *   php benchmark.php --skip-indexing # Search only (use existing index)
 *   php benchmark.php --save          # Save results to baseline
 *   php benchmark.php --compare       # Compare against saved baseline
 */

require_once __DIR__ . '/../vendor/autoload.php';

use YetiSearch\YetiSearch;

// ============================================================================
// Configuration
// ============================================================================

const INDEX_NAME = 'benchmark_movies';
const MOVIES_FILE = __DIR__ . '/movies.json';
const DB_FILE = __DIR__ . '/benchmark.db';
const BASELINE_FILE = __DIR__ . '/baseline.json';
const BATCH_SIZE = 250;

// ============================================================================
// CLI Arguments
// ============================================================================

$skipIndexing = in_array('--skip-indexing', $argv);
$saveBaseline = in_array('--save', $argv);
$compareBaseline = in_array('--compare', $argv);

// ============================================================================
// Helper Functions
// ============================================================================

function formatTime(float $ms): string {
    return $ms < 1 ? sprintf('%.2fms', $ms) : sprintf('%.1fms', $ms);
}

function formatRate(float $rate): string {
    return number_format($rate, 0) . '/sec';
}

function formatMemory(int $bytes): string {
    return number_format($bytes / 1024 / 1024, 1) . 'MB';
}

function printHeader(string $title): void {
    echo "\n" . str_repeat('=', 60) . "\n";
    echo " $title\n";
    echo str_repeat('=', 60) . "\n\n";
}

function printResult(string $label, string $value, string $status = ''): void {
    $statusIcon = match($status) {
        'pass' => "\033[32m✓\033[0m",
        'fail' => "\033[31m✗\033[0m",
        'warn' => "\033[33m!\033[0m",
        default => ' '
    };
    printf("  %s %-35s %s\n", $statusIcon, $label . ':', $value);
}

function downloadMovies(): void {
    if (file_exists(MOVIES_FILE)) {
        return;
    }

    echo "Downloading movies.json... ";
    $start = microtime(true);

    $data = @file_get_contents('https://www.meilisearch.com/movies.json');
    if ($data === false) {
        die("Failed to download movies.json\n");
    }

    file_put_contents(MOVIES_FILE, $data);
    $elapsed = (microtime(true) - $start) * 1000;
    $size = filesize(MOVIES_FILE) / 1024 / 1024;
    echo sprintf("Done (%.1fMB in %.0fms)\n", $size, $elapsed);
}

// ============================================================================
// Main Benchmark
// ============================================================================

echo "\n  YetiSearch Benchmark\n";
echo "  ====================\n";
echo "  PHP " . PHP_VERSION . " | SQLite " . \SQLite3::version()['versionString'] . "\n";

$results = [
    'timestamp' => date('c'),
    'php_version' => PHP_VERSION,
    'sqlite_version' => \SQLite3::version()['versionString'],
    'indexing' => null,
    'search' => [],
    'fuzzy' => [],
    'summary' => []
];

// Download movies if needed
downloadMovies();

// Initialize YetiSearch
$search = new YetiSearch([
    'storage' => ['path' => DB_FILE, 'external_content' => true],
    'indexer' => [
        'batch_size' => BATCH_SIZE,
        'fields' => [
            'title' => ['boost' => 5.0, 'store' => true],
            'overview' => ['boost' => 1.0, 'store' => true],
            'genres' => ['boost' => 2.0, 'store' => true]
        ]
    ],
    'search' => [
        'enable_fuzzy' => true,
        'fuzzy_algorithm' => 'trigram',
        'trigram_threshold' => 0.3,
        'cache_ttl' => 0
    ]
]);

// ============================================================================
// Indexing Benchmark
// ============================================================================

if (!$skipIndexing) {
    printHeader('Indexing Benchmark');

    // Load movies
    echo "  Loading movies.json... ";
    $loadStart = microtime(true);
    $movies = json_decode(file_get_contents(MOVIES_FILE), true);
    $loadTime = (microtime(true) - $loadStart) * 1000;
    echo "Done (" . count($movies) . " movies in " . formatTime($loadTime) . ")\n\n";

    // Drop and recreate index
    try {
        $search->dropIndex(INDEX_NAME);
    } catch (Exception $e) {}

    $indexer = $search->createIndex(INDEX_NAME);

    // Index movies
    echo "  Indexing...\n";
    $indexStart = microtime(true);
    $startMemory = memory_get_usage();
    $indexed = 0;
    $batch = [];

    foreach ($movies as $i => $movie) {
        $batch[] = [
            'id' => 'movie_' . $movie['id'],
            'content' => [
                'title' => $movie['title'],
                'overview' => $movie['overview'] ?? '',
                'genres' => is_array($movie['genres']) ? implode(', ', $movie['genres']) : ''
            ],
            'metadata' => ['original_id' => $movie['id']]
        ];

        if (count($batch) >= BATCH_SIZE || $i === count($movies) - 1) {
            $indexer->insert($batch);
            $indexed += count($batch);
            $batch = [];

            // Progress every 5000 docs
            if ($indexed % 5000 === 0 || $i === count($movies) - 1) {
                $elapsed = microtime(true) - $indexStart;
                $rate = $indexed / $elapsed;
                printf("    %d docs | %s | %.1fs elapsed\n", $indexed, formatRate($rate), $elapsed);
            }
        }
    }

    $indexer->flush();
    $indexTime = (microtime(true) - $indexStart) * 1000;
    $indexMemory = memory_get_usage() - $startMemory;
    $peakMemory = memory_get_peak_usage();
    $indexRate = $indexed / ($indexTime / 1000);

    echo "\n";
    printResult('Documents indexed', number_format($indexed));
    printResult('Indexing time', formatTime($indexTime));
    printResult('Indexing rate', formatRate($indexRate));
    printResult('Memory used', formatMemory($indexMemory));
    printResult('Peak memory', formatMemory($peakMemory));

    $results['indexing'] = [
        'documents' => $indexed,
        'time_ms' => round($indexTime, 2),
        'rate' => round($indexRate, 1),
        'memory_bytes' => $indexMemory,
        'peak_memory_bytes' => $peakMemory
    ];
} else {
    echo "\n  Skipping indexing (--skip-indexing)\n";
}

// ============================================================================
// Search Benchmarks
// ============================================================================

printHeader('Search Benchmark (Standard)');

$searchTests = [
    ['query' => 'star wars', 'min_results' => 1, 'expected' => 'Star Wars'],
    ['query' => 'action', 'min_results' => 1, 'expected' => null],
    ['query' => 'nemo', 'min_results' => 1, 'expected' => 'Finding Nemo'],
    ['query' => 'matrix', 'min_results' => 1, 'expected' => 'Matrix'],
    ['query' => 'Anakin Skywalker', 'min_results' => 1, 'expected' => 'Star Wars'],
    ['query' => 'drama crime', 'min_results' => 1, 'expected' => null],
];

$totalSearchTime = 0;
$searchPassed = 0;

foreach ($searchTests as $test) {
    $start = microtime(true);
    $res = $search->search(INDEX_NAME, $test['query'], ['limit' => 5, 'fuzzy' => false]);
    $elapsed = (microtime(true) - $start) * 1000;
    $totalSearchTime += $elapsed;

    $passed = $res['total'] >= $test['min_results'];
    if ($test['expected'] && $passed) {
        $titles = array_map(fn($r) => $r['document']['title'] ?? '', $res['results']);
        $found = array_filter($titles, fn($t) => stripos($t, $test['expected']) !== false);
        $passed = !empty($found);
    }

    $status = $passed ? 'pass' : 'fail';
    if ($passed) $searchPassed++;

    $info = sprintf("%.1fms | %d results", $elapsed, $res['total']);
    printResult("'{$test['query']}'", $info, $status);

    $results['search'][] = [
        'query' => $test['query'],
        'time_ms' => round($elapsed, 2),
        'total' => $res['total'],
        'passed' => $passed
    ];
}

echo "\n";
$avgSearchTime = $totalSearchTime / count($searchTests);
printResult('Average search time', formatTime($avgSearchTime));
printResult('Tests passed', "$searchPassed/" . count($searchTests), $searchPassed === count($searchTests) ? 'pass' : 'warn');

// ============================================================================
// Fuzzy Search Benchmarks
// ============================================================================

printHeader('Fuzzy Search Benchmark');

$fuzzyTests = [
    ['query' => 'Starwars', 'expected' => 'Star Wars', 'desc' => 'missing space'],
    ['query' => 'The Godfathr', 'expected' => 'Godfather', 'desc' => 'missing e'],
    ['query' => 'Gladiater', 'expected' => 'Gladiator', 'desc' => 'er->or'],
    ['query' => 'Forest Gump', 'expected' => 'Forrest Gump', 'desc' => 'missing r'],
    ['query' => 'Pulp Fictin', 'expected' => 'Pulp Fiction', 'desc' => 'missing o'],
    ['query' => 'Dark Knigh', 'expected' => 'Dark Knight', 'desc' => 'missing t'],
    ['query' => 'Shawshank Redemtion', 'expected' => 'Shawshank', 'desc' => 'missing p'],
    ['query' => 'Interstelar', 'expected' => 'Interstellar', 'desc' => 'missing l'],
    ['query' => 'Finding Nem', 'expected' => 'Finding Nemo', 'desc' => 'missing o'],
    ['query' => 'Toy Stry', 'expected' => 'Toy Story', 'desc' => 'missing o'],
];

$totalFuzzyTime = 0;
$fuzzyPassed = 0;

foreach ($fuzzyTests as $test) {
    $start = microtime(true);
    $res = $search->search(INDEX_NAME, $test['query'], [
        'limit' => 5,
        'fuzzy' => true,
        'fuzzy_algorithm' => 'trigram',
        'trigram_threshold' => 0.25
    ]);
    $elapsed = (microtime(true) - $start) * 1000;
    $totalFuzzyTime += $elapsed;

    $titles = array_map(fn($r) => $r['document']['title'] ?? '', $res['results']);
    $found = array_filter($titles, fn($t) => stripos($t, $test['expected']) !== false);
    $passed = !empty($found);

    $status = $passed ? 'pass' : 'fail';
    if ($passed) $fuzzyPassed++;

    $info = sprintf("%.1fms | %s", $elapsed, $passed ? 'Found' : 'NOT found');
    printResult("'{$test['query']}' -> '{$test['expected']}'", $info, $status);

    $results['fuzzy'][] = [
        'query' => $test['query'],
        'expected' => $test['expected'],
        'description' => $test['desc'],
        'time_ms' => round($elapsed, 2),
        'found' => $passed,
        'top_result' => $titles[0] ?? null
    ];
}

echo "\n";
$avgFuzzyTime = $totalFuzzyTime / count($fuzzyTests);
printResult('Average fuzzy time', formatTime($avgFuzzyTime));
printResult('Fuzzy tests passed', "$fuzzyPassed/" . count($fuzzyTests), $fuzzyPassed >= count($fuzzyTests) * 0.7 ? 'pass' : 'warn');

// ============================================================================
// Summary
// ============================================================================

printHeader('Summary');

$results['summary'] = [
    'indexing_rate' => $results['indexing']['rate'] ?? null,
    'avg_search_ms' => round($avgSearchTime, 2),
    'avg_fuzzy_ms' => round($avgFuzzyTime, 2),
    'search_pass_rate' => $searchPassed / count($searchTests),
    'fuzzy_pass_rate' => $fuzzyPassed / count($fuzzyTests),
    'peak_memory_mb' => isset($peakMemory) ? round($peakMemory / 1024 / 1024, 1) : null
];

if ($results['indexing']) {
    printResult('Indexing rate', formatRate($results['indexing']['rate']));
}
printResult('Avg search time', formatTime($avgSearchTime));
printResult('Avg fuzzy time', formatTime($avgFuzzyTime));
printResult('Search accuracy', sprintf('%.0f%%', $results['summary']['search_pass_rate'] * 100));
printResult('Fuzzy accuracy', sprintf('%.0f%%', $results['summary']['fuzzy_pass_rate'] * 100));

// ============================================================================
// Baseline Comparison
// ============================================================================

if ($saveBaseline) {
    file_put_contents(BASELINE_FILE, json_encode($results, JSON_PRETTY_PRINT));
    echo "\n  Baseline saved to " . basename(BASELINE_FILE) . "\n";
}

if ($compareBaseline && file_exists(BASELINE_FILE)) {
    printHeader('Baseline Comparison');

    $baseline = json_decode(file_get_contents(BASELINE_FILE), true);
    $bs = $baseline['summary'] ?? [];
    $cs = $results['summary'];

    $compare = function($label, $current, $baseline, $lowerBetter = true) {
        if ($baseline === null) return;
        $diff = $current - $baseline;
        $pct = $baseline > 0 ? ($diff / $baseline) * 100 : 0;
        $better = $lowerBetter ? $diff < 0 : $diff > 0;
        $status = abs($pct) < 5 ? '' : ($better ? 'pass' : 'warn');
        $arrow = $diff > 0 ? '+' : '';
        printResult($label, sprintf('%.1f (was %.1f, %s%.1f%%)', $current, $baseline, $arrow, $pct), $status);
    };

    if ($cs['indexing_rate'] && ($bs['indexing_rate'] ?? null)) {
        $compare('Indexing rate', $cs['indexing_rate'], $bs['indexing_rate'], false);
    }
    $compare('Avg search time (ms)', $cs['avg_search_ms'], $bs['avg_search_ms'] ?? null);
    $compare('Avg fuzzy time (ms)', $cs['avg_fuzzy_ms'], $bs['avg_fuzzy_ms'] ?? null);

    echo "\n  Baseline from: " . ($baseline['timestamp'] ?? 'unknown') . "\n";
}

// Save results
file_put_contents(__DIR__ . '/results.json', json_encode($results, JSON_PRETTY_PRINT));

echo "\n  Results saved to results.json\n";
echo "\n  Benchmark complete!\n\n";
