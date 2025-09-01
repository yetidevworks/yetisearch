<?php
// Geo benchmark: seeds random points, runs near queries at various radii
require_once __DIR__ . '/../vendor/autoload.php';

use YetiSearch\YetiSearch;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Models\SearchQuery;

$count = (int)($argv[1] ?? 50000);
$units = strtolower((string)($argv[2] ?? 'm')); // m | km | mi
$iters = (int)($argv[3] ?? 1);
$doFacets = (string)($argv[4] ?? '') === 'facets';
if (!in_array($units, ['m','km','mi'], true)) { $units = 'm'; }
echo "Geo Benchmark (points: {$count}, units: {$units}, iters: {$iters})\n";

$ys = new YetiSearch([
  'storage' => ['path' => __DIR__ . '/geo-bench.db'],
  'analyzer' => ['remove_stop_words' => false, 'disable_stop_words' => true],
]);
$index = 'geo_bench';

echo "Creating index...\n";
$ys->dropIndex($index);
$ys->createIndex($index);

// Seed random points across the globe
echo "Seeding...\n";
$batch = [];
for ($i = 0; $i < $count; $i++) {
    $lat = mt_rand(-900000, 900000) / 10000.0;    // -90..90
    $lng = mt_rand(-1800000, 1800000) / 10000.0;  // -180..180
    $batch[] = [
        'id' => 'p' . $i,
        'content' => [
            'title' => 'P' . $i,
            'content' => 'random point',
        ],
        'geo' => ['lat' => $lat, 'lng' => $lng],
    ];
    if (count($batch) >= 2000) {
        $ys->indexBatch($index, $batch);
        $batch = [];
    }
}
if (!empty($batch)) $ys->indexBatch($index, $batch);

echo "Running near queries...\n";
$center = new GeoPoint(37.7749, -122.4194); // SF
$radiiUnits = $units === 'm' ? [1000, 5000, 10000, 50000, 100000]
                             : [1, 5, 10, 50, 100];

$factor = 1.0;
if ($units === 'km') { $factor = 1000.0; }
elseif ($units === 'mi') { $factor = 1609.344; }

$engine = $ys->getSearchEngine($index);
foreach ($radiiUnits as $ru) {
    $r = (int)round($ru * $factor); // meters
    $q = new SearchQuery('');
    $q->near($center, $r)->sortByDistance($center, 'asc')->limit(1000);

    $msAcc = 0.0; $shown=0; $total=0;
    for ($i=0; $i < max(1,$iters); $i++) {
        $start = microtime(true);
        $results = $engine->search($q);
        $msAcc += (microtime(true) - $start) * 1000;
        $shown = count($results->getResults());
        $total = $results->getTotalCount();
    }
    $ms = $msAcc / max(1,$iters);
    if ($units === 'm') {
        echo sprintf("radius=%6dm  shown=%5d  total=%5d  time=%.1fms\n", $r, $shown, $total, $ms);
    } else {
        echo sprintf("radius=%6d%s  shown=%5d  total=%5d  time=%.1fms\n", $ru, $units, $shown, $total, $ms);
    }
}

echo "Done. DB: " . realpath(__DIR__ . '/geo-bench.db') . "\n";

if ($doFacets) {
    echo "\nDistance facets (demo bands)\n";
    $ranges = $units === 'm' ? [1000, 5000, 10000, 20000] : [1, 5, 10, 20];
    $faceted = $ys->search($index, '', [
      'facets' => [
        'distance' => [
          'from' => $center->toArray(),
          'ranges' => ($units === 'm' ? [1,5,10,20] : [1,5,10,20]),
          'units' => $units
        ]
      ],
      'geoFilters' => [
        'distance_sort' => ['from' => $center->toArray(), 'direction' => 'asc']
      ]
    ]);
    foreach (($faceted['facets']['distance'] ?? []) as $b) {
      echo " - {$b['value']}: {$b['count']}\n";
    }
}
