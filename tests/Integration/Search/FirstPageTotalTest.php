<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Tests\TestCase;
use YetiSearch\YetiSearch;

/**
 * A first page with room left over holds every match, and its total is the number of
 * results; the total of any other page is counted. Both give the same totals.
 */
class FirstPageTotalTest extends TestCase
{
    private const INDEX = 'first_page_total';

    private function search(): YetiSearch
    {
        $search = $this->createSearchInstance(['search' => ['cache_ttl' => 0]]);
        $this->createTestIndex(self::INDEX);
        $documents = [];
        for ($i = 1; $i <= 12; $i++) {
            $documents[] = [
                'id' => "d$i",
                'content' => ['title' => "Rocket $i", 'content' => $i % 3 === 0 ? 'launch window' : 'ground test'],
                'metadata' => ['group' => $i % 2 === 0 ? 'even' : 'odd'],
            ];
        }
        $search->indexBatch(self::INDEX, $documents);

        return $search;
    }

    public function test_totals_are_the_number_of_matches_whatever_the_page(): void
    {
        $search = $this->search();

        $cases = [
            ['launch', ['limit' => 10], 4, 4],
            ['launch', ['limit' => 4], 4, 4],
            ['launch', ['limit' => 3], 4, 3],
            ['launch', ['limit' => 3, 'offset' => 3], 4, 1],
            ['rocket', ['limit' => 5], 12, 5],
            ['rocket', ['limit' => 20], 12, 12],
            ['rocket', ['limit' => 20, 'offset' => 10], 12, 2],
            ['rocket', ['limit' => 20, 'filters' => [['field' => 'metadata.group', 'value' => 'even']]], 6, 6],
            ['nothing', ['limit' => 10], 0, 0],
        ];
        foreach ($cases as [$query, $options, $total, $count]) {
            $results = $search->search(self::INDEX, $query, $options);
            $label = $query . ' ' . json_encode($options);
            $this->assertSame($total, $results['total'], "total of $label");
            $this->assertCount($count, $results['results'], "results of $label");
        }
    }
}
