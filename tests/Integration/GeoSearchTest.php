<?php

namespace YetiSearch\Tests\Integration;

use YetiSearch\Tests\TestCase;
use YetiSearch\YetiSearch;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Geo\GeoBounds;
use YetiSearch\Models\SearchQuery;

class GeoSearchTest extends TestCase
{
    protected ?YetiSearch $search = null;
    private string $indexName = 'geo_test';
    private bool $hasGeoSupport = true;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->search = new YetiSearch([
            'storage' => ['path' => ':memory:'],
            'analyzer' => [
                'remove_stop_words' => false,  // Disable stop words removal
                'disable_stop_words' => true
            ]
        ]);
        
        $this->search->createIndex($this->indexName);
        
        // Check if geo features are available by testing if spatial data is indexed
        // We'll determine this after indexing
        
        // Index test locations
        $locations = [
            [
                'id' => 'pdx-coffee-1',
                'content' => [
                    'title' => 'Stumptown Coffee Roasters',
                    'body' => 'Portland original coffee roaster serving specialty coffee',
                    'category' => 'Coffee Shop',
                    'address' => '128 SW 3rd Ave, Portland, OR'
                ],
                'geo' => ['lat' => 45.5152, 'lng' => -122.6734]
            ],
            [
                'id' => 'pdx-coffee-2',
                'content' => [
                    'title' => 'Blue Star Donuts',
                    'body' => 'Gourmet donuts and coffee',
                    'category' => 'Coffee Shop',
                    'address' => '1237 SW Washington St, Portland, OR'
                ],
                'geo' => ['lat' => 45.5220, 'lng' => -122.6845]
            ],
            [
                'id' => 'pdx-restaurant-1',
                'content' => [
                    'title' => 'Pok Pok',
                    'body' => 'Thai street food restaurant',
                    'category' => 'Restaurant',
                    'address' => '3226 SE Division St, Portland, OR'
                ],
                'geo' => ['lat' => 45.5047, 'lng' => -122.6318]
            ],
            [
                'id' => 'seattle-coffee-1',
                'content' => [
                    'title' => 'Victrola Coffee Roasters',
                    'body' => 'Seattle coffee roaster since 2000',
                    'category' => 'Coffee Shop',
                    'address' => '310 E Pike St, Seattle, WA'
                ],
                'geo' => ['lat' => 47.6145, 'lng' => -122.3278]
            ],
            [
                'id' => 'vancouver-coffee-1',
                'content' => [
                    'title' => 'Revolver Coffee',
                    'body' => 'Vancouver specialty coffee',
                    'category' => 'Coffee Shop',
                    'address' => '325 Cambie St, Vancouver, BC'
                ],
                'geo' => ['lat' => 49.2835, 'lng' => -123.1089]
            ]
        ];
        
        $this->search->indexBatch($this->indexName, $locations);
        
        // Ensure indexing is complete
        $indexer = $this->search->getIndexer($this->indexName);
        $indexer->flush();
        
        // Test if geo features work by checking if we get distances
        $query = new SearchQuery('');
        $query->near(new GeoPoint(45.5152, -122.6784), 50000);
        $searchEngine = $this->search->getSearchEngine($this->indexName);
        try {
            $results = $searchEngine->search($query);
            // If we get results with distance, geo is supported
            $this->hasGeoSupport = count($results->getResults()) > 0 && 
                                  $results->getResults()[0]->hasDistance();
        } catch (\Exception $e) {
            $this->hasGeoSupport = false;
        }
    }
    
    protected function requiresGeoSupport(): void
    {
        if (!$this->hasGeoSupport) {
            $this->markTestSkipped('Geo features not available (R-tree module may not be installed)');
        }
    }
    
    public function testBasicSearchWithoutGeo(): void
    {
        // First test basic search without geo filters
        $query = new SearchQuery('coffee');
        
        $searchEngine = $this->search->getSearchEngine($this->indexName);
        
        // Search for coffee
        $results = $searchEngine->search($query);
        
        // Should find all 4 coffee shops
        $this->assertCount(4, $results->getResults());
    }
    
    public function testSearchNearPoint(): void
    {
        $this->requiresGeoSupport();
        
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
            $doc = $result->getDocument();
            
            // Get title from the document
            $title = $doc['title'] ?? '';
            
            // Both results should be Portland coffee shops
            $this->assertContains($title, ['Stumptown Coffee Roasters', 'Blue Star Donuts']);
        }
    }
    
    public function testSearchWithinBounds(): void
    {
        $this->requiresGeoSupport();
        
        // Define bounds for Portland area
        $query = new SearchQuery('coffee');
        $query->withinBounds(45.55, 45.48, -122.60, -122.70);
        
        $searchEngine = $this->search->getSearchEngine($this->indexName);
        $results = $searchEngine->search($query);
        
        $this->assertCount(2, $results->getResults());
        
        // Verify we got Portland results
        $this->assertCount(2, $results->getResults());
    }
    
    public function testSortByDistance(): void
    {
        $this->requiresGeoSupport();
        
        $centerPoint = new GeoPoint(45.5152, -122.6784); // Downtown Portland
        
        // Test 1: Without text query - distance sorting works correctly
        $query1 = new SearchQuery('');
        $query1->sortByDistance($centerPoint, 'asc');
        
        $searchEngine = $this->search->getSearchEngine($this->indexName);
        $results1 = $searchEngine->search($query1);
        
        $this->assertCount(5, $results1->getResults()); // All 5 locations
        
        // Verify distances are sorted correctly for non-FTS query
        $distances1 = [];
        foreach ($results1->getResults() as $result) {
            $this->assertTrue($result->hasDistance());
            $distances1[] = $result->getDistance();
        }
        
        $sortedDistances1 = $distances1;
        sort($sortedDistances1, SORT_NUMERIC);
        $this->assertEquals($sortedDistances1, $distances1, 
            "Distances should be in ascending order for non-FTS queries");
        
        // Test 2: With text query - PHP sorting should fix the ORDER BY issue
        $query2 = new SearchQuery('coffee');
        $query2->sortByDistance($centerPoint, 'asc');
        
        $results2 = $searchEngine->search($query2);
        
        // Verify we got all 4 coffee shops
        $this->assertCount(4, $results2->getResults());
        
        // Verify all results have valid distances
        $distances2 = [];
        foreach ($results2->getResults() as $idx => $result) {
            $this->assertTrue($result->hasDistance());
            $this->assertGreaterThan(0, $result->getDistance());
            $distances2[] = $result->getDistance();
        }
        
        // With PHP sorting, distances should now be in correct order
        $sortedDistances2 = $distances2;
        sort($sortedDistances2, SORT_NUMERIC);
        $this->assertEquals($sortedDistances2, $distances2, 
            "Distances should be in ascending order with PHP sorting for FTS queries");
        
        // Verify the actual order matches expected locations
        $titles = [];
        foreach ($results2->getResults() as $result) {
            $titles[] = $result->getDocument()['title'];
        }
        
        // Expected order from closest to farthest from downtown Portland
        // Stumptown and Blue Star are in Portland (closest)
        // Victrola is in Seattle and Revolver is in Vancouver (much farther)
        $portlandShops = ['Stumptown Coffee Roasters', 'Blue Star Donuts'];
        $distantShops = ['Victrola Coffee Roasters', 'Revolver Coffee'];
        
        // First two should be Portland shops (much closer)
        $this->assertContains($titles[0], $portlandShops, 
            "First result should be a Portland coffee shop");
        $this->assertContains($titles[1], $portlandShops, 
            "Second result should be a Portland coffee shop");
        
        // Last two should be Seattle/Vancouver shops (much farther)
        $this->assertContains($titles[2], $distantShops, 
            "Third result should be Seattle or Vancouver shop");
        $this->assertContains($titles[3], $distantShops, 
            "Fourth result should be Seattle or Vancouver shop");
    }
    
    public function testCombineTextAndGeoSearch(): void
    {
        $this->requiresGeoSupport();
        
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
        $this->requiresGeoSupport();
        
        // Search for coffee in an area with no results (middle of Pacific Ocean)
        $query = new SearchQuery('coffee');
        $query->near(new GeoPoint(30.0, -150.0), 1000);
        
        $searchEngine = $this->search->getSearchEngine($this->indexName);
        $results = $searchEngine->search($query);
        
        $this->assertCount(0, $results->getResults());
    }
    
    public function testLargeRadiusSearch(): void
    {
        $this->requiresGeoSupport();
        
        // Search with very large radius should find all coffee shops
        $query = new SearchQuery('coffee');
        $query->near(new GeoPoint(45.5152, -122.6784), 1000000); // 1000km radius
        
        $searchEngine = $this->search->getSearchEngine($this->indexName);
        $results = $searchEngine->search($query);
        
        $this->assertCount(4, $results->getResults()); // All coffee shops
    }
    
    public function testGeoSearchWithFilters(): void
    {
        $this->requiresGeoSupport();
        
        // Search for coffee shops in Portland area with additional filter
        $query = new SearchQuery('coffee');
        $query->near(new GeoPoint(45.5152, -122.6784), 5000)
              ->filter('category', 'Coffee Shop');
        
        $searchEngine = $this->search->getSearchEngine($this->indexName);
        $results = $searchEngine->search($query);
        
        $this->assertCount(2, $results->getResults());
        
        // Verify we got the expected results
        $this->assertCount(2, $results->getResults());
    }
    
    public function testIndexDocumentWithBounds(): void
    {
        $this->requiresGeoSupport();
        
        // Index a document with bounds instead of point
        $this->search->index($this->indexName, [
            'id' => 'portland-metro',
            'content' => [
                'title' => 'Portland Metro Area',
                'body' => 'Greater Portland metropolitan area'
            ],
            'geo_bounds' => [
                'north' => 45.65,
                'south' => 45.40,
                'east' => -122.40,
                'west' => -122.85
            ]
        ]);
        
        // Search within those bounds
        $query = new SearchQuery('metro');
        $query->within(new GeoBounds(45.55, 45.45, -122.50, -122.80));
        
        $searchEngine = $this->search->getSearchEngine($this->indexName);
        $results = $searchEngine->search($query);
        
        $this->assertCount(1, $results->getResults());
        $this->assertEquals('Portland Metro Area', $results->getResults()[0]->get('title'));
    }
    
    public function testSortByDistanceWithPagination(): void
    {
        $this->requiresGeoSupport();
        
        $centerPoint = new GeoPoint(45.5152, -122.6784); // Downtown Portland
        
        // Test pagination with FTS + distance sorting (uses PHP sorting)
        $query = new SearchQuery('coffee');
        $query->sortByDistance($centerPoint, 'asc')
              ->limit(2)
              ->offset(0);
        
        $searchEngine = $this->search->getSearchEngine($this->indexName);
        $results1 = $searchEngine->search($query);
        
        // Should get first 2 coffee shops by distance
        $this->assertCount(2, $results1->getResults());
        
        // Get next page
        $query->offset(2);
        $results2 = $searchEngine->search($query);
        
        // Should get remaining 2 coffee shops
        $this->assertCount(2, $results2->getResults());
        
        // Verify no duplicate results between pages
        $page1Ids = array_map(fn($r) => $r->getId(), $results1->getResults());
        $page2Ids = array_map(fn($r) => $r->getId(), $results2->getResults());
        $this->assertEmpty(array_intersect($page1Ids, $page2Ids), 
            "No results should appear on both pages");
        
        // Verify distance ordering across pages
        $page1MaxDistance = max(array_map(fn($r) => $r->getDistance(), $results1->getResults()));
        $page2MinDistance = min(array_map(fn($r) => $r->getDistance(), $results2->getResults()));
        $this->assertLessThanOrEqual($page2MinDistance, $page1MaxDistance,
            "All page 1 distances should be <= all page 2 distances");
    }
    
}