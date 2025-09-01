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
