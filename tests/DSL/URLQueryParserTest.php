<?php

namespace YetiSearch\Tests\DSL;

use PHPUnit\Framework\TestCase;
use YetiSearch\DSL\URLQueryParser;

class URLQueryParserTest extends TestCase
{
    private URLQueryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new URLQueryParser();
    }

    // ──────────────────────────────────────
    // Basic Query Parsing
    // ──────────────────────────────────────

    public function testParsesEmptyParams(): void
    {
        $result = $this->parser->parse([]);
        $this->assertEquals('', $result->getQuery());
        $this->assertEmpty($result->getFilters());
    }

    public function testParsesQueryFromQParam(): void
    {
        $result = $this->parser->parse(['q' => 'golang tutorial']);
        $this->assertEquals('golang tutorial', $result->getQuery());
    }

    public function testParsesQueryFromQueryParam(): void
    {
        $result = $this->parser->parse(['query' => 'search terms']);
        $this->assertEquals('search terms', $result->getQuery());
    }

    public function testQParamTakesPrecedenceOverQuery(): void
    {
        $result = $this->parser->parse(['q' => 'primary', 'query' => 'secondary']);
        $this->assertEquals('primary', $result->getQuery());
    }

    // ──────────────────────────────────────
    // parseFromQueryString
    // ──────────────────────────────────────

    public function testParsesFromRawQueryString(): void
    {
        $result = $this->parser->parseFromQueryString('q=hello+world&limit=5');
        $this->assertEquals('hello world', $result->getQuery());
        $this->assertEquals(5, $result->getLimit());
    }

    public function testParsesFromQueryStringWithFilters(): void
    {
        $result = $this->parser->parseFromQueryString('q=test&filter[status][eq]=published');
        $this->assertEquals('test', $result->getQuery());
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('status', $filters[0]['field']);
        $this->assertEquals('=', $filters[0]['operator']);
    }

    // ──────────────────────────────────────
    // Filter Operators
    // ──────────────────────────────────────

    public function testParsesEqOperator(): void
    {
        $result = $this->parser->parse([
            'filter' => ['author' => ['eq' => 'John']]
        ]);
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('=', $filters[0]['operator']);
        $this->assertEquals('John', $filters[0]['value']);
    }

    public function testParsesEqorOperator(): void
    {
        $result = $this->parser->parse([
            'filter' => ['category' => ['eqor' => 'tech']]
        ]);
        $filters = $result->getFilters();
        $this->assertEquals('=?', $filters[0]['operator']);
    }

    public function testParsesNeqOperator(): void
    {
        $result = $this->parser->parse([
            'filter' => ['status' => ['neq' => 'deleted']]
        ]);
        $filters = $result->getFilters();
        $this->assertEquals('!=', $filters[0]['operator']);
    }

    public function testParsesNeOperator(): void
    {
        $result = $this->parser->parse([
            'filter' => ['status' => ['ne' => 'deleted']]
        ]);
        $filters = $result->getFilters();
        $this->assertEquals('!=', $filters[0]['operator']);
    }

    public function testParsesGtOperator(): void
    {
        $result = $this->parser->parse([
            'filter' => ['price' => ['gt' => '100']]
        ]);
        $filters = $result->getFilters();
        $this->assertEquals('>', $filters[0]['operator']);
        $this->assertSame(100, $filters[0]['value']);
    }

    public function testParsesGteOperator(): void
    {
        $result = $this->parser->parse([
            'filter' => ['price' => ['gte' => '50']]
        ]);
        $filters = $result->getFilters();
        $this->assertEquals('>=', $filters[0]['operator']);
    }

    public function testParsesLtOperator(): void
    {
        $result = $this->parser->parse([
            'filter' => ['price' => ['lt' => '200']]
        ]);
        $filters = $result->getFilters();
        $this->assertEquals('<', $filters[0]['operator']);
    }

    public function testParsesLteOperator(): void
    {
        $result = $this->parser->parse([
            'filter' => ['price' => ['lte' => '150']]
        ]);
        $filters = $result->getFilters();
        $this->assertEquals('<=', $filters[0]['operator']);
    }

    public function testParsesLikeOperator(): void
    {
        $result = $this->parser->parse([
            'filter' => ['title' => ['like' => '%golang%']]
        ]);
        $filters = $result->getFilters();
        $this->assertEquals('like', $filters[0]['operator']);
        $this->assertEquals('%golang%', $filters[0]['value']);
    }

    public function testParsesInOperator(): void
    {
        $result = $this->parser->parse([
            'filter' => ['status' => ['in' => 'published,featured,archived']]
        ]);
        $filters = $result->getFilters();
        $this->assertEquals('in', $filters[0]['operator']);
        $this->assertEquals(['published', 'featured', 'archived'], $filters[0]['value']);
    }

    public function testParsesNinOperator(): void
    {
        $result = $this->parser->parse([
            'filter' => ['status' => ['nin' => 'draft,deleted']]
        ]);
        $filters = $result->getFilters();
        $this->assertEquals('not in', $filters[0]['operator']);
        $this->assertEquals(['draft', 'deleted'], $filters[0]['value']);
    }

    public function testParsesBetweenOperator(): void
    {
        $result = $this->parser->parse([
            'filter' => ['price' => ['between' => '10,100']]
        ]);
        $filters = $result->getFilters();
        $this->assertEquals('between', $filters[0]['operator']);
        $this->assertIsArray($filters[0]['value']);
    }

    public function testParsesExistsOperator(): void
    {
        $result = $this->parser->parse([
            'filter' => ['thumbnail' => ['exists' => 'true']]
        ]);
        $filters = $result->getFilters();
        $this->assertEquals('exists', $filters[0]['operator']);
    }

    public function testParsesNullOperator(): void
    {
        $result = $this->parser->parse([
            'filter' => ['deleted_at' => ['null' => 'true']]
        ]);
        $filters = $result->getFilters();
        $this->assertEquals('is null', $filters[0]['operator']);
    }

    public function testParsesNotnullOperator(): void
    {
        $result = $this->parser->parse([
            'filter' => ['email' => ['notnull' => 'true']]
        ]);
        $filters = $result->getFilters();
        $this->assertEquals('is not null', $filters[0]['operator']);
    }

    public function testParsesSimpleEqualityFilter(): void
    {
        $result = $this->parser->parse([
            'filter' => ['status' => 'published']
        ]);
        $filters = $result->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('=', $filters[0]['operator']);
        $this->assertEquals('published', $filters[0]['value']);
    }

    public function testParsesMultipleFilters(): void
    {
        $result = $this->parser->parse([
            'filter' => [
                'author' => ['eq' => 'John'],
                'status' => ['in' => 'published,featured'],
                'price' => ['gt' => '10']
            ]
        ]);
        $filters = $result->getFilters();
        $this->assertCount(3, $filters);
    }

    public function testParsesMultipleOperatorsOnSameField(): void
    {
        $result = $this->parser->parse([
            'filter' => [
                'price' => ['gte' => '10', 'lte' => '100']
            ]
        ]);
        $filters = $result->getFilters();
        $this->assertCount(2, $filters);
        $this->assertEquals('price', $filters[0]['field']);
        $this->assertEquals('price', $filters[1]['field']);
    }

    // ──────────────────────────────────────
    // Value Parsing & Coercion
    // ──────────────────────────────────────

    public function testCoercesTrueStringToBoolean(): void
    {
        $result = $this->parser->parse([
            'filter' => ['active' => ['eq' => 'true']]
        ]);
        $filters = $result->getFilters();
        $this->assertTrue($filters[0]['value']);
    }

    public function testCoercesFalseStringToBoolean(): void
    {
        $result = $this->parser->parse([
            'filter' => ['active' => ['eq' => 'false']]
        ]);
        $filters = $result->getFilters();
        $this->assertFalse($filters[0]['value']);
    }

    public function testCoercesNullStringToNull(): void
    {
        $result = $this->parser->parse([
            'filter' => ['deleted_at' => ['eq' => 'null']]
        ]);
        $filters = $result->getFilters();
        $this->assertNull($filters[0]['value']);
    }

    public function testCoercesIntegerStringToInt(): void
    {
        $result = $this->parser->parse([
            'filter' => ['count' => ['eq' => '42']]
        ]);
        $filters = $result->getFilters();
        $this->assertSame(42, $filters[0]['value']);
    }

    public function testCoercesFloatStringToFloat(): void
    {
        $result = $this->parser->parse([
            'filter' => ['price' => ['eq' => '19.99']]
        ]);
        $filters = $result->getFilters();
        $this->assertSame(19.99, $filters[0]['value']);
    }

    public function testCommaSeparatedValuesBecomesArray(): void
    {
        $result = $this->parser->parse([
            'filter' => ['tags' => ['eq' => 'php,golang,rust']]
        ]);
        $filters = $result->getFilters();
        $this->assertIsArray($filters[0]['value']);
        $this->assertEquals(['php', 'golang', 'rust'], $filters[0]['value']);
    }

    public function testNonNumericStringStaysString(): void
    {
        $result = $this->parser->parse([
            'filter' => ['name' => ['eq' => 'John']]
        ]);
        $filters = $result->getFilters();
        $this->assertIsString($filters[0]['value']);
        $this->assertEquals('John', $filters[0]['value']);
    }

    // ──────────────────────────────────────
    // Sorting
    // ──────────────────────────────────────

    public function testParsesSortAscending(): void
    {
        $result = $this->parser->parse(['sort' => 'title']);
        $sort = $result->getSort();
        $this->assertArrayHasKey('title', $sort);
        $this->assertEquals('asc', $sort['title']);
    }

    public function testParsesSortDescendingWithMinusPrefix(): void
    {
        $result = $this->parser->parse(['sort' => '-created_at']);
        $sort = $result->getSort();
        $this->assertArrayHasKey('created_at', $sort);
        $this->assertEquals('desc', $sort['created_at']);
    }

    public function testParsesMultipleSortFields(): void
    {
        $result = $this->parser->parse(['sort' => '-created_at,title']);
        $sort = $result->getSort();
        $this->assertCount(2, $sort);
        $this->assertEquals('desc', $sort['created_at']);
        $this->assertEquals('asc', $sort['title']);
    }

    public function testParsesSortWithColonDirection(): void
    {
        $result = $this->parser->parse(['sort' => 'title:desc']);
        $sort = $result->getSort();
        $this->assertArrayHasKey('title', $sort);
        $this->assertEquals('desc', $sort['title']);
    }

    public function testIgnoresEmptySortFields(): void
    {
        $result = $this->parser->parse(['sort' => 'title,,author']);
        $sort = $result->getSort();
        $this->assertCount(2, $sort);
    }

    // ──────────────────────────────────────
    // Fields Selection
    // ──────────────────────────────────────

    public function testParsesFieldsFromString(): void
    {
        $result = $this->parser->parse(['fields' => 'title,author,body']);
        $fields = $result->getFields();
        $this->assertCount(3, $fields);
        $this->assertContains('title', $fields);
        $this->assertContains('author', $fields);
        $this->assertContains('body', $fields);
    }

    public function testParsesFieldsFromArray(): void
    {
        $result = $this->parser->parse(['fields' => ['title', 'author']]);
        $fields = $result->getFields();
        $this->assertCount(2, $fields);
    }

    public function testParsesFieldsFromAssociativeArray(): void
    {
        $result = $this->parser->parse([
            'fields' => ['title' => 'heading', 'author' => 'writer']
        ]);
        $fields = $result->getFields();
        // Associative arrays use keys as field names
        $this->assertContains('title', $fields);
        $this->assertContains('author', $fields);
    }

    // ──────────────────────────────────────
    // Pagination
    // ──────────────────────────────────────

    public function testParsesPageLimitAndOffset(): void
    {
        $result = $this->parser->parse([
            'page' => ['limit' => 10, 'offset' => 20]
        ]);
        $this->assertEquals(10, $result->getLimit());
        $this->assertEquals(20, $result->getOffset());
    }

    public function testParsesPageNumberAndSize(): void
    {
        $result = $this->parser->parse([
            'page' => ['number' => 3, 'size' => 15]
        ]);
        $this->assertEquals(15, $result->getLimit());
        $this->assertEquals(30, $result->getOffset()); // (3-1) * 15
    }

    public function testParsesPageNumberOneHasZeroOffset(): void
    {
        $result = $this->parser->parse([
            'page' => ['number' => 1, 'size' => 10]
        ]);
        $this->assertEquals(10, $result->getLimit());
        $this->assertEquals(0, $result->getOffset());
    }

    public function testParsesSimplePageNumber(): void
    {
        $result = $this->parser->parse(['page' => '2']);
        $this->assertEquals(20, $result->getLimit()); // Default page size
        $this->assertEquals(20, $result->getOffset()); // (2-1) * 20
    }

    public function testParsesDirectLimitParam(): void
    {
        $result = $this->parser->parse(['limit' => 50]);
        $this->assertEquals(50, $result->getLimit());
    }

    public function testParsesDirectOffsetParam(): void
    {
        $result = $this->parser->parse(['offset' => 100]);
        $this->assertEquals(100, $result->getOffset());
    }

    public function testDirectLimitOverridesPageLimit(): void
    {
        // Direct params are processed after page params
        $result = $this->parser->parse([
            'page' => ['limit' => 10],
            'limit' => 25
        ]);
        $this->assertEquals(25, $result->getLimit());
    }

    // ──────────────────────────────────────
    // Fuzzy Search
    // ──────────────────────────────────────

    public function testParsesFuzzyTrueString(): void
    {
        $result = $this->parser->parse(['fuzzy' => 'true']);
        $this->assertTrue($result->isFuzzy());
    }

    public function testParsesFuzzyOneString(): void
    {
        $result = $this->parser->parse(['fuzzy' => '1']);
        $this->assertTrue($result->isFuzzy());
    }

    public function testParsesFuzzyFalseString(): void
    {
        $result = $this->parser->parse(['fuzzy' => 'false']);
        $this->assertFalse($result->isFuzzy());
    }

    public function testParsesFuzzyBooleanTrue(): void
    {
        $result = $this->parser->parse(['fuzzy' => true]);
        $this->assertTrue($result->isFuzzy());
    }

    public function testParsesFuzzyBooleanFalse(): void
    {
        $result = $this->parser->parse(['fuzzy' => false]);
        $this->assertFalse($result->isFuzzy());
    }

    // ──────────────────────────────────────
    // Highlighting
    // ──────────────────────────────────────

    public function testParsesHighlightTrue(): void
    {
        $result = $this->parser->parse(['highlight' => 'true']);
        $this->assertTrue($result->shouldHighlight());
    }

    public function testParsesHighlightFalse(): void
    {
        $result = $this->parser->parse(['highlight' => 'false']);
        $this->assertFalse($result->shouldHighlight());
    }

    public function testParsesHighlightBoolean(): void
    {
        $result = $this->parser->parse(['highlight' => true]);
        $this->assertTrue($result->shouldHighlight());
    }

    // ──────────────────────────────────────
    // Facets
    // ──────────────────────────────────────

    public function testParsesFacetsWithOptions(): void
    {
        $result = $this->parser->parse([
            'facets' => [
                'category' => ['limit' => 10],
                'tags' => ['limit' => 5]
            ]
        ]);
        $facets = $result->getFacets();
        $this->assertArrayHasKey('category', $facets);
        $this->assertArrayHasKey('tags', $facets);
        $this->assertEquals(['limit' => 10], $facets['category']);
    }

    public function testParsesFacetsWithSimpleValues(): void
    {
        $result = $this->parser->parse([
            'facets' => [
                'category' => 'true'
            ]
        ]);
        $facets = $result->getFacets();
        $this->assertArrayHasKey('category', $facets);
        $this->assertEquals([], $facets['category']); // Non-array → empty options
    }

    // ──────────────────────────────────────
    // Geo Filters
    // ──────────────────────────────────────

    public function testParsesGeoNear(): void
    {
        $result = $this->parser->parse([
            'geo' => [
                'near' => [
                    'lat' => 37.7749,
                    'lng' => -122.4194,
                    'radius' => 1000
                ]
            ]
        ]);
        $geoFilters = $result->getGeoFilters();
        $this->assertArrayHasKey('near', $geoFilters);
        $this->assertEquals(1000, $geoFilters['near']['radius']);
    }

    public function testParsesGeoNearWithUnits(): void
    {
        $result = $this->parser->parse([
            'geo' => [
                'near' => [
                    'lat' => 37.7749,
                    'lng' => -122.4194,
                    'radius' => 5,
                    'units' => 'km'
                ]
            ]
        ]);
        $geoFilters = $result->getGeoFilters();
        $this->assertArrayHasKey('near', $geoFilters);
        $this->assertEquals('km', $geoFilters['units']);
    }

    public function testParsesGeoWithin(): void
    {
        $result = $this->parser->parse([
            'geo' => [
                'within' => [
                    'north' => 38.0,
                    'south' => 37.0,
                    'east' => -122.0,
                    'west' => -123.0
                ]
            ]
        ]);
        $geoFilters = $result->getGeoFilters();
        $this->assertArrayHasKey('within', $geoFilters);
    }

    public function testParsesGeoDistanceSort(): void
    {
        $result = $this->parser->parse([
            'geo' => [
                'sort' => [
                    'lat' => 37.7749,
                    'lng' => -122.4194,
                    'direction' => 'asc'
                ]
            ]
        ]);
        $geoFilters = $result->getGeoFilters();
        $this->assertArrayHasKey('distance_sort', $geoFilters);
    }

    public function testParsesGeoUnits(): void
    {
        $result = $this->parser->parse([
            'geo' => ['units' => 'mi']
        ]);
        $geoFilters = $result->getGeoFilters();
        $this->assertEquals('mi', $geoFilters['units']);
    }

    public function testParsesCompleteGeoQuery(): void
    {
        $result = $this->parser->parse([
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
        ]);
        $this->assertEquals('restaurants', $result->getQuery());
        $geoFilters = $result->getGeoFilters();
        $this->assertArrayHasKey('near', $geoFilters);
        $this->assertArrayHasKey('distance_sort', $geoFilters);
        $this->assertTrue($result->hasGeoFilters());
    }

    // ──────────────────────────────────────
    // Language
    // ──────────────────────────────────────

    public function testParsesLanguage(): void
    {
        $result = $this->parser->parse(['language' => 'english']);
        $this->assertEquals('english', $result->getLanguage());
    }

    // ──────────────────────────────────────
    // Field Boosts
    // ──────────────────────────────────────

    public function testParsesSingleBoost(): void
    {
        $result = $this->parser->parse([
            'boost' => ['title' => 2.0]
        ]);
        $boost = $result->getBoost();
        $this->assertEquals(2.0, $boost['title']);
    }

    public function testParsesMultipleBoosts(): void
    {
        $result = $this->parser->parse([
            'boost' => ['title' => 3.0, 'body' => 1.0, 'tags' => 2.0]
        ]);
        $boost = $result->getBoost();
        $this->assertCount(3, $boost);
        $this->assertEquals(3.0, $boost['title']);
        $this->assertEquals(1.0, $boost['body']);
    }

    public function testBoostValuesAreCastToFloat(): void
    {
        $result = $this->parser->parse([
            'boost' => ['title' => '2']
        ]);
        $boost = $result->getBoost();
        $this->assertIsFloat($boost['title']);
    }

    // ──────────────────────────────────────
    // Field Aliases
    // ──────────────────────────────────────

    public function testResolvesFieldAliasesInFilters(): void
    {
        $parser = new URLQueryParser(['writer' => 'author']);
        $result = $parser->parse([
            'filter' => ['writer' => ['eq' => 'John']]
        ]);
        $filters = $result->getFilters();
        $this->assertEquals('author', $filters[0]['field']);
    }

    public function testUnaliasedFieldPassesThrough(): void
    {
        $parser = new URLQueryParser(['writer' => 'author']);
        $result = $parser->parse([
            'filter' => ['status' => ['eq' => 'published']]
        ]);
        $filters = $result->getFilters();
        $this->assertEquals('status', $filters[0]['field']);
    }

    // ──────────────────────────────────────
    // Complete Combined Queries
    // ──────────────────────────────────────

    public function testParsesFullFeaturedQuery(): void
    {
        $result = $this->parser->parse([
            'q' => 'golang tutorial',
            'filter' => [
                'author' => ['eq' => 'John'],
                'status' => ['in' => 'published,featured'],
                'rating' => ['gte' => '4']
            ],
            'sort' => '-created_at,title',
            'fields' => 'title,author,body',
            'page' => ['limit' => 10, 'offset' => 20],
            'fuzzy' => 'true',
            'highlight' => 'true',
            'language' => 'english',
            'boost' => ['title' => 2.0]
        ]);

        $this->assertEquals('golang tutorial', $result->getQuery());
        $this->assertCount(3, $result->getFilters());
        $this->assertCount(2, $result->getSort());
        $this->assertCount(3, $result->getFields());
        $this->assertEquals(10, $result->getLimit());
        $this->assertEquals(20, $result->getOffset());
        $this->assertTrue($result->isFuzzy());
        $this->assertTrue($result->shouldHighlight());
        $this->assertEquals('english', $result->getLanguage());
        $this->assertEquals(2.0, $result->getBoost()['title']);
    }
}
