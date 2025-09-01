<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Tests\TestCase;

class SuggestionsTest extends TestCase
{
    private string $index = 'suggest_idx';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSearchInstance([
            'indexer' => [
                'fields' => [
                    'title' => ['boost' => 3.0, 'store' => true],
                    'content' => ['boost' => 1.0, 'store' => true],
                    'tags' => ['boost' => 2.0, 'store' => true],
                ],
            ],
            'search' => [
                'enable_fuzzy' => true,
                'fuzzy_algorithm' => 'jaro_winkler',
            ],
        ]);

        $this->createTestIndex($this->index);

        $docs = [
            ['id' => 'a1', 'content' => ['title' => 'Anakin Skywalker', 'content' => 'Jedi']],
            ['id' => 'l1', 'content' => ['title' => 'Luke Skywalker', 'content' => 'Jedi Knight']],
            ['id' => 's1', 'content' => ['title' => 'Star Wars', 'content' => 'Space opera']],
            ['id' => 'k1', 'content' => ['title' => 'The Dark Knight', 'content' => 'Batman vs Joker']],
            ['id' => 'g1', 'content' => ['title' => 'Skylark', 'content' => 'Songbird']],
        ];
        foreach ($docs as $d) {
            $this->search->index($this->index, $d);
        }
    }

    public function test_suggest_ranks_relevant_titles_first(): void
    {
        $sugs = $this->search->suggest($this->index, 'Skywaker', [
            'limit' => 5,
            'per_variant' => 3,
        ]);

        $this->assertNotEmpty($sugs, 'Expected non-empty suggestions');

        // Expect suggestions to include Skywalker titles and rank highly
        $texts = array_map(fn($s) => $s['text'], $sugs);
        $this->assertTrue(
            (bool)array_filter($texts, fn($t) => stripos($t, 'Skywalker') !== false),
            'Expected a suggestion containing "Skywalker"'
        );

        // The first suggestion should be one of the Skywalker titles or close
        $first = $sugs[0]['text'] ?? '';
        $this->assertTrue(
            stripos($first, 'Skywalker') !== false || stripos($first, 'Sky') !== false,
            'Top suggestion should be Skywalker-related'
        );
    }
}

