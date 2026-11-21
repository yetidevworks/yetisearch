<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Tests\TestCase;

/**
 * A fuzzy search of several words finds the documents with any of them, those with all of them
 * first, as a search without fuzzy does. It used to need every word, so a word that was in no
 * document with the others ('stir' in 'stir wars') left it with nothing; such a word is now read
 * as the word one slip away that is near the others ('star').
 */
class FuzzyFindsAnyWordTest extends TestCase
{
    private const INDEX = 'fuzzy_any_idx';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSearchInstance(['search' => ['enable_fuzzy' => true, 'cache_ttl' => 0, 'min_score' => 0.0]]);
        $this->createTestIndex(self::INDEX);
        $this->search->indexBatch(self::INDEX, [
            ['id' => 'star', 'content' => ['title' => 'Star Wars', 'content' => 'A galaxy far away at war']],
            ['id' => 'roses', 'content' => ['title' => 'The Wars of the Roses', 'content' => 'English history']],
            ['id' => 'stir', 'content' => ['title' => 'Stir fry', 'content' => 'Cooking with a wok']],
            ['id' => 'godfather', 'content' => ['title' => 'The Godfather', 'content' => 'A crime family saga']],
            ['id' => 'crime', 'content' => ['title' => 'Crime Story', 'content' => 'A detective in the city']],
        ]);
    }

    private function ids(string $query, bool $fuzzy): array
    {
        return array_column($this->search->search(self::INDEX, $query, ['fuzzy' => $fuzzy])['results'], 'id');
    }

    public function test_a_word_never_near_the_others_is_read_as_the_word_that_is(): void
    {
        $this->assertEqualsCanonicalizing(['star', 'roses', 'stir'], $this->ids('stir wars', false));
        $fuzzy = $this->ids('stir wars', true);

        $this->assertSame('star', $fuzzy[0]);
        $this->assertContains('roses', $fuzzy);
    }

    public function test_words_that_are_near_each_other_are_kept_as_typed(): void
    {
        $this->assertSame('stir', $this->ids('stir fry', true)[0]);
    }

    public function test_a_corrected_word_ranks_the_document_with_every_word_first(): void
    {
        $fuzzy = $this->ids('godfathr crime', true);

        $this->assertSame('godfather', $fuzzy[0]);
        $this->assertContains('crime', $fuzzy);
    }
}
