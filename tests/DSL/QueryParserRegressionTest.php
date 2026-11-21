<?php

namespace YetiSearch\Tests\DSL;

use PHPUnit\Framework\TestCase;
use YetiSearch\DSL\QueryParser;
use YetiSearch\Models\SearchQuery;

class QueryParserRegressionTest extends TestCase
{
    /**
     * Parses with a time limit, so a parser that loops fails the test instead
     * of hanging the run
     */
    private function parse(string $input, array $defaults = []): SearchQuery
    {
        $parser = new QueryParser([], $defaults);
        if (!function_exists('pcntl_alarm')) {
            return $parser->parse($input);
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use ($input) {
            throw new \RuntimeException("Parsing '{$input}' did not finish");
        });
        pcntl_alarm(2);
        try {
            return $parser->parse($input);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
        }
    }

    public function queriesThatHung(): array
    {
        return [
            'sorting algorithms' => ['sorting algorithms', 'sorting algorithms', [], false],
            'fuzzy logic' => ['fuzzy logic', 'fuzzy logic', [], false],
            'highlighting tips' => ['highlighting tips', 'highlighting tips', [], false],
            'hello FUZZY' => ['hello FUZZY', 'hello', [], true],
            'hello HIGHLIGHT' => ['hello HIGHLIGHT', 'hello HIGHLIGHT', [], false],
            'SORT on a quoted name' => ['hello SORT "title"', 'hello SORT title', [], false],
            'SORT with a direction' => ['hello SORT title:desc', 'hello', ['title' => 'desc'], false],
        ];
    }

    /**
     * @dataProvider queriesThatHung
     */
    public function testQueriesThatHungNowParse(string $input, string $text, array $sort, bool $fuzzy): void
    {
        $query = $this->parse($input);

        $this->assertSame($text, $query->getQuery());
        $this->assertSame($sort, $query->getSort());
        $this->assertSame($fuzzy, $query->isFuzzy());
    }

    public function plainText(): array
    {
        $words = [
            'india', 'order online', 'android', 'index', 'limited edition', 'landing page',
            'nearby cafes', 'within reach', 'orange juice', 'informatics', 'standard', 'pagerank',
            'offsets', 'fieldwork notes', 'made in china', 'i like turtles', 'rock and roll',
            'cafe NEAR', 'not in stock',
        ];

        return array_combine($words, array_map(function ($word) {
            return [$word];
        }, $words));
    }

    /**
     * @dataProvider plainText
     */
    public function testWordsHoldingOperatorsOrKeywordsStayText(string $input): void
    {
        $query = $this->parse($input);

        $this->assertSame($input, $query->getQuery());
        $this->assertSame([], $query->getFilters());
        $this->assertSame([], $query->getSort());
        $this->assertSame(20, $query->getLimit());
        $this->assertSame(0, $query->getOffset());
    }

    public function testPaginationKeywordsReadTheirNumbers(): void
    {
        $this->assertSame(10, $this->parse('hello LIMIT 10')->getLimit());
        $this->assertSame(30, $this->parse('hello OFFSET 30')->getOffset());

        $page = $this->parse('hello world PAGE 2,5');
        $this->assertSame('hello world', $page->getQuery());
        $this->assertSame(5, $page->getLimit());
        $this->assertSame(5, $page->getOffset());

        $both = $this->parse('LIMIT 5 OFFSET 10');
        $this->assertSame(5, $both->getLimit());
        $this->assertSame(10, $both->getOffset());
    }

    public function testSortReadsMinusAndDirections(): void
    {
        $query = $this->parse('hello SORT -date, title, rank:desc');

        $this->assertSame('hello', $query->getQuery());
        $this->assertSame(['date' => 'desc', 'title' => 'asc', 'rank' => 'desc'], $query->getSort());
    }

    public function testNumbersAreTypedAndOtherValuesStayStrings(): void
    {
        $query = $this->parse(
            'a = 10 AND b >= 3.5 AND c > -2 AND zip = 02134 AND day = 2024-01-15 '
            . 'AND slug = hello-world AND big = 12345678901234567890 AND e = 1e3'
        );

        $values = array_column($query->getFilters(), 'value', 'field');
        $this->assertSame(10, $values['a']);
        $this->assertSame(3.5, $values['b']);
        $this->assertSame(-2, $values['c']);
        $this->assertSame('02134', $values['zip']);
        $this->assertSame('2024-01-15', $values['day']);
        $this->assertSame('hello-world', $values['slug']);
        $this->assertSame('12345678901234567890', $values['big']);
        $this->assertSame('1e3', $values['e']);
        $this->assertSame('', $query->getQuery());
    }

    public function testNotInWithAnySpacing(): void
    {
        foreach (["status NOT  IN [a]", "status not\nin [a]"] as $input) {
            $filters = $this->parse($input)->getFilters();
            $this->assertSame([['field' => 'status', 'value' => ['a'], 'operator' => 'not in']], $filters, $input);
        }
    }

    public function testTextAfterConditionsIsSearched(): void
    {
        $query = $this->parse('author = john hello world');

        $this->assertSame('hello world', $query->getQuery());
        $this->assertCount(1, $query->getFilters());
    }

    public function testGroupingAndLogicalWordsAreNotSearchText(): void
    {
        $query = $this->parse('(a = 1 OR b = 2) AND c = 3');
        $this->assertSame('', $query->getQuery());
        $this->assertSame(['a', 'b', 'c'], array_column($query->getFilters(), 'field'));

        $query = $this->parse('hello AND author = john');
        $this->assertSame('hello', $query->getQuery());
        $this->assertCount(1, $query->getFilters());
    }

    public function testNonAsciiWordsAreKept(): void
    {
        $this->assertSame('café crème 日本語', $this->parse('café crème 日本語')->getQuery());
    }

    public function testStartsFromTheDefaults(): void
    {
        $query = $this->parse('hello', ['limit' => 5, 'fuzzy' => true, 'highlight' => false]);

        $this->assertSame(5, $query->getLimit());
        $this->assertTrue($query->isFuzzy());
        $this->assertFalse($query->shouldHighlight());
    }
}
