<?php

namespace YetiSearch\Tests\DSL;

use PHPUnit\Framework\TestCase;
use YetiSearch\DSL\QueryBuilder;
use YetiSearch\DSL\FluentQuery;
use YetiSearch\YetiSearch;
use YetiSearch\Exceptions\InvalidArgumentException;
use YetiSearch\Models\SearchQuery;

class FluentQueryTest extends TestCase
{
    private QueryBuilder $builder;

    protected function setUp(): void
    {
        $config = ['storage' => ['path' => ':memory:']];
        $yeti = new YetiSearch($config);
        $this->builder = new QueryBuilder($yeti);
    }

    // ──────────────────────────────────────
    // Basic Query Construction
    // ──────────────────────────────────────

    public function testCreatesEmptyQuery(): void
    {
        $sq = $this->builder->query()->toSearchQuery();
        $this->assertEquals('', $sq->getQuery());
    }

    public function testCreatesQueryWithSearchText(): void
    {
        $sq = $this->builder->query('hello world')->toSearchQuery();
        $this->assertEquals('hello world', $sq->getQuery());
    }

    public function testSearchMethodOverridesInitialQuery(): void
    {
        $sq = $this->builder->query('initial')
            ->search('updated')
            ->toSearchQuery();
        $this->assertEquals('updated', $sq->getQuery());
    }

    // ──────────────────────────────────────
    // where() Filters
    // ──────────────────────────────────────

    public function testWhereDefaultEquality(): void
    {
        $sq = $this->builder->query()
            ->where('status', 'published')
            ->toSearchQuery();
        $filters = $sq->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('status', $filters[0]['field']);
        $this->assertEquals('published', $filters[0]['value']);
        $this->assertEquals('=', $filters[0]['operator']);
    }

    public function testWhereCustomOperator(): void
    {
        $sq = $this->builder->query()
            ->where('price', 100, '>')
            ->toSearchQuery();
        $filters = $sq->getFilters();
        $this->assertEquals('>', $filters[0]['operator']);
        $this->assertEquals(100, $filters[0]['value']);
    }

    public function testWhereNotEqual(): void
    {
        $sq = $this->builder->query()
            ->where('status', 'deleted', '!=')
            ->toSearchQuery();
        $filters = $sq->getFilters();
        $this->assertEquals('!=', $filters[0]['operator']);
    }

    public function testMultipleWheres(): void
    {
        $sq = $this->builder->query()
            ->where('status', 'published')
            ->where('author', 'John')
            ->where('rating', 3, '>=')
            ->toSearchQuery();
        $filters = $sq->getFilters();
        $this->assertCount(3, $filters);
    }

    // ──────────────────────────────────────
    // whereIn / whereNotIn
    // ──────────────────────────────────────

    public function testWhereIn(): void
    {
        $sq = $this->builder->query()
            ->whereIn('status', ['published', 'featured'])
            ->toSearchQuery();
        $filters = $sq->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('in', $filters[0]['operator']);
        $this->assertEquals(['published', 'featured'], $filters[0]['value']);
    }

    public function testWhereNotIn(): void
    {
        $sq = $this->builder->query()
            ->whereNotIn('status', ['draft', 'deleted'])
            ->toSearchQuery();
        $filters = $sq->getFilters();
        $this->assertEquals('not in', $filters[0]['operator']);
    }

    // ──────────────────────────────────────
    // whereLike
    // ──────────────────────────────────────

    public function testWhereLike(): void
    {
        $sq = $this->builder->query()
            ->whereLike('title', '%golang%')
            ->toSearchQuery();
        $filters = $sq->getFilters();
        $this->assertEquals('like', $filters[0]['operator']);
        $this->assertEquals('%golang%', $filters[0]['value']);
    }

    // ──────────────────────────────────────
    // whereNull / whereNotNull
    // ──────────────────────────────────────

    public function testWhereNull(): void
    {
        $sq = $this->builder->query()
            ->whereNull('deleted_at')
            ->toSearchQuery();
        $filters = $sq->getFilters();
        $this->assertEquals('is null', $filters[0]['operator']);
        $this->assertNull($filters[0]['value']);
    }

    public function testWhereNotNull(): void
    {
        $sq = $this->builder->query()
            ->whereNotNull('email')
            ->toSearchQuery();
        $filters = $sq->getFilters();
        $this->assertEquals('is not null', $filters[0]['operator']);
    }

    // ──────────────────────────────────────
    // whereBetween
    // ──────────────────────────────────────

    public function testWhereBetween(): void
    {
        $sq = $this->builder->query()
            ->whereBetween('price', 10, 100)
            ->toSearchQuery();
        $filters = $sq->getFilters();
        $this->assertEquals('between', $filters[0]['operator']);
        $this->assertEquals([10, 100], $filters[0]['value']);
    }

    // ──────────────────────────────────────
    // Fields Selection
    // ──────────────────────────────────────

    public function testFieldsSelection(): void
    {
        $sq = $this->builder->query()
            ->fields(['title', 'author', 'body'])
            ->toSearchQuery();
        $fields = $sq->getFields();
        $this->assertCount(3, $fields);
        $this->assertContains('title', $fields);
    }

    // ──────────────────────────────────────
    // Ordering
    // ──────────────────────────────────────

    public function testOrderByAscending(): void
    {
        $sq = $this->builder->query()
            ->orderBy('title', 'asc')
            ->toSearchQuery();
        $sort = $sq->getSort();
        $this->assertEquals('asc', $sort['title']);
    }

    public function testOrderByDescending(): void
    {
        $sq = $this->builder->query()
            ->orderBy('created_at', 'desc')
            ->toSearchQuery();
        $sort = $sq->getSort();
        $this->assertEquals('desc', $sort['created_at']);
    }

    public function testOrderByDefaultsToAsc(): void
    {
        $sq = $this->builder->query()
            ->orderBy('title')
            ->toSearchQuery();
        $sort = $sq->getSort();
        $this->assertEquals('asc', $sort['title']);
    }

    public function testMultipleOrderBy(): void
    {
        $sq = $this->builder->query()
            ->orderBy('created_at', 'desc')
            ->orderBy('title', 'asc')
            ->toSearchQuery();
        $sort = $sq->getSort();
        $this->assertCount(2, $sort);
        $this->assertEquals('desc', $sort['created_at']);
        $this->assertEquals('asc', $sort['title']);
    }

    // ──────────────────────────────────────
    // Pagination
    // ──────────────────────────────────────

    public function testLimit(): void
    {
        $sq = $this->builder->query()
            ->limit(50)
            ->toSearchQuery();
        $this->assertEquals(50, $sq->getLimit());
    }

    public function testOffset(): void
    {
        $sq = $this->builder->query()
            ->offset(100)
            ->toSearchQuery();
        $this->assertEquals(100, $sq->getOffset());
    }

    public function testPageHelper(): void
    {
        $sq = $this->builder->query()
            ->page(3, 25)
            ->toSearchQuery();
        $this->assertEquals(25, $sq->getLimit());
        $this->assertEquals(50, $sq->getOffset()); // (3-1) * 25
    }

    public function testPageHelperDefaultPerPage(): void
    {
        $sq = $this->builder->query()
            ->page(2)
            ->toSearchQuery();
        $this->assertEquals(20, $sq->getLimit());
        $this->assertEquals(20, $sq->getOffset()); // (2-1) * 20
    }

    public function testPageOneHasZeroOffset(): void
    {
        $sq = $this->builder->query()
            ->page(1, 10)
            ->toSearchQuery();
        $this->assertEquals(0, $sq->getOffset());
    }

    // ──────────────────────────────────────
    // Fuzzy Search
    // ──────────────────────────────────────

    public function testFuzzyEnabled(): void
    {
        $sq = $this->builder->query()
            ->fuzzy(true)
            ->toSearchQuery();
        $this->assertTrue($sq->isFuzzy());
    }

    public function testFuzzyDisabled(): void
    {
        $sq = $this->builder->query()
            ->fuzzy(false)
            ->toSearchQuery();
        $this->assertFalse($sq->isFuzzy());
    }

    public function testFuzzyWithCustomFuzziness(): void
    {
        $sq = $this->builder->query()
            ->fuzzy(true, 0.6)
            ->toSearchQuery();
        $this->assertTrue($sq->isFuzzy());
        $this->assertEquals(0.6, $sq->getFuzziness());
    }

    public function testFuzzyDefaultNoArguments(): void
    {
        $sq = $this->builder->query()
            ->fuzzy()
            ->toSearchQuery();
        $this->assertTrue($sq->isFuzzy());
        $this->assertEquals(0.8, $sq->getFuzziness());
    }

    // ──────────────────────────────────────
    // Highlighting
    // ──────────────────────────────────────

    public function testHighlightEnabled(): void
    {
        $sq = $this->builder->query()
            ->highlight(true)
            ->toSearchQuery();
        $this->assertTrue($sq->shouldHighlight());
    }

    public function testHighlightDisabled(): void
    {
        $sq = $this->builder->query()
            ->highlight(false)
            ->toSearchQuery();
        $this->assertFalse($sq->shouldHighlight());
    }

    public function testHighlightWithCustomLength(): void
    {
        $sq = $this->builder->query()
            ->highlight(true, 300)
            ->toSearchQuery();
        $this->assertTrue($sq->shouldHighlight());
        $this->assertEquals(300, $sq->getHighlightLength());
    }

    // ──────────────────────────────────────
    // Boost
    // ──────────────────────────────────────

    public function testSingleBoost(): void
    {
        $sq = $this->builder->query()
            ->boost('title', 2.0)
            ->toSearchQuery();
        $boost = $sq->getBoost();
        $this->assertEquals(2.0, $boost['title']);
    }

    public function testMultipleBoosts(): void
    {
        $sq = $this->builder->query()
            ->boost('title', 3.0)
            ->boost('body', 1.0)
            ->toSearchQuery();
        $boost = $sq->getBoost();
        $this->assertCount(2, $boost);
    }

    // ──────────────────────────────────────
    // Language
    // ──────────────────────────────────────

    public function testLanguage(): void
    {
        $sq = $this->builder->query()
            ->language('french')
            ->toSearchQuery();
        $this->assertEquals('french', $sq->getLanguage());
    }

    // ──────────────────────────────────────
    // Facets
    // ──────────────────────────────────────

    public function testFacetWithOptions(): void
    {
        $sq = $this->builder->query()
            ->facet('category', ['limit' => 10])
            ->toSearchQuery();
        $facets = $sq->getFacets();
        $this->assertArrayHasKey('category', $facets);
        $this->assertEquals(['limit' => 10], $facets['category']);
    }

    public function testFacetWithoutOptions(): void
    {
        $sq = $this->builder->query()
            ->facet('tags')
            ->toSearchQuery();
        $facets = $sq->getFacets();
        $this->assertArrayHasKey('tags', $facets);
        $this->assertEquals([], $facets['tags']);
    }

    public function testMultipleFacets(): void
    {
        $sq = $this->builder->query()
            ->facet('category')
            ->facet('tags', ['limit' => 5])
            ->toSearchQuery();
        $facets = $sq->getFacets();
        $this->assertCount(2, $facets);
    }

    // ──────────────────────────────────────
    // Geo Queries
    // ──────────────────────────────────────

    public function testNearPoint(): void
    {
        $sq = $this->builder->query()
            ->nearPoint(37.7749, -122.4194, 1000, 'm')
            ->toSearchQuery();
        $geo = $sq->getGeoFilters();
        $this->assertArrayHasKey('near', $geo);
        $this->assertEquals(1000, $geo['near']['radius']);
        $this->assertTrue($sq->hasGeoFilters());
    }

    public function testWithinBounds(): void
    {
        $sq = $this->builder->query()
            ->withinBounds(38.0, 37.0, -122.0, -123.0)
            ->toSearchQuery();
        $geo = $sq->getGeoFilters();
        $this->assertArrayHasKey('within', $geo);
    }

    public function testSortByDistance(): void
    {
        $sq = $this->builder->query()
            ->sortByDistance(37.7749, -122.4194, 'asc')
            ->toSearchQuery();
        $geo = $sq->getGeoFilters();
        $this->assertArrayHasKey('distance_sort', $geo);
    }

    public function testCombinedGeoQuery(): void
    {
        $sq = $this->builder->query('restaurants')
            ->nearPoint(37.7749, -122.4194, 1000, 'm')
            ->sortByDistance(37.7749, -122.4194)
            ->limit(20)
            ->toSearchQuery();
        $geo = $sq->getGeoFilters();
        $this->assertArrayHasKey('near', $geo);
        $this->assertArrayHasKey('distance_sort', $geo);
        $this->assertEquals('restaurants', $sq->getQuery());
        $this->assertEquals(20, $sq->getLimit());
    }

    // ──────────────────────────────────────
    // toArray
    // ──────────────────────────────────────

    public function testToArrayReturnsAllProperties(): void
    {
        $arr = $this->builder->query('test')
            ->where('status', 'published')
            ->limit(10)
            ->toArray();
        $this->assertArrayHasKey('query', $arr);
        $this->assertArrayHasKey('filters', $arr);
        $this->assertArrayHasKey('limit', $arr);
        $this->assertEquals('test', $arr['query']);
        $this->assertEquals(10, $arr['limit']);
    }

    // ──────────────────────────────────────
    // Method Chaining
    // ──────────────────────────────────────

    public function testFullMethodChaining(): void
    {
        $sq = $this->builder->query('golang tutorial')
            ->in('articles')
            ->where('status', 'published')
            ->whereIn('category', ['tech', 'programming'])
            ->whereNotIn('tags', ['deprecated'])
            ->whereLike('title', '%advanced%')
            ->fields(['title', 'body', 'author'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->offset(20)
            ->fuzzy(true, 0.7)
            ->highlight(true, 200)
            ->boost('title', 2.5)
            ->language('english')
            ->facet('category')
            ->toSearchQuery();

        $this->assertEquals('golang tutorial', $sq->getQuery());
        $this->assertCount(4, $sq->getFilters());
        $this->assertCount(3, $sq->getFields());
        $this->assertEquals('desc', $sq->getSort()['created_at']);
        $this->assertEquals(10, $sq->getLimit());
        $this->assertEquals(20, $sq->getOffset());
        $this->assertTrue($sq->isFuzzy());
        $this->assertEquals(0.7, $sq->getFuzziness());
        $this->assertTrue($sq->shouldHighlight());
        $this->assertEquals(200, $sq->getHighlightLength());
        $this->assertEquals(2.5, $sq->getBoost()['title']);
        $this->assertEquals('english', $sq->getLanguage());
        $this->assertArrayHasKey('category', $sq->getFacets());
    }

    // ──────────────────────────────────────
    // get() / count() Without Index
    // ──────────────────────────────────────

    public function testGetWithoutIndexThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Index not specified');
        $this->builder->query('test')->get();
    }

    public function testCountWithoutIndexThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Index not specified');
        $this->builder->query('test')->count();
    }

    // ──────────────────────────────────────
    // QueryBuilder.parse() Auto-Detection
    // ──────────────────────────────────────

    public function testParseDetectsDslString(): void
    {
        $sq = $this->builder->parse('author = "John" SORT title');
        $this->assertInstanceOf(SearchQuery::class, $sq);
        $filters = $sq->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('author', $filters[0]['field']);
        $this->assertArrayHasKey('title', $sq->getSort());
    }

    public function testParseDetectsUrlQueryString(): void
    {
        $sq = $this->builder->parse('filter[author][eq]=John&page[limit]=10');
        $this->assertInstanceOf(SearchQuery::class, $sq);
        $filters = $sq->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals(10, $sq->getLimit());
    }

    public function testParseAcceptsArray(): void
    {
        $sq = $this->builder->parse([
            'q' => 'test',
            'filter' => ['status' => ['eq' => 'published']]
        ]);
        $this->assertInstanceOf(SearchQuery::class, $sq);
        $this->assertEquals('test', $sq->getQuery());
    }

    public function testParsePassesThroughSearchQuery(): void
    {
        $original = new SearchQuery('original');
        $result = $this->builder->parse($original);
        $this->assertSame($original, $result);
    }

    public function testParseInvalidTypeThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->parse(42);
    }

    // ──────────────────────────────────────
    // QueryBuilder Configuration
    // ──────────────────────────────────────

    public function testSetFieldAliases(): void
    {
        $this->builder->setFieldAliases(['writer' => 'author']);
        $sq = $this->builder->parse('writer = "John"');
        $filters = $sq->getFilters();
        $this->assertEquals('author', $filters[0]['field']);
    }

    public function testSetMetadataFields(): void
    {
        $config = ['storage' => ['path' => ':memory:']];
        $yeti = new YetiSearch($config);
        $builder = new QueryBuilder($yeti, [
            'metadata_fields' => ['custom_field']
        ]);
        // Metadata fields config is set; verifiable if we could call executeSearch
        // For now, verify no errors in construction
        $this->assertNotNull($builder);
    }

    public function testAddMetadataField(): void
    {
        $this->builder->addMetadataField('custom_score');
        // Verify chainability
        $result = $this->builder->addMetadataField('priority_level');
        $this->assertSame($this->builder, $result);
    }
}
