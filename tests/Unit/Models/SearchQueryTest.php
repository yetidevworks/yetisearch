<?php

namespace YetiSearch\Tests\Unit\Models;

use YetiSearch\Tests\TestCase;
use YetiSearch\Models\SearchQuery;

class SearchQueryTest extends TestCase
{
    public function testCreateBasicQuery(): void
    {
        $query = new SearchQuery('test search');
        
        $this->assertEquals('test search', $query->getQuery());
        $this->assertEquals(20, $query->getLimit());
        $this->assertEquals(0, $query->getOffset());
        $this->assertNull($query->getLanguage());
        $this->assertFalse($query->isFuzzy());
        $this->assertEquals([], $query->getFilters());
        $this->assertEquals([], $query->getFields());
        $this->assertEquals([], $query->getSort());
    }
    
    public function testCreateQueryWithOptions(): void
    {
        $query = new SearchQuery('advanced search', [
            'limit' => 50,
            'offset' => 10,
            'language' => 'fr',
            'fuzzy' => true,
            'fields' => ['title', 'body'],
            'highlight' => true
        ]);
        
        $this->assertEquals('advanced search', $query->getQuery());
        $this->assertEquals(50, $query->getLimit());
        $this->assertEquals(10, $query->getOffset());
        $this->assertEquals('fr', $query->getLanguage());
        $this->assertTrue($query->isFuzzy());
        $this->assertEquals(['title', 'body'], $query->getFields());
        $this->assertTrue($query->shouldHighlight());
    }
    
    public function testSettersAndGetters(): void
    {
        $query = new SearchQuery('initial');
        
        $query->setQuery('updated query');
        $query->setLimit(100);
        $query->setOffset(25);
        $query->setLanguage('de');
        $query->setFuzzy(true);
        $query->setFields(['description']);
        $query->setHighlight(true);
        
        $this->assertEquals('updated query', $query->getQuery());
        $this->assertEquals(100, $query->getLimit());
        $this->assertEquals(25, $query->getOffset());
        $this->assertEquals('de', $query->getLanguage());
        $this->assertTrue($query->isFuzzy());
        $this->assertEquals(['description'], $query->getFields());
        $this->assertTrue($query->shouldHighlight());
    }
    
    public function testAddFilter(): void
    {
        $query = new SearchQuery('test');
        
        // Add simple filter
        $query->addFilter('type', 'article');
        $filters = $query->getFilters();
        
        $this->assertCount(1, $filters);
        $this->assertEquals('type', $filters[0]['field']);
        $this->assertEquals('=', $filters[0]['operator']);
        $this->assertEquals('article', $filters[0]['value']);
        
        // Add filter with operator
        $query->addFilter('price', 100, '>');
        $filters = $query->getFilters();
        
        $this->assertCount(2, $filters);
        $this->assertEquals('price', $filters[1]['field']);
        $this->assertEquals('>', $filters[1]['operator']);
        $this->assertEquals(100, $filters[1]['value']);
    }
    
    public function testAddMetadataFilter(): void
    {
        $query = new SearchQuery('test');
        
        // Test simple metadata filter
        $query->addFilter('metadata.author', 'John Doe');
        $filters = $query->getFilters();
        
        $this->assertCount(1, $filters);
        $this->assertEquals('metadata.author', $filters[0]['field']);
        $this->assertEquals('John Doe', $filters[0]['value']);
        
        // Test metadata filter with operator
        $query->addFilter('metadata.rating', 4.0, '>=');
        $filters = $query->getFilters();
        
        $this->assertCount(2, $filters);
        $this->assertEquals('metadata.rating', $filters[1]['field']);
        $this->assertEquals('>=', $filters[1]['operator']);
        $this->assertEquals(4.0, $filters[1]['value']);
    }
    
    public function testFilterOperators(): void
    {
        $query = new SearchQuery('test');
        
        // Test all supported operators
        $operators = ['=', '!=', '>', '<', '>=', '<=', 'in', 'contains', 'exists'];
        
        foreach ($operators as $operator) {
            $query->addFilter("field_{$operator}", 'value', $operator);
        }
        
        $filters = $query->getFilters();
        $this->assertCount(count($operators), $filters);
        
        foreach ($filters as $index => $filter) {
            $this->assertEquals($operators[$index], $filter['operator']);
        }
    }
    
    public function testAddSort(): void
    {
        $query = new SearchQuery('test');
        
        // Add single sort
        $query->addSort('timestamp', 'desc');
        $sort = $query->getSort();
        
        $this->assertEquals(['timestamp' => 'desc'], $sort);
        
        // Add multiple sorts
        $query->addSort('score', 'desc');
        $query->addSort('title', 'asc');
        $sort = $query->getSort();
        
        $this->assertEquals([
            'timestamp' => 'desc',
            'score' => 'desc',
            'title' => 'asc'
        ], $sort);
    }
    
    public function testBoost(): void
    {
        $query = new SearchQuery('test');
        
        // Add field boosts
        $query->boost('title', 2.0);
        $query->boost('tags', 1.5);
        
        $boosts = $query->getBoosts();
        
        $this->assertEquals(2.0, $boosts['title']);
        $this->assertEquals(1.5, $boosts['tags']);
    }
    
    public function testFacet(): void
    {
        $query = new SearchQuery('test');
        
        // Add simple facet
        $query->facet('category');
        $facets = $query->getFacets();
        
        $this->assertArrayHasKey('category', $facets);
        $this->assertEquals(10, $facets['category']['limit']);
        
        // Add facet with options
        $query->facet('brand', ['limit' => 20, 'min_count' => 5]);
        $facets = $query->getFacets();
        
        $this->assertArrayHasKey('brand', $facets);
        $this->assertEquals(20, $facets['brand']['limit']);
        $this->assertEquals(5, $facets['brand']['min_count']);
    }
    
    public function testAggregation(): void
    {
        $query = new SearchQuery('test');
        
        // Add aggregations
        $query->aggregate('price', 'min');
        $query->aggregate('price', 'max');
        $query->aggregate('rating', 'avg');
        
        $aggregations = $query->getAggregations();
        
        $this->assertContains(['field' => 'price', 'type' => 'min'], $aggregations);
        $this->assertContains(['field' => 'price', 'type' => 'max'], $aggregations);
        $this->assertContains(['field' => 'rating', 'type' => 'avg'], $aggregations);
    }
    
    public function testToArray(): void
    {
        $query = new SearchQuery('complex query', [
            'limit' => 30,
            'offset' => 5,
            'language' => 'en',
            'fuzzy' => true,
            'fields' => ['title', 'content'],
            'highlight' => true
        ]);
        
        $query->addFilter('type', 'article');
        $query->addFilter('metadata.published', true);
        $query->addSort('timestamp', 'desc');
        $query->boost('title', 2.0);
        $query->facet('category', ['limit' => 15]);
        $query->aggregate('views', 'sum');
        
        $array = $query->toArray();
        
        $this->assertEquals('complex query', $array['query']);
        $this->assertEquals(30, $array['limit']);
        $this->assertEquals(5, $array['offset']);
        $this->assertEquals('en', $array['language']);
        $this->assertTrue($array['fuzzy']);
        $this->assertEquals(['title', 'content'], $array['fields']);
        $this->assertTrue($array['highlight']);
        $this->assertCount(2, $array['filters']);
        $this->assertEquals(['timestamp' => 'desc'], $array['sort']);
        $this->assertEquals(['title' => 2.0], $array['boosts']);
        $this->assertArrayHasKey('category', $array['facets']);
        $this->assertCount(1, $array['aggregations']);
    }
    
    public function testPagination(): void
    {
        $query = new SearchQuery('test');
        
        // Test page calculation
        $query->setPage(1, 20); // First page, 20 items per page
        $this->assertEquals(20, $query->getLimit());
        $this->assertEquals(0, $query->getOffset());
        
        $query->setPage(3, 20); // Third page
        $this->assertEquals(20, $query->getLimit());
        $this->assertEquals(40, $query->getOffset());
        
        $query->setPage(5, 50); // Fifth page, 50 items per page
        $this->assertEquals(50, $query->getLimit());
        $this->assertEquals(200, $query->getOffset());
    }
    
    public function testChainableMethods(): void
    {
        $query = (new SearchQuery('chainable'))
            ->setLimit(50)
            ->setLanguage('fr')
            ->setFuzzy(true)
            ->addFilter('type', 'product')
            ->addFilter('metadata.in_stock', true)
            ->addSort('price', 'asc')
            ->boost('title', 3.0)
            ->facet('brand')
            ->aggregate('price', 'avg');
        
        $this->assertEquals(50, $query->getLimit());
        $this->assertEquals('fr', $query->getLanguage());
        $this->assertTrue($query->isFuzzy());
        $this->assertCount(2, $query->getFilters());
        $this->assertEquals(['price' => 'asc'], $query->getSort());
        $this->assertEquals(['title' => 3.0], $query->getBoosts());
        $this->assertArrayHasKey('brand', $query->getFacets());
        $this->assertCount(1, $query->getAggregations());
    }
    
    public function testEmptyQuery(): void
    {
        $query = new SearchQuery('');
        
        $this->assertEquals('', $query->getQuery());
        $this->assertEquals(20, $query->getLimit());
        
        // Empty query should still support filters
        $query->addFilter('type', 'article');
        $this->assertCount(1, $query->getFilters());
    }
    
    public function testQueryNormalization(): void
    {
        // Test query trimming
        $query = new SearchQuery('  test query  ');
        $this->assertEquals('test query', $query->getQuery());
        
        // Test multi-space normalization
        $query->setQuery('test    multiple   spaces');
        $this->assertEquals('test multiple spaces', $query->getQuery());
    }
}