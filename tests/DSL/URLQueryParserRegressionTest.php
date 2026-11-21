<?php

namespace YetiSearch\Tests\DSL;

use PHPUnit\Framework\TestCase;
use YetiSearch\DSL\URLQueryParser;

class URLQueryParserRegressionTest extends TestCase
{
    private URLQueryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new URLQueryParser();
    }

    private function filterValue(string $queryString)
    {
        return $this->parser->parseFromQueryString($queryString)->getFilters()[0]['value'];
    }

    public function testCommaSplitsListOperatorsOnly(): void
    {
        $this->assertSame('Hello, World', $this->filterValue('filter[title]=Hello, World'));
        $this->assertSame('a,b', $this->filterValue('filter[title][like]=a,b'));
        $this->assertSame(['a', 'b'], $this->filterValue('filter[tag][in]=a,b'));
        $this->assertSame(['a', 'b'], $this->filterValue('filter[tag][nin]=a, b'));
        $this->assertSame([1, 5], $this->filterValue('filter[price][between]=1,5'));
    }

    public function testOnlyNumbersWrittenPlainlyBecomeNumbers(): void
    {
        $this->assertSame(42, $this->filterValue('filter[a]=42'));
        $this->assertSame(-3.5, $this->filterValue('filter[a]=-3.5'));
        $this->assertSame(0, $this->filterValue('filter[a]=0'));
        $this->assertSame('00123', $this->filterValue('filter[sku]=00123'));
        $this->assertSame('1e3', $this->filterValue('filter[a]=1e3'));
        $this->assertSame('0x1A', $this->filterValue('filter[a]=0x1A'));
        $this->assertSame('12345678901234567890', $this->filterValue('filter[a]=12345678901234567890'));
        $this->assertSame(['007', 7], $this->filterValue('filter[a][in]=007,7'));
    }

    public function testRepeatedFilterValuesMakeAnInFilter(): void
    {
        $filters = $this->parser->parseFromQueryString('filter[category][]=a&filter[category][]=b')->getFilters();

        $this->assertSame([['field' => 'category', 'value' => ['a', 'b'], 'operator' => 'in']], $filters);
    }

    public function testSortAsAnArray(): void
    {
        $this->assertSame(
            ['price' => 'asc', 'title' => 'desc'],
            $this->parser->parseFromQueryString('sort[]=price&sort[]=-title')->getSort()
        );
        $this->assertSame(['title' => 'desc'], $this->parser->parseFromQueryString('sort[title]=desc')->getSort());
    }

    public function testPageNumberWithoutSizeUsesTheLimit(): void
    {
        $query = $this->parser->parseFromQueryString('page[number]=2');
        $this->assertSame(20, $query->getLimit());
        $this->assertSame(20, $query->getOffset());

        $query = $this->parser->parseFromQueryString('page=3&limit=10');
        $this->assertSame(10, $query->getLimit());
        $this->assertSame(20, $query->getOffset());

        $query = $this->parser->parseFromQueryString('page[number]=3&page[limit]=5');
        $this->assertSame(5, $query->getLimit());
        $this->assertSame(10, $query->getOffset());
    }

    public function testLimitThatIsNotANumberKeepsTheDefault(): void
    {
        foreach (['limit=', 'limit=abc', 'page[limit]=', 'limit=-5'] as $queryString) {
            $this->assertSame(20, $this->parser->parseFromQueryString($queryString)->getLimit(), $queryString);
        }

        $parser = new URLQueryParser([], ['limit' => 7]);
        $this->assertSame(7, $parser->parseFromQueryString('limit=abc')->getLimit());
    }

    public function testFacetsAsAList(): void
    {
        $facets = $this->parser->parseFromQueryString('facets[]=brand&facets[]=category')->getFacets();

        $this->assertSame(['brand' => [], 'category' => []], $facets);
    }

    public function testArrayQueryIsIgnored(): void
    {
        $this->assertSame('', $this->parser->parseFromQueryString('q[]=x&language[]=en')->getQuery());
    }
}
