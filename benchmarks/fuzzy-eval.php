<?php

// Local fuzzy evaluation without network
require_once __DIR__ . '/../vendor/autoload.php';

use YetiSearch\YetiSearch;

$config = [
    'storage' => [ 'path' => __DIR__ . '/fuzzy-eval.db' ],
    'indexer' => [
        'fields' => [
            'title' => ['boost' => 3.0, 'store' => true],
            'content' => ['boost' => 1.0, 'store' => true],
            'tags' => ['boost' => 2.0, 'store' => true],
        ],
    ],
    'search' => [ 'enable_fuzzy' => true, 'cache_ttl' => 0 ],
];

$ys = new YetiSearch($config);
$idx = 'fuzzy_eval_local';

// Create index and (re)seed
$indexer = $ys->createIndex($idx);
$ys->clear($idx);

$seed = [
    ['id' => 'doc-anakin',   'content' => ['title' => 'Anakin Skywalker',    'content' => 'Jedi Knight who became Darth Vader', 'tags' => 'starwars skywalker']],
    ['id' => 'doc-luke',     'content' => ['title' => 'Luke Skywalker',      'content' => 'A powerful Jedi Knight', 'tags' => 'starwars skywalker']],
    ['id' => 'doc-dark',     'content' => ['title' => 'The Dark Knight',     'content' => 'Batman vs Joker', 'tags' => 'batman nolan']],
    ['id' => 'doc-inception','content' => ['title' => 'Inception',           'content' => 'Mind-bending heist in dreams', 'tags' => 'nolan sci-fi']],
    ['id' => 'doc-starwars', 'content' => ['title' => 'Star Wars',           'content' => 'A space opera', 'tags' => 'starwars saga']],
];

foreach ($seed as $doc) { $indexer->insert($doc); }
$indexer->flush();

$queries = [
    ['q' => 'Amakin Dkywalker', 'expect' => 'Anakin Skywalker'],
    ['q' => 'Skywaker',         'expect' => 'Skywalker'],
    ['q' => 'Star Wrs',         'expect' => 'Star Wars'],
    ['q' => 'Incepton',         'expect' => 'Inception'],
    ['q' => 'The Dark Knigh',   'expect' => 'The Dark Knight'],
];

$algos = [
    'basic' => [ 'fuzzy_algorithm' => 'basic', 'max_fuzzy_variations' => 6, 'fuzzy_score_penalty' => 0.4 ],
    'jaro_winkler' => [ 'fuzzy_algorithm' => 'jaro_winkler', 'jaro_winkler_threshold' => 0.86, 'jaro_winkler_prefix_scale' => 0.1, 'max_fuzzy_variations' => 6, 'fuzzy_score_penalty' => 0.25 ],
    'trigram' => [ 'fuzzy_algorithm' => 'trigram', 'trigram_threshold' => 0.4, 'trigram_size' => 3, 'min_term_frequency' => 1, 'max_fuzzy_variations' => 8, 'fuzzy_score_penalty' => 0.35 ],
    'levenshtein' => [ 'fuzzy_algorithm' => 'levenshtein', 'levenshtein_threshold' => 2, 'min_term_frequency' => 1, 'max_indexed_terms' => 10000, 'max_fuzzy_variations' => 8, 'fuzzy_score_penalty' => 0.35 ],
];

echo "Fuzzy Evaluation (local dataset)\n===============================\n";
foreach ($algos as $name => $opts) {
    echo "\nAlgorithm: {$name}\n";
    $hit = 0; $tot = 0; $msTotal = 0;
    foreach ($queries as $t) {
        $tot++;
        $start = microtime(true);
        $res = $ys->search($idx, $t['q'], array_merge(['limit' => 5, 'fuzzy' => true, 'fields' => ['title','content','tags']], $opts));
        $ms = (microtime(true) - $start) * 1000;
        $msTotal += $ms;
        $titles = array_map(fn($r) => $r['document']['title'] ?? 'n/a', $res['results']);
        $found = false;
        foreach ($titles as $tt) {
            if (stripos($tt, $t['expect']) !== false) { $found = true; break; }
        }
        $hit += $found ? 1 : 0;
        echo sprintf("  %-20s -> %s  (%.1f ms)\n", $t['q'], $found ? 'FOUND' : 'miss', $ms);
    }
    echo sprintf("  Accuracy: %d/%d  Avg time: %.1f ms\n", $hit, $tot, $msTotal / max(1,$tot));
}

echo "\nDone. DB: " . realpath(__DIR__ . '/fuzzy-eval.db') . "\n";

