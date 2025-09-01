#!/usr/bin/env php
<?php
declare(strict_types=1);

function getMetric(string $path, string $key): string {
    $lines = @file($path) ?: [];
    foreach ($lines as $line) {
        if (strpos($line, $key) === 0) {
            $parts = preg_split('/\s+/', trim($line));
            if ($key === 'Average indexing rate') {
                return str_replace(',', '', $parts[3] ?? '0');
            }
            return $parts[2] ?? '0';
        }
    }
    return '0';
}

$before = __DIR__ . '/../benchmarks/benchmark-before.txt';
$after  = __DIR__ . '/../benchmarks/benchmark-after.txt';
if (!file_exists($before) || !file_exists($after)) {
    fwrite(STDERR, "Missing benchmark files. Run 'make bench-before' and 'make bench-after' first.\n");
    exit(1);
}

// Parse indexing metrics
$bt = (float)getMetric($before, 'Total time');
$at = (float)getMetric($after,  'Total time');
$bi = (float)getMetric($before, 'Indexing time');
$ai = (float)getMetric($after,  'Indexing time');
$br = (float)getMetric($before, 'Average indexing rate');
$ar = (float)getMetric($after,  'Average indexing rate');
$bm = (float)getMetric($before, 'Memory used');
$am = (float)getMetric($after,  'Memory used');
$bp = (float)getMetric($before, 'Peak memory');
$ap = (float)getMetric($after,  'Peak memory');

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

    foreach ($lines as $line) {
        if (strpos($line, '--- Standard Search') === 0) { $mode = 'standard'; $currentQuery = null; $currBlock = ['time_ms'=>0.0,'results_found'=>0,'total_hits'=>0,'titles'=>[]]; continue; }
        if (strpos($line, '--- Fuzzy Search') === 0)    { $mode = 'fuzzy';    $currentQuery = null; $currBlock = ['time_ms'=>0.0,'results_found'=>0,'total_hits'=>0,'titles'=>[]]; continue; }
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
