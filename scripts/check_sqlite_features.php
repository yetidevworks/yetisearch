#!/usr/bin/env php
<?php
declare(strict_types=1);

// Quick diagnostic for SQLite features (FTS5, FTS4, RTree) used by PDO SQLite

echo "SQLite Feature Check (via PDO)\n";
echo str_repeat('=', 40) . "\n";

printf("PHP: %s\n", PHP_VERSION);
printf("pdo_sqlite: %s\n", phpversion('pdo_sqlite') ?: 'unknown');
printf("sqlite3 ext: %s\n", phpversion('sqlite3') ?: 'unknown');

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    fwrite(STDERR, "Failed to open in-memory SQLite via PDO: {$e->getMessage()}\n");
    exit(1);
}

// Versions
try {
    $ver = $pdo->query('SELECT sqlite_version()')->fetchColumn();
    printf("sqlite_version(): %s\n", $ver ?: 'unknown');
} catch (Throwable $e) {
    printf("sqlite_version(): error: %s\n", $e->getMessage());
}

echo "\nCompile Options (PRAGMA compile_options)\n";
echo str_repeat('-', 40) . "\n";
try {
    $opts = $pdo->query('PRAGMA compile_options')->fetchAll(PDO::FETCH_COLUMN, 0);
    foreach ($opts as $o) {
        echo " - {$o}\n";
    }
    $hasFts5 = (bool)array_filter($opts, fn($o) => stripos($o, 'ENABLE_FTS5') !== false);
    $hasFts4 = (bool)array_filter($opts, fn($o) => stripos($o, 'ENABLE_FTS3') !== false || stripos($o, 'ENABLE_FTS4') !== false);
    $hasRtree = (bool)array_filter($opts, fn($o) => stripos($o, 'ENABLE_RTREE') !== false);
    printf("\nDetected (from compile options): FTS5=%s, FTS3/4=%s, RTREE=%s\n", $hasFts5?'yes':'no', $hasFts4?'yes':'no', $hasRtree?'yes':'no');
} catch (Throwable $e) {
    echo "(Could not read compile options: {$e->getMessage()})\n";
}

echo "\nRuntime Probes\n";
echo str_repeat('-', 40) . "\n";

$checks = [
    'FTS5' => "CREATE VIRTUAL TABLE temp.__fts5_test USING fts5(doc, tokenize='unicode61')",
    'FTS4' => "CREATE VIRTUAL TABLE temp.__fts4_test USING fts4(doc)",
    'RTREE' => "CREATE VIRTUAL TABLE temp.__rtree_test USING rtree(id, minX, maxX, minY, maxY)",
];

foreach ($checks as $name => $ddl) {
    try {
        $pdo->exec($ddl);
        printf("%s: OK\n", $name);
    } catch (Throwable $e) {
        printf("%s: FAIL (%s)\n", $name, $e->getMessage());
    }
}

echo "\nIf FTS5=FAIL, your PDO SQLite is linked without FTS5. Options:\n";
echo " - Use a PHP build linked against a newer libsqlite with FTS5 enabled.\n";
echo " - On macOS, Homebrew PHP typically has FTS5; system PHP may not.\n";
echo " - Ensure pdo_sqlite and sqlite3 extensions use the same library version.\n";

