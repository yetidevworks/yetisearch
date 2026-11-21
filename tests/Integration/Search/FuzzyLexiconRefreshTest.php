<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Tests\TestCase;

/**
 * Typo correction picks from the terms the index holds now, not from those it held
 * when the first fuzzy search read them.
 */
class FuzzyLexiconRefreshTest extends TestCase
{
    public function test_a_term_written_after_a_fuzzy_search_is_a_correction_target_at_once(): void
    {
        $index = 'lexicon_refresh';
        $search = $this->createSearchInstance(['search' => ['cache_ttl' => 0]]);
        $this->createTestIndex($index);
        $search->index($index, ['id' => 'a', 'content' => ['title' => 'Space shuttle', 'content' => 'Orbiter flights']]);

        $before = $search->search($index, 'gladiatr', ['fuzzy' => true]);
        $this->assertSame(0, $before['total']);

        $search->index($index, ['id' => 'b', 'content' => ['title' => 'Gladiator', 'content' => 'Arena film']]);

        $after = $search->search($index, 'gladiatr', ['fuzzy' => true]);
        $this->assertSame(['b'], array_column($after['results'], 'id'));
    }
}
