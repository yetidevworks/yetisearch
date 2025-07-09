#!/usr/bin/env php
<?php

/**
 * Enhanced test runner with better CLI output
 */

// Colors for terminal output
$colors = [
    'reset'  => "\033[0m",
    'bold'   => "\033[1m",
    'green'  => "\033[32m",
    'red'    => "\033[31m",
    'yellow' => "\033[33m",
    'blue'   => "\033[34m",
    'cyan'   => "\033[36m",
    'gray'   => "\033[90m",
];

// Parse command line arguments
$args = array_slice($argv, 1);
$filter = '';
$suite = '';

foreach ($args as $arg) {
    if (strpos($arg, '--filter=') === 0) {
        $filter = substr($arg, 9);
    } elseif (strpos($arg, '--suite=') === 0) {
        $suite = substr($arg, 8);
    }
}

// Clear screen
echo "\033[H\033[J";

// Header
echo $colors['bold'] . $colors['cyan'] . "
╔════════════════════════════════════════════════════════════════════╗
║                       YetiSearch Test Runner                       ║
╚════════════════════════════════════════════════════════════════════╝
" . $colors['reset'] . "\n";

// Build PHPUnit command
$cmd = 'vendor/bin/phpunit';
$cmd .= ' --printer=PHPUnit\\TextUI\\ResultPrinter';
$cmd .= ' --colors=always';
$cmd .= ' --testdox';

if ($filter) {
    $cmd .= " --filter=\"$filter\"";
    echo $colors['yellow'] . "Filter: " . $colors['reset'] . $filter . "\n";
}

if ($suite) {
    $cmd .= " --testsuite=\"$suite\"";
    echo $colors['yellow'] . "Suite: " . $colors['reset'] . $suite . "\n";
}

echo "\n";

// Execute tests
$output = [];
$returnCode = 0;
exec($cmd . ' 2>&1', $output, $returnCode);

// Process output
$inProgress = false;
$testCount = 0;
$passCount = 0;
$failCount = 0;

foreach ($output as $line) {
    // Skip progress indicators
    if (strpos($line, 'running tests') !== false || strpos($line, '[1K') !== false) {
        continue;
    }
    
    // Count results
    if (strpos($line, '✔') !== false) {
        $passCount++;
        $testCount++;
    } elseif (strpos($line, '✘') !== false || strpos($line, 'F') === 0) {
        $failCount++;
        $testCount++;
    }
    
    // Format section headers
    if (preg_match('/^\[4m(.+?)\[0m$/', $line, $matches)) {
        echo "\n" . $colors['bold'] . $colors['blue'] . "▸ " . $matches[1] . $colors['reset'] . "\n";
        continue;
    }
    
    // Format test results
    if (strpos($line, '✔') !== false) {
        $line = str_replace('[32m✔[0m', $colors['green'] . '  ✓' . $colors['reset'], $line);
        $line = preg_replace('/\[32m (\d+) \[2mms\[0m/', $colors['gray'] . ' ($1ms)' . $colors['reset'], $line);
    }
    
    if (strpos($line, '✘') !== false) {
        $line = str_replace('✘', $colors['red'] . '  ✗' . $colors['reset'], $line);
    }
    
    echo $line . "\n";
}

// Summary
echo "\n" . $colors['bold'] . str_repeat('─', 70) . $colors['reset'] . "\n";

if ($failCount === 0) {
    echo $colors['green'] . $colors['bold'] . "✓ ALL TESTS PASSED!" . $colors['reset'] . "\n";
} else {
    echo $colors['red'] . $colors['bold'] . "✗ TESTS FAILED!" . $colors['reset'] . "\n";
}

echo "\n";
echo "Total Tests: " . $colors['bold'] . $testCount . $colors['reset'] . "\n";
echo "Passed: " . $colors['green'] . $passCount . $colors['reset'] . "\n";
echo "Failed: " . $colors['red'] . $failCount . $colors['reset'] . "\n";

// Show available commands
echo "\n" . $colors['gray'] . "Available commands:" . $colors['reset'] . "\n";
echo $colors['gray'] . "  composer test                 " . $colors['reset'] . "# Run all tests (simple output)\n";
echo $colors['gray'] . "  composer test:verbose         " . $colors['reset'] . "# Run with descriptive names\n";
echo $colors['gray'] . "  composer test:pretty          " . $colors['reset'] . "# Run with enhanced formatting\n";
echo $colors['gray'] . "  composer test:coverage        " . $colors['reset'] . "# Run with text coverage report\n";
echo $colors['gray'] . "  composer test:coverage-html   " . $colors['reset'] . "# Generate HTML coverage report\n";
echo $colors['gray'] . "  composer test:filter TestName " . $colors['reset'] . "# Run specific test\n";
echo $colors['gray'] . "  php test-runner.php           " . $colors['reset'] . "# Use this enhanced runner\n";

exit($returnCode);