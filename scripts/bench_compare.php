#!/usr/bin/env php
<?php
declare(strict_types=1);

function normalizeLine(string $line): string {
    // Remove ANSI color codes and normalize whitespace.
    $line = preg_replace('/\x1b\[[0-9;]*m/', '', $line);
    $line = trim($line);
    $line = preg_replace('/\s+/', ' ', $line);
    return (string)$line;
}

function getMetricSeconds(string $path, array $labels): float {
    $lines = @file($path) ?: [];
    foreach ($lines as $raw) {
        $line = normalizeLine($raw);
        foreach ($labels as $label) {
            if (stripos($line, $label . ':') !== 0) {
                continue;
            }
            if (preg_match('/:\s*([0-9][0-9,\.]*)\s*(ms|s)?/i', $line, $m)) {
                $value = (float)str_replace(',', '', $m[1]);
                $unit = strtolower($m[2] ?? 's');
                return $unit === 'ms' ? $value / 1000.0 : $value;
            }
        }
    }
    return 0.0;
}

function getMetricFloat(string $path, array $labels): float {
    $lines = @file($path) ?: [];
    foreach ($lines as $raw) {
        $line = normalizeLine($raw);
        foreach ($labels as $label) {
            if (stripos($line, $label . ':') !== 0) {
                continue;
            }
            if (preg_match('/:\s*([0-9][0-9,\.]*)/i', $line, $m)) {
                return (float)str_replace(',', '', $m[1]);
            }
        }
    }
    return 0.0;
}

$before = __DIR__ . '/../benchmarks/benchmark-before.txt';
$after  = __DIR__ . '/../benchmarks/benchmark-after.txt';
if (!file_exists($before) || !file_exists($after)) {
    fwrite(STDERR, "Missing benchmark files. Run 'make bench-before' and 'make bench-after' first.\n");
    exit(1);
}

// Parse indexing metrics (supports legacy and current benchmark output formats).
$bi = getMetricSeconds($before, ['Indexing time']);
$ai = getMetricSeconds($after,  ['Indexing time']);

$bt = getMetricSeconds($before, ['Total time']);
$at = getMetricSeconds($after,  ['Total time']);
if ($bt <= 0.0) {
    $bt = $bi;
}
if ($at <= 0.0) {
    $at = $ai;
}

$br = getMetricFloat($before, ['Average indexing rate', 'Indexing rate']);
$ar = getMetricFloat($after,  ['Average indexing rate', 'Indexing rate']);
$bm = getMetricFloat($before, ['Memory used']);
$am = getMetricFloat($after,  ['Memory used']);
$bp = getMetricFloat($before, ['Peak memory']);
$ap = getMetricFloat($after,  ['Peak memory']);

echo "Benchmark comparison (legacy vs external-content)\n";
printf("Total time: %.4fs -> %.4fs (Δ %+0.4fs)\n", $bt, $at, $at - $bt);
printf("Indexing time: %.4fs -> %.4fs (Δ %+0.4fs)\n", $bi, $ai, $ai - $bi);
printf("Indexing rate: %.2f -> %.2f docs/s (Δ %+0.2f docs/s)\n", $br, $ar, $ar - $br);
printf("Memory used: %.2f MB -> %.2f MB (Δ %+0.2f MB)\n", $bm, $am, $am - $bm);
printf("Peak memory: %.2f MB -> %.2f MB (Δ %+0.2f MB)\n", $bp, $ap, $ap - $bp);
echo "\nFiles:\n - $before\n - $after\n";

// ---- Search results comparison ----
function parseSearchResults(string $path): array {
    $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
    $mode = null; // 'standard' | 'fuzzy' | null
    $data = [
        'standard' => [],
        'fuzzy' => [],
    ];
    $currentQuery = null;

    $pushResult = function(string $mode, ?string $q, array &$acc, array $curr) {
        if ($mode && $q !== null) {
            if (!isset($acc[$mode][$q])) { $acc[$mode][$q] = $curr; }
        }
    };

    $currBlock = [
        'time_ms' => 0.0,
        'results_found' => 0,
        'total_hits' => 0,
        'titles' => [],
    ];

    foreach ($lines as $rawLine) {
        $line = normalizeLine($rawLine);
        if (stripos($line, '--- Standard Search') === 0 || stripos($line, 'Search Benchmark (Standard)') !== false) {
            $mode = 'standard';
            $currentQuery = null;
            $currBlock = ['time_ms'=>0.0,'results_found'=>0,'total_hits'=>0,'titles'=>[]];
            continue;
        }
        if (stripos($line, '--- Fuzzy Search') === 0 || stripos($line, 'Fuzzy Search Benchmark') !== false) {
            $mode = 'fuzzy';
            $currentQuery = null;
            $currBlock = ['time_ms'=>0.0,'results_found'=>0,'total_hits'=>0,'titles'=>[]];
            continue;
        }
        // End of sections
        if ($mode !== 'standard' && $mode !== 'fuzzy') { continue; }

        if (preg_match("/^Query: '(.+)' \(took ([0-9.]+) ms\)/", $line, $m)) {
            // Push previous if any
            $pushResult($mode, $currentQuery, $data, $currBlock);
            // Start new block
            $currentQuery = $m[1];
            $currBlock = ['time_ms' => (float)$m[2], 'results_found'=>0, 'total_hits'=>0, 'titles'=>[]];
            continue;
        }
        if (preg_match('/^Results found: (\d+) \(Total hits: (\d+)\)/', $line, $m)) {
            $currBlock['results_found'] = (int)$m[1];
            $currBlock['total_hits'] = (int)$m[2];
            continue;
        }
        if (preg_match('/^\s+\d+\.\s+(.+?)\s+\(Score:/', $line, $m)) {
            $currBlock['titles'][] = $m[1];
            continue;
        }

        // Current benchmark.php output format (standard)
        // Example: ✓ 'star wars': 4.4ms | 925 results
        if ($mode === 'standard' && preg_match("/^[✓✗]\s+'(.+)':\s+([0-9.]+)ms\s+\|\s+([0-9]+)\s+results$/u", $line, $m)) {
            $q = $m[1];
            $data['standard'][$q] = [
                'time_ms' => (float)$m[2],
                'results_found' => (int)$m[3],
                'total_hits' => (int)$m[3],
                'titles' => [],
            ];
            continue;
        }

        // Current benchmark.php output format (fuzzy)
        // Example: ✓ 'The Godfathr' -> 'Godfather': 86.4ms | Found
        if ($mode === 'fuzzy' && preg_match("/^[✓✗]\s+'(.+)'\s+->\s+'(.+)':\s+([0-9.]+)ms\s+\|\s+(Found|NOT found)$/u", $line, $m)) {
            $q = $m[1];
            $found = strtolower($m[4]) === 'found';
            $data['fuzzy'][$q] = [
                'time_ms' => (float)$m[3],
                'results_found' => $found ? 1 : 0,
                'total_hits' => $found ? 1 : 0,
                'titles' => [$m[2]],
            ];
            continue;
        }
    }
    // Push last block
    $pushResult($mode ?? '', $currentQuery, $data, $currBlock);
    return $data;
}

$beforeSearch = parseSearchResults($before);
$afterSearch  = parseSearchResults($after);

// Helper to pretty print a comparison line
function printQueryCompare(string $label, string $query, array $b, array $a): void {
    $bTime = $b['time_ms'] ?? 0.0; $aTime = $a['time_ms'] ?? 0.0;
    $bHits = $b['total_hits'] ?? 0; $aHits = $a['total_hits'] ?? 0;
    $deltaMs = $aTime - $bTime; $deltaHits = $aHits - $bHits;
    printf("- %s | '%s': %.2fms -> %.2fms (Δ %+0.2fms); hits %d -> %d (Δ %+d)\n",
        $label, $query, $bTime, $aTime, $deltaMs, $bHits, $aHits, $deltaHits
    );
    $bt = array_slice($b['titles'] ?? [], 0, 3);
    $at = array_slice($a['titles'] ?? [], 0, 3);
    if ($bt || $at) {
        echo "  before: ".($bt ? implode(' | ', $bt) : '(no results)')."\n";
        echo "  after : ".($at ? implode(' | ', $at) : '(no results)')."\n";
    }
}

echo "\nSearch comparison (same queries)\n";
echo "--------------------------------\n";

foreach (['standard' => 'Standard (fuzzy OFF)', 'fuzzy' => 'Fuzzy (fuzzy ON)'] as $sectionKey => $sectionLabel) {
    $bq = array_keys($beforeSearch[$sectionKey] ?? []);
    $aq = array_keys($afterSearch[$sectionKey] ?? []);
    $allQueries = array_values(array_unique(array_merge($bq, $aq)));
    if (!$allQueries) { continue; }
    echo "$sectionLabel\n";
    foreach ($allQueries as $q) {
        $b = $beforeSearch[$sectionKey][$q] ?? [];
        $a = $afterSearch[$sectionKey][$q] ?? [];
        printQueryCompare('Query', $q, $b, $a);
    }
    echo "\n";
}

// Append a dated row to benchmarks/benchmark-results.md under a Run History table
$resultsMd = __DIR__ . '/../benchmarks/benchmark-results.md';
if (file_exists($resultsMd)) {
    $md = file_get_contents($resultsMd);
    $ts = date('Y-m-d H:i:s');
    $row = sprintf("| %s | %.4f | %.4f | %+0.4f | %.2f | %.2f | %+0.2f |\n",
        $ts, $bt, $at, $at - $bt, $br, $ar, $ar - $br
    );
    $header = "## Run History";
    $tableHeader = "| Date | Legacy Total (s) | External Total (s) | Δ Total (s) | Legacy Rate (docs/s) | External Rate (docs/s) | Δ Rate (docs/s) |\n| - | - | - | - | - | - | - |\n";
    if (strpos($md, $header) === false) {
        // Add new section at the top after the main compare table
        $insertionPoint = strpos($md, "## Testing Search Functionality");
        if ($insertionPoint === false) { $insertionPoint = strlen($md); }
        $md = substr($md, 0, $insertionPoint) . "\n\n$header\n\n" . $tableHeader . $row . "\n" . substr($md, $insertionPoint);
    } else {
        // Append a new row after existing Run History header (at the end of that table)
        $pos = strpos($md, $header);
        // Find the end of the Run History section table by locating the next blank line or next header
        $nextHeaderPos = strpos($md, "\n## ", $pos + strlen($header));
        if ($nextHeaderPos === false) { $nextHeaderPos = strlen($md); }
        // Insert just before next header (i.e., append at end of table)
        $md = substr($md, 0, $nextHeaderPos) . $row . substr($md, $nextHeaderPos);
    }
    file_put_contents($resultsMd, $md);
}

// Also emit machine-readable JSON for automation
$jsonOut = __DIR__ . '/../benchmarks/benchmark-compare.json';
$payload = [
    'indexing' => [
        'before' => ['total_time' => $bt, 'index_time' => $bi, 'rate' => $br, 'memory' => $bm, 'peak' => $bp],
        'after'  => ['total_time' => $at, 'index_time' => $ai, 'rate' => $ar, 'memory' => $am, 'peak' => $ap],
        'delta'  => ['total_time' => $at - $bt, 'index_time' => $ai - $bi, 'rate' => $ar - $br, 'memory' => $am - $bm, 'peak' => $ap - $bp],
    ],
    'search' => [
        'standard' => $beforeSearch['standard'] ?? [],
        'fuzzy'    => $beforeSearch['fuzzy'] ?? [],
        'standard_after' => $afterSearch['standard'] ?? [],
        'fuzzy_after'    => $afterSearch['fuzzy'] ?? [],
    ],
    'files' => [ 'before' => $before, 'after' => $after ],
];
file_put_contents($jsonOut, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo "JSON written: $jsonOut\n";
