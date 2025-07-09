<?php

/**
 * PHPUnit Bootstrap File for YetiSearch
 */

// Ensure we're in the right directory
$rootDir = dirname(__DIR__);

// Load composer autoloader
$autoloadFile = $rootDir . '/vendor/autoload.php';
if (!file_exists($autoloadFile)) {
    die('You must run "composer install" to install the dependencies.' . PHP_EOL);
}

require_once $autoloadFile;

// Set error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// Set timezone to avoid warnings
date_default_timezone_set('UTC');

// Memory limit for performance tests
ini_set('memory_limit', '256M');

// Create test directories if they don't exist
$testDirs = [
    $rootDir . '/tests/tmp',
    $rootDir . '/tests/tmp/db',
    $rootDir . '/tests/tmp/cache',
    $rootDir . '/build',
    $rootDir . '/build/logs',
    $rootDir . '/build/coverage'
];

foreach ($testDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

// Clean up previous test databases
$testDbDir = $rootDir . '/tests/tmp/db';
if (is_dir($testDbDir)) {
    $files = glob($testDbDir . '/*.db');
    foreach ($files as $file) {
        @unlink($file);
    }
}

// Define test constants
define('YETISEARCH_TEST_ROOT', $rootDir . '/tests');
define('YETISEARCH_TEST_TMP', $rootDir . '/tests/tmp');
define('YETISEARCH_TEST_FIXTURES', $rootDir . '/tests/Fixtures');

// Helper function to get test database path
function getTestDbPath(string $name = 'test'): string {
    return YETISEARCH_TEST_TMP . '/db/' . $name . '.db';
}

// Helper function to clean test databases
function cleanTestDatabases(): void {
    $files = glob(YETISEARCH_TEST_TMP . '/db/*.db');
    foreach ($files as $file) {
        @unlink($file);
    }
}

// Register cleanup function
register_shutdown_function('cleanTestDatabases');

echo "YetiSearch Test Suite" . PHP_EOL;
echo "PHP Version: " . PHP_VERSION . PHP_EOL;
echo "Test Directory: " . YETISEARCH_TEST_ROOT . PHP_EOL;
echo str_repeat('-', 50) . PHP_EOL;