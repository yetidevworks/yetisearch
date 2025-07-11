<?php

declare(strict_types=1);

namespace YetiSearch\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use YetiSearch\Models\SearchResults;
use YetiSearch\Models\SearchResult;

class SearchResultsTest extends TestCase
{
    public function testConstructorWithResults(): void
    {
        $results = [
            new SearchResult(['id' => 'doc1', 'score' => 95.0]),
            new SearchResult(['id' => 'doc2', 'score' => 85.0]),
            new SearchResult(['id' => 'doc3', 'score' => 75.0])
        ];
        
        $searchResults = new SearchResults($results, 3, 0.123);
        
        $this->assertCount(3, $searchResults);
        $this->assertEquals(3, $searchResults->getTotalCount());
        $this->assertEquals(3, $searchResults->count());
        $this->assertEquals(0.123, $searchResults->getSearchTime());
    }
    
    public function testConstructorWithFacets(): void
    {
        $results = [];
        $facets = [
            'category' => [
                ['value' => 'Tech', 'count' => 10],
                ['value' => 'Science', 'count' => 5]
            ],
            'author' => [
                ['value' => 'John', 'count' => 8],
                ['value' => 'Jane', 'count' => 3]
            ]
        ];
        
        $searchResults = new SearchResults($results, 0, 0.1, $facets);
        
        $this->assertEquals($facets, $searchResults->getFacets());
        $this->assertNotEmpty($searchResults->getFacets());
    }
    
    public function testConstructorWithAggregations(): void
    {
        $results = [];
        $aggregations = [
            'avg_score' => 85.5,
            'max_score' => 100.0,
            'min_score' => 50.0
        ];
        
        $searchResults = new SearchResults($results, 0, 0.1, [], $aggregations);
        
        $this->assertEquals($aggregations, $searchResults->getAggregations());
        $this->assertNotEmpty($searchResults->getAggregations());
    }
    
    public function testIteratorInterface(): void
    {
        $results = [
            new SearchResult(['id' => 'doc1', 'score' => 95.0]),
            new SearchResult(['id' => 'doc2', 'score' => 85.0]),
            new SearchResult(['id' => 'doc3', 'score' => 75.0])
        ];
        
        $searchResults = new SearchResults($results, 3, 0.1);
        
        $ids = [];
        foreach ($searchResults as $result) {
            $ids[] = $result->getId();
        }
        
        $this->assertEquals(['doc1', 'doc2', 'doc3'], $ids);
    }
    
    public function testCountable(): void
    {
        $results = [
            new SearchResult(['id' => 'doc1', 'score' => 95.0]),
            new SearchResult(['id' => 'doc2', 'score' => 85.0])
        ];
        
        $searchResults = new SearchResults($results, 10, 0.1); // Total is 10 but only 2 results
        
        $this->assertCount(2, $searchResults);
        $this->assertEquals(2, count($searchResults));
        $this->assertEquals(10, $searchResults->getTotalCount()); // Total can be different from count
    }
    
    public function testIsEmpty(): void
    {
        $emptyResults = new SearchResults([], 0, 0.1);
        $nonEmptyResults = new SearchResults([
            new SearchResult(['id' => 'doc1', 'score' => 95.0])
        ], 1, 0.1);
        
        $this->assertTrue($emptyResults->isEmpty());
        $this->assertFalse($nonEmptyResults->isEmpty());
    }
    
    public function testFirst(): void
    {
        $results = [
            new SearchResult(['id' => 'doc1', 'score' => 95.0]),
            new SearchResult(['id' => 'doc2', 'score' => 85.0])
        ];
        
        $searchResults = new SearchResults($results, 2, 0.1);
        $first = $searchResults->first();
        
        $this->assertInstanceOf(SearchResult::class, $first);
        $this->assertEquals('doc1', $first->getId());
        
        // Test empty results
        $emptyResults = new SearchResults([], 0, 0.1);
        $this->assertNull($emptyResults->first());
    }
    
    public function testGetResults(): void
    {
        $results = [
            new SearchResult(['id' => 'doc1', 'score' => 95.0]),
            new SearchResult(['id' => 'doc2', 'score' => 85.0])
        ];
        
        $searchResults = new SearchResults($results, 2, 0.1);
        $retrievedResults = $searchResults->getResults();
        
        $this->assertIsArray($retrievedResults);
        $this->assertCount(2, $retrievedResults);
        $this->assertSame($results, $retrievedResults);
    }
    
    public function testGetFacet(): void
    {
        $facets = [
            'category' => [
                ['value' => 'Tech', 'count' => 10],
                ['value' => 'Science', 'count' => 5]
            ],
            'author' => [
                ['value' => 'John', 'count' => 8]
            ]
        ];
        
        $searchResults = new SearchResults([], 0, 0.1, $facets);
        
        $this->assertEquals($facets['category'], $searchResults->getFacet('category'));
        $this->assertEquals($facets['author'], $searchResults->getFacet('author'));
        $this->assertNull($searchResults->getFacet('non_existent'));
    }
    
    public function testGetAggregation(): void
    {
        $aggregations = [
            'avg_score' => ['value' => 85.5],
            'max_score' => ['value' => 100.0],
            'min_score' => ['value' => 50.0]
        ];
        
        $searchResults = new SearchResults([], 0, 0.1, [], $aggregations);
        
        $this->assertEquals(['value' => 85.5], $searchResults->getAggregation('avg_score'));
        $this->assertEquals(['value' => 100.0], $searchResults->getAggregation('max_score'));
        $this->assertEquals(['value' => 50.0], $searchResults->getAggregation('min_score'));
        $this->assertNull($searchResults->getAggregation('non_existent'));
    }
    
    public function testToArray(): void
    {
        $results = [
            new SearchResult(['id' => 'doc1', 'score' => 95.0, 'title' => 'Test 1']),
            new SearchResult(['id' => 'doc2', 'score' => 85.0, 'title' => 'Test 2'])
        ];
        
        $facets = ['category' => [['value' => 'Tech', 'count' => 10]]];
        $aggregations = ['avg_score' => 90.0];
        
        $searchResults = new SearchResults($results, 2, 0.123, $facets, $aggregations);
        $array = $searchResults->toArray();
        
        $this->assertIsArray($array);
        $this->assertArrayHasKey('results', $array);
        $this->assertArrayHasKey('total', $array);
        $this->assertArrayHasKey('count', $array);
        $this->assertArrayHasKey('search_time', $array);
        $this->assertArrayHasKey('facets', $array);
        $this->assertArrayHasKey('aggregations', $array);
        
        $this->assertCount(2, $array['results']);
        $this->assertEquals(2, $array['total']);
        $this->assertEquals(2, $array['count']);
        $this->assertEquals(0.123, $array['search_time']);
        $this->assertEquals($facets, $array['facets']);
        $this->assertEquals($aggregations, $array['aggregations']);
        
        // Check results are properly converted
        $this->assertEquals('doc1', $array['results'][0]['id']);
        $this->assertEquals('doc2', $array['results'][1]['id']);
    }
    
    public function testToJson(): void
    {
        $results = [
            new SearchResult(['id' => 'doc1', 'score' => 95.0])
        ];
        
        $searchResults = new SearchResults($results, 1, 0.1);
        $json = $searchResults->toJson();
        
        $this->assertJson($json);
        
        $decoded = json_decode($json, true);
        $this->assertArrayHasKey('results', $decoded);
        $this->assertArrayHasKey('total', $decoded);
        $this->assertArrayHasKey('count', $decoded);
        $this->assertArrayHasKey('search_time', $decoded);
    }
    
}