<?php

namespace YetiSearch\Tests\DSL;

use PHPUnit\Framework\TestCase;
use YetiSearch\DSL\QueryBuilder;
use YetiSearch\YetiSearch;

class QueryBuilderRegressionTest extends TestCase
{
    private YetiSearch $yeti;
    private QueryBuilder $builder;

    protected function setUp(): void
    {
        $this->yeti = new YetiSearch(['storage' => ['path' => ':memory:']]);
        $this->yeti->createIndex('places');
        $this->yeti->indexBatch('places', [
            ['id' => 'sf', 'type' => 'article', 'content' => ['title' => 'cafe sf'],
                'metadata' => ['price' => 5], 'geo' => ['lat' => 37.7749, 'lng' => -122.4194]],
            ['id' => 'oak', 'type' => 'page', 'content' => ['title' => 'cafe oakland'],
                'metadata' => ['price' => 50], 'geo' => ['lat' => 37.8044, 'lng' => -122.2712]],
            ['id' => 'ny', 'type' => 'article', 'content' => ['title' => 'cafe ny'],
                'metadata' => ['price' => 20], 'geo' => ['lat' => 40.7128, 'lng' => -74.0060]],
        ]);
        $this->builder = new QueryBuilder($this->yeti);
    }

    private function ids(array $results): array
    {
        $ids = array_column($results['results'], 'id');
        sort($ids);

        return $ids;
    }

    public function testNearPointAndUrlNearSearch(): void
    {
        $fluent = $this->builder->query('cafe')->in('places')->nearPoint(37.77, -122.41, 20000, 'm')->get();
        $this->assertSame(['oak', 'sf'], $this->ids($fluent));

        $url = $this->builder->searchWithURL(
            'places',
            'q=cafe&geo[near][lat]=37.77&geo[near][lng]=-122.41&geo[near][radius]=20&geo[near][units]=km'
        );
        $this->assertSame(['oak', 'sf'], $this->ids($url));
    }

    public function testTypeAndIdAreColumnsNotMetadata(): void
    {
        $this->assertSame(['ny', 'sf'], $this->ids($this->builder->searchWithDSL('places', 'cafe type = "article"')));
        $this->assertSame(['sf'], $this->ids($this->builder->searchWithDSL('places', 'cafe id = "sf"')));
        $this->assertSame(['ny', 'sf'], $this->ids($this->builder->searchWithURL('places', 'q=cafe&filter[type]=article')));
        $this->assertSame(['ny', 'sf'], $this->ids($this->builder->query('cafe')->in('places')->where('type', 'article')->get()));

        $sorted = $this->builder->searchWithURL('places', 'q=cafe&sort=-type');
        $this->assertSame('oak', $sorted['results'][0]['id']);
    }

    public function testDslNumbersPaginationAndDescendingSort(): void
    {
        $this->assertSame(['ny'], $this->ids($this->builder->searchWithDSL('places', 'cafe price = 20')));
        $this->assertCount(2, $this->builder->searchWithDSL('places', 'cafe LIMIT 2')['results']);

        $sorted = $this->builder->searchWithDSL('places', 'cafe SORT -price');
        $this->assertSame(['oak', 'ny', 'sf'], array_column($sorted['results'], 'id'));
    }

    public function testCountMatchesGet(): void
    {
        $queries = [
            'metadata filter' => function () {
                return $this->builder->query('cafe')->in('places')->where('price', 10, '>');
            },
            'type filter' => function () {
                return $this->builder->query('cafe')->in('places')->where('type', 'article');
            },
            'near' => function () {
                return $this->builder->query('cafe')->in('places')->nearPoint(37.77, -122.41, 20, 'km');
            },
            'bounds' => function () {
                return $this->builder->query('cafe')->in('places')->withinBounds(38, 37, -122, -123);
            },
        ];

        foreach ($queries as $name => $query) {
            $this->assertSame($query()->get()['total'], $query()->count(), $name);
        }
        $this->assertSame(2, $queries['metadata filter']()->count());
    }

    public function testParseTellsUrlParametersFromDsl(): void
    {
        $url = $this->builder->parse('q=golang');
        $this->assertSame('golang', $url->getQuery());
        $this->assertSame([], $url->getFilters());

        $dsl = $this->builder->parse('title = "R&D"');
        $this->assertSame('', $dsl->getQuery());
        $this->assertSame([['field' => 'title', 'value' => 'R&D', 'operator' => '=']], $dsl->getFilters());

        $this->assertSame(10, $this->builder->parse('filter%5Bauthor%5D=John&page%5Blimit%5D=10')->getLimit());
        // Not a URL parameter name, so a DSL condition
        $this->assertSame(
            [['field' => 'status', 'value' => 'published', 'operator' => '=']],
            $this->builder->parse('status=published')->getFilters()
        );
    }

    public function testQueryDefaultsApplyToEveryQueryStyle(): void
    {
        $builder = new QueryBuilder($this->yeti, ['default_limit' => 5, 'default_fuzzy' => true, 'default_highlight' => false]);

        foreach ([$builder->parse('x'), $builder->parse('q=x'), $builder->query('x')->toSearchQuery()] as $query) {
            $this->assertSame(5, $query->getLimit());
            $this->assertTrue($query->isFuzzy());
            $this->assertFalse($query->shouldHighlight());
        }

        $this->assertSame(8, $builder->parse('q=x&limit=8')->getLimit());
        $this->assertSame(8, $builder->parse('x LIMIT 8')->getLimit());
    }

    public function testFieldsSelectWhatIsReturnedAndDoNotNarrowTheSearch(): void
    {
        $this->yeti->createIndex('articles');
        $this->yeti->indexBatch('articles', [
            ['id' => 'a', 'content' => ['title' => 'Golang tutorial', 'content' => 'Learn go', 'author' => 'Ann'], 'metadata' => ['views' => 7]],
            ['id' => 'b', 'content' => ['title' => 'Notes', 'content' => 'A golang primer', 'author' => 'Bob'], 'metadata' => ['views' => 7]],
        ]);

        // 'b' has golang in its content only, which FIELDS does not leave out
        $dsl = $this->builder->searchWithDSL('articles', 'golang FIELDS title, author');
        $this->assertSame(['a', 'b'], $this->ids($dsl));
        $this->assertSame(['title', 'author'], array_keys($dsl['results'][0]['document']));

        $aliased = $this->builder->searchWithDSL('articles', 'golang FIELDS title:headline');
        $this->assertSame(['headline'], array_keys($aliased['results'][0]['document']));

        // A field without an alias beside one with, and metadata fields
        $mixed = $this->builder->searchWithDSL('articles', 'golang FIELDS title, author:writer, views, metadata.views:seen');
        $this->assertSame(['title', 'writer', 'views', 'seen'], array_keys($mixed['results'][0]['document']));
        $this->assertSame(7, $mixed['results'][0]['document']['seen']);

        $url = $this->builder->searchWithURL('articles', 'q=golang&fields=title');
        $this->assertSame(['a', 'b'], $this->ids($url));
        $this->assertSame(['title'], array_keys($url['results'][0]['document']));
    }
}
