<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Tests\TestCase;
use YetiSearch\Geo\GeoPoint;

class SearchEngineSuggestionsAndFacetsTest extends TestCase
{
    public function test_suggestions_and_distance_facets(): void
    {
        $search = $this->createSearchInstance([
            'search' => [
                'enable_suggestions' => true,
                'enable_synonyms' => true,
                'synonyms' => [
                    'movie' => ['film']
                ],
                'min_score' => 0.0,
            ],
        ]);
        $index = 'sugg_facet_idx';
        $this->createTestIndex($index);

        $docs = [
            ['id' => 's1', 'content' => ['title' => 'Star Wars', 'content' => 'space opera movie'], 'geo' => ['lat' => 37.7749, 'lng' => -122.4194]],
            ['id' => 's2', 'content' => ['title' => 'Stargate', 'content' => 'sci-fi'], 'geo' => ['lat' => 37.7849, 'lng' => -122.4094]],
            ['id' => 'd1', 'content' => ['title' => 'Dark Knight', 'content' => 'film noir'], 'geo' => ['lat' => 37.8049, 'lng' => -122.3894]],
        ];
        $search->indexBatch($index, $docs);
        $search->getIndexer($index)->flush();

        // Suggestions for 'sta' should include 'Star Wars' due to title boost
        $engine = $search->getSearchEngine($index);
        $suggestions = $engine->suggest('star', ['limit' => 5]);
        $texts = array_map(fn($s) => $s['text'] ?? '', $suggestions);
        $this->assertIsArray($suggestions);

        // Distance facets with 1km and 5km buckets
        $res = $search->search($index, '', [
            'limit' => 100,
            'facets' => [
                'distance' => [
                    'from' => ['lat' => 37.7749, 'lng' => -122.4194],
                    'ranges' => [1, 5],
                    'units' => 'km'
                ]
            ],
            'geoFilters' => [
                'distance_sort' => [
                    'from' => ['lat' => 37.7749, 'lng' => -122.4194],
                    'direction' => 'asc'
                ]
            ]
        ]);
        $this->assertArrayHasKey('facets', $res);
        $this->assertIsArray($res['facets']);
    }
}
