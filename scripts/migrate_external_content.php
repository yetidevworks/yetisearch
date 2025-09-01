#!/usr/bin/env php
<?php
// Migrate a legacy index to external-content/doc_id schema and rebuild FTS + spatial

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Exceptions\StorageException;

function out($s = "") { fwrite(STDOUT, $s . PHP_EOL); }
function err($s) { fwrite(STDERR, $s . PHP_EOL); }

// Parse args: --db=PATH --index=NAME [--prefix=2,3]
$argv0 = array_shift($argv);
$opts = [];
foreach ($argv as $a) {
    if (substr($a,0,2) === '--') {
        $eq = strpos($a,'=');
        if ($eq !== false) { $k = substr($a,2,$eq-2); $v = substr($a,$eq+1); }
        else { $k = substr($a,2); $v = true; }
        $opts[$k] = $v;
    }
}

$db = $opts['db'] ?? (__DIR__ . '/../yetisearch.db');
$index = $opts['index'] ?? null;
if (!$index) { err('Usage: migrate_external_content.php --db=PATH --index=NAME'); exit(1); }

$storage = new SqliteStorage();
$storage->connect([
    'path' => $db,
    // Set default so future createIndex uses external-content by default
    'external_content' => true,
]);

$pdo = (new ReflectionClass($storage))->getProperty('connection');
$pdo->setAccessible(true);
/** @var PDO $conn */
$conn = $pdo->getValue($storage);

out("Migrating index '{$index}' to external-content schema...");
try {
    $conn->beginTransaction();

    // Create new docs2 table with doc_id INTEGER
    $conn->exec("CREATE TABLE IF NOT EXISTS {$index}_docs2 (doc_id INTEGER PRIMARY KEY, id TEXT UNIQUE, content TEXT NOT NULL, metadata TEXT, language TEXT, type TEXT DEFAULT 'default', timestamp INTEGER)");
    $conn->exec("INSERT INTO {$index}_docs2 (id, content, metadata, language, type, timestamp) SELECT id, content, metadata, language, type, timestamp FROM {$index}");

    // Swap tables
    $conn->exec("ALTER TABLE {$index} RENAME TO {$index}_old");
    $conn->exec("ALTER TABLE {$index}_docs2 RENAME TO {$index}");

    // Meta table
    $conn->exec("CREATE TABLE IF NOT EXISTS {$index}_meta (key TEXT PRIMARY KEY, value TEXT)");
    $stmt = $conn->prepare("INSERT OR REPLACE INTO {$index}_meta (key,value) VALUES ('schema_mode','external')");
    $stmt->execute();

    // Recreate spatial (drop id_map)
    $conn->exec("DROP TABLE IF EXISTS {$index}_spatial");
    $conn->exec("DROP TABLE IF EXISTS {$index}_id_map");
    $conn->exec("CREATE VIRTUAL TABLE {$index}_spatial USING rtree(id, minLat, maxLat, minLng, maxLng)");

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollBack();
    err('Migration failed: ' . $e->getMessage());
    exit(1);
}

// Rebuild FTS content using library helpers
try {
    $storage->rebuildFts($index);
    out('FTS rebuilt.');
} catch (Throwable $e) {
    err('Failed rebuilding FTS: ' . $e->getMessage());
    exit(1);
}

out('Done.');
exit(0);

