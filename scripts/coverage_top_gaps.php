<?php
declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/coverage_top_gaps.php <clover.xml> [limit]\n");
    exit(1);
}

$file = $argv[1];
$limit = isset($argv[2]) ? (int)$argv[2] : 15;
if (!is_file($file)) {
    fwrite(STDERR, "File not found: {$file}\n");
    exit(1);
}

$xml = @simplexml_load_file($file);
if ($xml === false) {
    fwrite(STDERR, "Failed to parse clover XML: {$file}\n");
    exit(1);
}

$files = [];
foreach ($xml->project->file as $f) {
    $name = (string)$f['name'];
    $linesTotal = 0; $linesCovered = 0;
    foreach ($f->metrics as $m) {
        $linesTotal += (int)$m['loc'];
        // Prefer statements/covered if present
        if (isset($m['statements']) && isset($m['coveredstatements'])) {
            $linesTotal = (int)$m['statements'];
            $linesCovered = (int)$m['coveredstatements'];
        }
    }
    if ($linesTotal <= 0) { continue; }
    $pct = $linesTotal > 0 ? ($linesCovered / max(1, $linesTotal)) * 100.0 : 0.0;
    $files[] = [
        'name' => $name,
        'covered' => $linesCovered,
        'total' => $linesTotal,
        'pct' => $pct,
    ];
}

usort($files, function($a, $b){
    if ($a['pct'] === $b['pct']) {
        return $a['total'] <=> $b['total'];
    }
    return $a['pct'] <=> $b['pct'];
});

$files = array_slice($files, 0, $limit);

echo "Lowest-covered files (by statements):\n";
foreach ($files as $i => $f) {
    printf(
        "%2d) %6.2f%%  %5d/%-5d  %s\n",
        $i+1,
        $f['pct'],
        $f['covered'],
        $f['total'],
        $f['name']
    );
}

