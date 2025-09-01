<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Tests\TestCase;

class SynonymsTest extends TestCase
{
    public function test_synonyms_expand_terms_and_find_results(): void
    {
        $search = $this->createSearchInstance([
            'search' => [
                'enable_synonyms' => true,
                'synonyms' => [
                    'nyc' => ['new york', 'new york city'],
                    'la' => ['los angeles']
                ],
                'synonyms_case_sensitive' => false,
                'synonyms_max_expansions' => 3,
            ],
        ]);
        $index = 'syn_idx';
        $this->createTestIndex($index);

        $docs = [
            ['id' => 'ny1', 'content' => ['title' => 'Best Coffee in New York']],
            ['id' => 'ny2', 'content' => ['title' => 'New York City Bagels']],
            ['id' => 'la1', 'content' => ['title' => 'Los Angeles Coffee']],
        ];
        $search->indexBatch($index, $docs);

        $res = $search->search($index, 'nyc coffee', [
            'limit' => 5,
            'fuzzy' => false,
        ]);
        $ids = array_column($res['results'], 'id');
        $this->assertTrue(in_array('ny1', $ids) || in_array('ny2', $ids), 'Expected NYC -> New York synonym expansion to match NYC coffee query');
    }
}
