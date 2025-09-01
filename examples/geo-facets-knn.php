<?php
// Example: Distance facets + k-NN nearest
require_once __DIR__ . '/../vendor/autoload.php';

use YetiSearch\YetiSearch;
use YetiSearch\Geo\GeoPoint;

$ys = new YetiSearch([
  'storage' => [
    // Demonstrate external-content schema explicitly
    'external_content' => true,
  ],
  'search' => [
    'geo_units' => 'km',          // default units for convenience
    'distance_weight' => 0.4,     // blend text + distance
    'distance_decay_k' => 0.01,
  ]
]);

$index = 'geo_demo';
try { $ys->dropIndex($index); } catch (Throwable $e) {}
// Force external-content on index creation
$ys->createIndex($index, ['external_content' => true]);

// Seed a few places around NYC
$docs = [
  ['id' => 'a', 'content' => ['title' => 'Coffee A', 'content' => 'cozy coffee shop'], 'geo' => ['lat' => 40.73061, 'lng' => -73.935242]],
  ['id' => 'b', 'content' => ['title' => 'Coffee B', 'content' => 'best espresso'],    'geo' => ['lat' => 40.741,    'lng' => -73.99]],
  ['id' => 'c', 'content' => ['title' => 'Coffee C', 'content' => 'roastery'],         'geo' => ['lat' => 40.752,    'lng' => -73.98]],
  ['id' => 'd', 'content' => ['title' => 'Coffee D', 'content' => 'bakery & cafe'],    'geo' => ['lat' => 40.780,    'lng' => -73.96]],
];
$ys->indexBatch($index, $docs);

$center = ['lat' => 40.748817, 'lng' => -73.985428]; // Midtown Manhattan

// Distance facets (km)
$faceted = $ys->search($index, 'coffee', [
  'facets' => [
    'distance' => [
      'from' => $center,
      'ranges' => [1, 3, 5],
      'units' => 'km'
    ]
  ],
  'geoFilters' => [
    'distance_sort' => ['from' => $center, 'direction' => 'asc']
  ]
]);

echo "Distance facets (km):\n";
foreach (($faceted['facets']['distance'] ?? []) as $b) {
  echo " - {$b['value']}: {$b['count']}\n";
}

// k-NN nearest 3
$knn = $ys->search($index, '', [
  'limit' => 3,
  'geoFilters' => [
    'nearest' => 3,
    'distance_sort' => ['from' => $center, 'direction' => 'asc'],
    'units' => 'km'
  ]
]);

echo "\nNearest 3:\n";
foreach ($knn['results'] as $r) {
  $title = $r['document']['title'] ?? 'Untitled';
  $dist = $r['distance'] ?? 0;
  echo sprintf(" - %s (%.2f km)\n", $title, $dist / 1000.0);
}
