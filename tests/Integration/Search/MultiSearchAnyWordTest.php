<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Tests\TestCase;

/**
 * multiSearch() finds the documents with any of the words, those with all of them first, as
 * search() does. It needed every word, so 'stir wars' found only a document with both.
 */
class MultiSearchAnyWordTest extends TestCase
{
    public function test_any_of_the_words_is_found_and_all_of_them_rank_first(): void
    {
        $search = $this->createSearchInstance(['search' => ['min_score' => 0.0, 'cache_ttl' => 0]]);
        $this->createTestIndex('films');
        $this->createTestIndex('food');
        $search->indexBatch('films', [
            ['id' => 'parody', 'content' => ['title' => 'Stir Wars', 'content' => 'A parody of a space saga']],
            ['id' => 'star', 'content' => ['title' => 'Star Wars', 'content' => 'A space saga']],
            ['id' => 'roses', 'content' => ['title' => 'The Wars of the Roses', 'content' => 'English history']],
        ]);
        $search->indexBatch('food', [
            ['id' => 'wok', 'content' => ['title' => 'Stir fry', 'content' => 'Cooking with a wok']],
            ['id' => 'soup', 'content' => ['title' => 'Soup', 'content' => 'Cooking with a pot']],
        ]);

        $results = $search->multiSearch(['films', 'food'], 'stir wars');
        $ids = array_column($results['results'], 'id');

        $this->assertSame(4, $results['total']);
        $this->assertSame('parody', $ids[0]);
        $this->assertEqualsCanonicalizing(['parody', 'star', 'roses', 'wok'], $ids);
    }
}
