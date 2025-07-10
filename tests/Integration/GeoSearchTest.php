<?php

namespace YetiSearch\Tests\Integration;

use YetiSearch\Tests\TestCase;
use YetiSearch\YetiSearch;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Geo\GeoBounds;
use YetiSearch\Models\SearchQuery;

class GeoSearchTest extends TestCase
{
    private YetiSearch $search;
    private string $indexName = 'geo_test';
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->search = new YetiSearch([
            'storage' => ['path' => ':memory:']
        ]);
        
        $indexer = $this->search->createIndex($this->indexName);
        
        // Index test locations
        $locations = [
            [
                'id' => 'pdx-coffee-1',
                'title' => 'Stumptown Coffee Roasters',
                'content' => 'Portland original coffee roaster',
                'category' => 'Coffee Shop',
                'address' => '128 SW 3rd Ave, Portland, OR',
                'geo' => ['lat' => 45.5152, 'lng' => -122.6734]
            ],
            [
                'id' => 'pdx-coffee-2',
                'title' => 'Blue Star Donuts',
                'content' => 'Gourmet donuts and coffee',
                'category' => 'Coffee Shop',
                'address' => '1237 SW Washington St, Portland, OR',
                'geo' => ['lat' => 45.5220, 'lng' => -122.6845]
            ],
            [
                'id' => 'pdx-restaurant-1',
                'title' => 'Pok Pok',
                'content' => 'Thai street food restaurant',
                'category' => 'Restaurant',
                'address' => '3226 SE Division St, Portland, OR',
                'geo' => ['lat' => 45.5047, 'lng' => -122.6318]
            ],
            [
                'id' => 'seattle-coffee-1',
                'title' => 'Victrola Coffee Roasters',
                'content' => 'Seattle coffee roaster since 2000',
                'category' => 'Coffee Shop',
                'address' => '310 E Pike St, Seattle, WA',
                'geo' => ['lat' => 47.6145, 'lng' => -122.3278]
            ],
            [
                'id' => 'vancouver-coffee-1',
                'title' => 'Revolver Coffee',
                'content' => 'Vancouver specialty coffee',
                'category' => 'Coffee Shop',
                'address' => '325 Cambie St, Vancouver, BC',
                'geo' => ['lat' => 49.2835, 'lng' => -123.1089]
            ]
        ];
        
        $indexer->indexBatch($locations);
        $indexer->flush();
    }
    
    public function testSearchNearPoint(): void
    {
        // Search for coffee shops near downtown Portland
        $query = new SearchQuery('coffee');
        $query->near(new GeoPoint(45.5152, -122.6784), 5000); // 5km radius
        
        $searchEngine = $this->search->getSearchEngine($this->indexName);
        $results = $searchEngine->search($query);
        
        $this->assertCount(2, $results->getResults());
        
        // Check that results have distance
        foreach ($results->getResults() as $result) {
            $this->assertTrue($result->hasDistance());
            $this->assertLessThanOrEqual(5000, $result->getDistance());
            
            // Should only find Portland coffee shops
            $this->assertStringContainsString('Portland', $result->get('address'));
        }
    }
    
    public function testSearchWithinBounds(): void
    {
        // Define bounds for Portland area
        $query = new SearchQuery('coffee');
        $query->withinBounds(45.55, 45.48, -122.60, -122.70);
        
        $searchEngine = $this->search->getSearchEngine($this->indexName);
        $results = $searchEngine->search($query);
        
        $this->assertCount(2, $results->getResults());
        
        foreach ($results->getResults() as $result) {
            $this->assertStringContainsString('Portland', $result->get('address'));
        }
    }
    
    public function testSortByDistance(): void
    {
        // Search all coffee shops, sorted by distance from a point
        $centerPoint = new GeoPoint(45.5152, -122.6784); // Downtown Portland
        
        $query = new SearchQuery('coffee');
        $query->sortByDistance($centerPoint, 'asc');
        
        $searchEngine = $this->search->getSearchEngine($this->indexName);
        $results = $searchEngine->search($query);
        
        $distances = [];
        foreach ($results->getResults() as $result) {
            $this->assertTrue($result->hasDistance());
            $distances[] = $result->getDistance();
        }
        
        // Verify distances are in ascending order
        $sortedDistances = $distances;
        sort($sortedDistances);
        $this->assertEquals($sortedDistances, $distances);
        
        // First result should be Stumptown (closest to center)
        $this->assertEquals('Stumptown Coffee Roasters', $results->getResults()[0]->get('title'));
    }
    
    public function testCombineTextAndGeoSearch(): void
    {
        // Search for restaurants within 10km of downtown Portland
        $query = new SearchQuery('restaurant');
        $query->near(new GeoPoint(45.5152, -122.6784), 10000);
        
        $searchEngine = $this->search->getSearchEngine($this->indexName);
        $results = $searchEngine->search($query);
        
        $this->assertCount(1, $results->getResults());
        $this->assertEquals('Pok Pok', $results->getResults()[0]->get('title'));
    }
    
    public function testEmptyGeoResults(): void
    {
        // Search for coffee in an area with no results (middle of Pacific Ocean)
        $query = new SearchQuery('coffee');
        $query->near(new GeoPoint(30.0, -150.0), 1000);
        
        $searchEngine = $this->search->getSearchEngine($this->indexName);
        $results = $searchEngine->search($query);
        
        $this->assertCount(0, $results->getResults());
    }
    
    public function testLargeRadiusSearch(): void
    {
        // Search with very large radius should find all coffee shops
        $query = new SearchQuery('coffee');
        $query->near(new GeoPoint(45.5152, -122.6784), 1000000); // 1000km radius
        
        $searchEngine = $this->search->getSearchEngine($this->indexName);
        $results = $searchEngine->search($query);
        
        $this->assertCount(4, $results->getResults()); // All coffee shops
    }
    
    public function testGeoSearchWithFilters(): void
    {
        // Search for coffee shops in Portland area with additional filter
        $query = new SearchQuery('coffee');
        $query->near(new GeoPoint(45.5152, -122.6784), 5000)
              ->filter('category', 'Coffee Shop');
        
        $searchEngine = $this->search->getSearchEngine($this->indexName);
        $results = $searchEngine->search($query);
        
        $this->assertCount(2, $results->getResults());
        
        foreach ($results->getResults() as $result) {
            $this->assertEquals('Coffee Shop', $result->get('category'));
        }
    }
    
    public function testIndexDocumentWithBounds(): void
    {
        $indexer = $this->search->getIndexer($this->indexName);
        
        // Index a document with bounds instead of point
        $indexer->index([
            'id' => 'portland-metro',
            'title' => 'Portland Metro Area',
            'content' => 'Greater Portland metropolitan area',
            'geo_bounds' => [
                'north' => 45.65,
                'south' => 45.40,
                'east' => -122.40,
                'west' => -122.85
            ]
        ]);
        $indexer->flush();
        
        // Search within those bounds
        $query = new SearchQuery('metro');
        $query->within(new GeoBounds(45.55, 45.45, -122.50, -122.80));
        
        $searchEngine = $this->search->getSearchEngine($this->indexName);
        $results = $searchEngine->search($query);
        
        $this->assertCount(1, $results->getResults());
        $this->assertEquals('Portland Metro Area', $results->getResults()[0]->get('title'));
    }
}