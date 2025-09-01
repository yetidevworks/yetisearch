#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use YetiSearch\YetiSearch;

function parseArgs(array $argv): array {
    $args = [
        'db' => null,
        'index' => null,
        'prefix' => null,
    ];
    foreach ($argv as $arg) {
        if (strpos($arg, '--db=') === 0) $args['db'] = substr($arg, 5);
        if (strpos($arg, '--index=') === 0) $args['index'] = substr($arg, 8);
        if (strpos($arg, '--prefix=') === 0) $args['prefix'] = substr($arg, 9);
    }
    return $args;
}

function usage(): void {
    fwrite(STDERR, "\nUsage: php scripts/migrate_fts.php --db=/path/to.db --index=NAME [--prefix=2,3]\n\n");
    fwrite(STDERR, "Notes:\n- The script reads all documents, drops the index, recreates FTS as multi-column, and reinserts.\n- If --prefix is set, FTS5 prefix indexing is enabled (e.g., 2,3).\n\n");
}

$a = parseArgs(array_slice($argv, 1));

// Defaults: try benchmarks DB and 'movies' index
if (!$a['db']) {
    $benchDb = __DIR__ . '/../benchmarks/benchmark.db';
    if (file_exists($benchDb)) $a['db'] = realpath($benchDb);
}
if (!$a['index']) {
    $a['index'] = 'movies';
}

if (!$a['db'] || !$a['index']) {
    usage();
    exit(1);
}

echo "Migrating index '{$a['index']}' in DB: {$a['db']}\n";

// Build base config
$config = [
    'storage' => ['path' => $a['db']],
    'indexer' => [
        'fields' => [
            'title' => ['boost' => 3.0, 'store' => true],
            'content' => ['boost' => 1.0, 'store' => true],
            'tags' => ['boost' => 2.0, 'store' => true],
            'overview' => ['boost' => 1.0, 'store' => true],
            'excerpt' => ['boost' => 2.0, 'store' => true],
        ],
        'fts' => [
            'multi_column' => true,
        ],
    ],
];

if (!empty($a['prefix'])) {
    $prefix = array_values(array_filter(array_map('intval', explode(',', $a['prefix']))));
    if (!empty($prefix)) {
        $config['indexer']['fts']['prefix'] = $prefix;
        echo " - Enabling FTS prefix: " . implode(',', $prefix) . "\n";
    }
}

$ys = new YetiSearch($config);

// Step 1: Read existing documents
// We'll use the storage directly to fetch existing rows.
$ref = new ReflectionClass($ys);
$m = $ref->getMethod('getStorage');
$m->setAccessible(true);
/** @var \YetiSearch\Storage\SqliteStorage $storage */
$storage = $m->invoke($ys);

$pdoRef = new ReflectionClass($storage);
$prop = $pdoRef->getProperty('connection');
$prop->setAccessible(true);
/** @var PDO $pdo */
$pdo = $prop->getValue($storage);

$index = $a['index'];

try {
    $stmt = $pdo->query("SELECT id, content, metadata, language, type, timestamp FROM {$index}");
} catch (Throwable $e) {
    fwrite(STDERR, "Error: index table '{$index}' not found in DB.\n");
    exit(1);
}

$docs = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $docs[] = [
        'id' => $row['id'],
        'content' => json_decode($row['content'] ?? '{}', true) ?: [],
        'metadata' => json_decode($row['metadata'] ?? '{}', true) ?: [],
        'language' => $row['language'] ?? null,
        'type' => $row['type'] ?? 'default',
        'timestamp' => (int)($row['timestamp'] ?? time()),
    ];
}

echo " - Extracted " . count($docs) . " documents\n";

// Step 2: Drop and recreate index with multi-column FTS
$ys->dropIndex($index);

// Optionally infer additional fields from content
$fieldCounts = [];
foreach ($docs as $d) {
    foreach ($d['content'] as $k => $v) {
        if (is_string($v) || is_numeric($v)) {
            $fieldCounts[$k] = ($fieldCounts[$k] ?? 0) + 1;
        }
    }
}
foreach ($fieldCounts as $k => $cnt) {
    if (!isset($config['indexer']['fields'][$k])) {
        $config['indexer']['fields'][$k] = ['boost' => 1.0, 'store' => true];
    }
}

// Recreate YetiSearch with expanded fields
$ys = new YetiSearch($config);
$ys->createIndex($index);

// Step 3: Reinsert all documents
$batch = [];
$batchSize = 200;
$inserted = 0;
foreach ($docs as $d) {
    $batch[] = $d;
    if (count($batch) >= $batchSize) {
        $ys->indexBatch($index, $batch);
        $inserted += count($batch);
        $batch = [];
        echo " - Reinserted {$inserted} docs\n";
    }
}
if (!empty($batch)) {
    $ys->indexBatch($index, $batch);
    $inserted += count($batch);
}

echo " - Reinserted total: {$inserted} docs\n";
echo "Migration complete.\n";

