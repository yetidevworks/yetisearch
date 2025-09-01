<?php

// Type-Ahead example: suggestions + as-you-type search
// Usage:
//   php examples/type-ahead.php "anaki skywa"
//   php examples/type-ahead.php --interactive

require_once __DIR__ . '/../vendor/autoload.php';

use YetiSearch\YetiSearch;

$args = $argv;
array_shift($args);
$interactive = in_array('--interactive', $args, true);
$live = $interactive; // interactive mode is live-by-character
$query = $interactive ? '' : (implode(' ', array_filter($args)) ?: 'skyw');

// Configure YetiSearch for type-ahead
$config = [
    'storage' => [
        'path' => __DIR__ . '/typeahead.db',
        // Explicitly demonstrate external-content schema
        'external_content' => true,
    ],
    'indexer' => [
        'fields' => [
            'title' => ['boost' => 3.0, 'store' => true],
            'content' => ['boost' => 1.0, 'store' => true],
            'tags'   => ['boost' => 2.0, 'store' => true],
        ],
        // Optional: enable weighted multi-column FTS + prefix indexes
        'fts' => [
            'multi_column' => true,
            'prefix' => [2,3],
        ],
    ],
    'search' => [
        'enable_fuzzy' => true,
        'fuzzy_algorithm' => 'jaro_winkler', // good for short terms
        'fuzzy_last_token_only' => true,      // focus typo tolerance on last term
        'prefix_last_token' => true,          // use prefix on last term (requires prefix above)
    ],
];

$ys = new YetiSearch($config);
$index = 'demo_typeahead';
// Explicitly create with external-content enabled (default is true)
$ys->createIndex($index, ['external_content' => true]);

// Seed a small dataset (idempotent)
$docs = [
    ['id' => 'a1', 'content' => ['title' => 'Anakin Skywalker', 'content' => 'Jedi Knight turned Sith Lord', 'tags' => 'starwars skywalker']],
    ['id' => 'l1', 'content' => ['title' => 'Luke Skywalker', 'content' => 'A powerful Jedi Knight', 'tags' => 'starwars skywalker']],
    ['id' => 's1', 'content' => ['title' => 'Star Wars', 'content' => 'A space opera saga', 'tags' => 'starwars saga']],
    ['id' => 't1', 'content' => ['title' => 'Star Trek', 'content' => 'Exploration among the stars', 'tags' => 'startrek']],
    ['id' => 'g1', 'content' => ['title' => 'Stargate', 'content' => 'Ancient portals between worlds', 'tags' => 'stargate']],
    ['id' => 'k1', 'content' => ['title' => 'The Dark Knight', 'content' => 'Batman vs Joker', 'tags' => 'batman nolan']],
    ['id' => 'i1', 'content' => ['title' => 'Inception', 'content' => 'A dream within a dream', 'tags' => 'nolan']],
    ['id' => 'f1', 'content' => ['title' => 'Skyfall', 'content' => 'James Bond adventure', 'tags' => 'bond']],
    ['id' => 'y1', 'content' => ['title' => 'Skyrim Guide', 'content' => 'Elder Scrolls strategies', 'tags' => 'skyrim']],
];
$ys->indexBatch($index, $docs);

function runQuery(YetiSearch $ys, string $index, string $q): void {
    if ($q === '') return;
    echo "\nQuery: {$q}\n";

    // Suggestions for dropdown
    $sugs = $ys->suggest($index, $q, [
        'limit' => 6,
        'per_variant' => 4,
    ]);
    if (!empty($sugs)) {
        echo "Suggestions:\n";
        foreach ($sugs as $s) {
            echo " - " . $s['text'] . "\n";
        }
    }

    // As-you-type results (small, focused)
    $res = $ys->search($index, $q, [
        'limit' => 6,
        'fields' => ['title','content','tags'],
        'fuzzy' => true,
        'fuzzy_algorithm' => 'jaro_winkler',
        'fuzzy_last_token_only' => true,
        'prefix_last_token' => true,
        'highlight' => false,
        'unique_by_route' => true,
    ]);

    $count = count($res['results']);
    echo "Results ({$count} shown, total: " . ($res['total'] ?? $count) . "):\n";
    foreach ($res['results'] as $i => $r) {
        $title = $r['document']['title'] ?? 'Untitled';
        $score = number_format((float)($r['score'] ?? 0), 2);
        echo sprintf(" %d. %s (score %s)\n", $i+1, $title, $score);
    }
}

if ($interactive) {
    echo "Type-Ahead Demo (Ctrl+C to exit)\n";
    echo "Live updates after each character. Min length: 2.\n";

    $isUnix = DIRECTORY_SEPARATOR === '/';
    $canRaw = $isUnix && function_exists('shell_exec') && function_exists('system');
    $origStty = null;

    $cleanup = function() use (&$origStty, $canRaw) {
        if ($canRaw && $origStty) {
            @system('stty ' . $origStty);
            $origStty = null;
        }
    };
    register_shutdown_function($cleanup);

    $buffer = '';

    if ($canRaw) {
        // Save and enable raw (non-canonical) mode
        $origStty = trim((string)@shell_exec('stty -g'));
        @system('stty -icanon -echo min 1 time 0');
        echo "\n> ";
        while (true) {
            $ch = fgetc(STDIN);
            if ($ch === false) { usleep(10000); continue; }
            $ord = ord($ch);
            if ($ord === 3) { // Ctrl-C
                echo "\n";
                break;
            }
            if ($ord === 127 || $ord === 8) { // backspace
                if ($buffer !== '') { $buffer = mb_substr($buffer, 0, mb_strlen($buffer)-1); }
            } elseif ($ord === 10 || $ord === 13) {
                // Enter: keep buffer, just newline
                echo "\n";
            } elseif ($ord >= 32) {
                $buffer .= $ch;
            }

            // Redraw prompt line (simple approach)
            echo "\r> " . $buffer . "    ";

            if (mb_strlen(trim($buffer)) >= 2) {
                runQuery($ys, $index, trim($buffer));
            }
        }
        $cleanup();
    } else {
        // Fallback: line-buffered input
        echo "(Raw mode unavailable, falling back to line input)\n";
        while (true) {
            echo "\n> ";
            $line = fgets(STDIN);
            if ($line === false) break;
            $q = trim($line);
            runQuery($ys, $index, $q);
        }
    }
} else {
    runQuery($ys, $index, $query);
}
