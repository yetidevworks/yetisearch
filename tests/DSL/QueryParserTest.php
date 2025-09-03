<?php

namespace YetiSearch\Tests\DSL;

use PHPUnit\Framework\TestCase;
use YetiSearch\DSL\QueryParser;
use YetiSearch\DSL\URLQueryParser;
use YetiSearch\DSL\QueryBuilder;
use YetiSearch\YetiSearch;

class QueryParserTest extends TestCase
{
    private QueryParser $parser;
    private URLQueryParser $urlParser;
    
    protected function setUp(): void
    {
        $this->parser = new QueryParser();
        $this->urlParser = new URLQueryParser();
    }
    
    public function testParsesSimpleQuery(): void
    {
        $query = 'hello world';
        $result = $this->parser->parse($query);
        
        $this->assertEquals('hello world', $result->getQuery());
        $this->assertEmpty($result->getFilters());
    }
    
    public function testParsesQueryWithEqualityFilter(): void
    {
        $query = 'author = "John Doe"';
        $result = $this->parser->parse($query);
        
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('author', $filters[0]['field']);
        $this->assertEquals('John Doe', $filters[0]['value']);
        $this->assertEquals('=', $filters[0]['operator']);
    }
    
    public function testParsesQueryWithMultipleFilters(): void
    {
        $query = 'status = "published" AND author = "John"';
        $result = $this->parser->parse($query);
        
        $filters = $result->getFilters();
        $this->assertCount(2, $filters);
    }
    
    public function testParsesQueryWithInOperator(): void
    {
        $query = 'status IN [draft, published, archived]';
        $result = $this->parser->parse($query);
        
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('status', $filters[0]['field']);
        $this->assertEquals(['draft', 'published', 'archived'], $filters[0]['value']);
        $this->assertEquals('in', $filters[0]['operator']);
    }
    
    public function testParsesQueryWithNegation(): void
    {
        $query = '-status IN [draft, deleted]';
        $result = $this->parser->parse($query);
        
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('not in', $filters[0]['operator']);
    }
    
    public function testParsesComplexQuery(): void
    {
        $query = 'golang tutorial author = "John" AND -status IN [draft, deleted] FIELDS title, author SORT -created_at LIMIT 10';
        $result = $this->parser->parse($query);
        
        $this->assertEquals('golang tutorial', $result->getQuery());
        
        $filters = $result->getFilters();
        $this->assertCount(2, $filters);
        
        $fields = $result->getFields();
        $this->assertContains('title', $fields);
        $this->assertContains('author', $fields);
        
        $sort = $result->getSort();
        $this->assertEquals('desc', $sort['created_at']);
        
        $this->assertEquals(10, $result->getLimit());
    }
    
    public function testParsesQueryWithPagination(): void
    {
        $query = 'search term PAGE 2, 25';
        $result = $this->parser->parse($query);
        
        $this->assertEquals(25, $result->getLimit());
        $this->assertEquals(25, $result->getOffset()); // Page 2, offset = (2-1) * 25
    }
    
    public function testParsesGroupedConditions(): void
    {
        $query = '(status = "published" OR status = "featured") AND author = "John"';
        $result = $this->parser->parse($query);
        
        $filters = $result->getFilters();
        $this->assertGreaterThanOrEqual(2, count($filters));
    }
    
    public function testParsesLikeOperator(): void
    {
        $query = 'title LIKE "%golang%"';
        $result = $this->parser->parse($query);
        
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('title', $filters[0]['field']);
        $this->assertEquals('%golang%', $filters[0]['value']);
        $this->assertEquals('like', $filters[0]['operator']);
    }
    
    public function testParsesFieldAliases(): void
    {
        $parser = new QueryParser(['writer' => 'author']);
        $query = 'writer = "John"';
        $result = $parser->parse($query);
        
        $filters = $result->getFilters();
        $this->assertEquals('author', $filters[0]['field']);
    }
    
    public function testParsesURLQueryString(): void
    {
        $queryString = 'q=golang&filter[author][eq]=John&filter[status][in]=published,featured&sort=-created_at&page[limit]=10&page[offset]=20';
        $result = $this->urlParser->parseFromQueryString($queryString);
        
        $this->assertEquals('golang', $result->getQuery());
        
        $filters = $result->getFilters();
        $this->assertCount(2, $filters);
        
        $this->assertEquals(10, $result->getLimit());
        $this->assertEquals(20, $result->getOffset());
    }
    
    public function testParsesURLQueryArray(): void
    {
        $params = [
            'q' => 'search term',
            'filter' => [
                'author' => ['eq' => 'John'],
                'status' => ['in' => 'published,featured']
            ],
            'sort' => '-created_at,title',
            'page' => [
                'number' => 2,
                'size' => 25
            ],
            'fields' => 'title,author,created_at',
            'fuzzy' => 'true'
        ];
        
        $result = $this->urlParser->parse($params);
        
        $this->assertEquals('search term', $result->getQuery());
        $this->assertTrue($result->isFuzzy());
        $this->assertEquals(25, $result->getLimit());
        $this->assertEquals(25, $result->getOffset());
        
        $fields = $result->getFields();
        $this->assertCount(3, $fields);
    }
    
    public function testParsesGeoFilters(): void
    {
        $params = [
            'q' => 'restaurants',
            'geo' => [
                'near' => [
                    'lat' => 37.7749,
                    'lng' => -122.4194,
                    'radius' => 1000,
                    'units' => 'm'
                ],
                'sort' => [
                    'lat' => 37.7749,
                    'lng' => -122.4194,
                    'direction' => 'asc'
                ]
            ]
        ];
        
        $result = $this->urlParser->parse($params);
        
        $geoFilters = $result->getGeoFilters();
        $this->assertArrayHasKey('near', $geoFilters);
        $this->assertArrayHasKey('distance_sort', $geoFilters);
    }
    
    public function testFluentQueryBuilder(): void
    {
        $config = ['storage' => ['path' => ':memory:']];
        $yeti = new YetiSearch($config);
        $builder = new QueryBuilder($yeti);
        
        $query = $builder->query('golang')
            ->in('articles')
            ->where('status', 'published')
            ->whereIn('category', ['tech', 'programming'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->fuzzy(true, 0.8)
            ->boost('title', 2.0);
        
        $searchQuery = $query->toSearchQuery();
        
        $this->assertEquals('golang', $searchQuery->getQuery());
        $this->assertEquals(10, $searchQuery->getLimit());
        $this->assertTrue($searchQuery->isFuzzy());
        
        $filters = $searchQuery->getFilters();
        $this->assertCount(2, $filters);
        
        $boost = $searchQuery->getBoost();
        $this->assertEquals(2.0, $boost['title']);
    }
    
    public function testFluentQueryBuilderWithGeo(): void
    {
        $config = ['storage' => ['path' => ':memory:']];
        $yeti = new YetiSearch($config);
        $builder = new QueryBuilder($yeti);
        
        $query = $builder->query('restaurants')
            ->in('places')
            ->nearPoint(37.7749, -122.4194, 1000, 'm')
            ->sortByDistance(37.7749, -122.4194, 'asc')
            ->limit(20);
        
        $searchQuery = $query->toSearchQuery();
        
        $geoFilters = $searchQuery->getGeoFilters();
        $this->assertArrayHasKey('near', $geoFilters);
        $this->assertArrayHasKey('distance_sort', $geoFilters);
    }
    
    public function testCompleteIntegration(): void
    {
        $config = ['storage' => ['path' => ':memory:']];
        $yeti = new YetiSearch($config);
        $builder = new QueryBuilder($yeti);
        
        // Test DSL parsing
        $dslQuery = 'author = "John" AND status IN [published] SORT -created_at LIMIT 10';
        $searchQuery1 = $builder->parse($dslQuery);
        
        // Test URL parsing
        $urlQuery = 'filter[author][eq]=John&filter[status][in]=published&sort=-created_at&page[limit]=10';
        $searchQuery2 = $builder->parse($urlQuery);
        
        // Both should produce similar results
        $this->assertEquals($searchQuery1->getLimit(), $searchQuery2->getLimit());
        
        $filters1 = $searchQuery1->getFilters();
        $filters2 = $searchQuery2->getFilters();
        
        $this->assertEquals(count($filters1), count($filters2));
    }
}