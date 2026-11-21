<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Index\Indexer;
use YetiSearch\Tests\TestCase;

/**
 * The boosts of the indexer's fields rank the results, as the search's field_weights do: a
 * title that is the query comes first, its stop words aside, and a word counts where it begins
 * a word of a field.
 */
class FieldBoostRankingTest extends TestCase
{
    private const INDEX = 'boost_idx';

    private function index(array $search = [], array $indexer = []): void
    {
        $this->createSearchInstance([
            'storage' => ['external_content' => true],
            'indexer' => $indexer + ['fields' => [
                'title' => ['boost' => 5.0, 'store' => true],
                'overview' => ['boost' => 1.0, 'store' => true],
            ]],
            'search' => $search + ['min_score' => 0.0, 'cache_ttl' => 0],
        ]);
        $this->createTestIndex(self::INDEX);
        $this->search->indexBatch(self::INDEX, [
            ['id' => 'matrix', 'content' => ['title' => 'The Matrix', 'overview' => 'A hacker learns the truth']],
            ['id' => 'revisited', 'content' => ['title' => 'The Matrix Revisited', 'overview' => 'How the matrix was made: the matrix, its matrix effects and the matrix sets']],
            ['id' => 'animatrix', 'content' => ['title' => 'The Animatrix', 'overview' => 'Short films set in the matrix, the matrix world and the matrix war']],
            ['id' => 'inception', 'content' => ['title' => 'Inception', 'overview' => 'A thief enters dreams']],
            ['id' => 'inept', 'content' => ['title' => 'Office comedy', 'overview' => 'An inept boss, an inept team, an inept plan']],
        ]);
    }

    private function ids(string $query, array $options = []): array
    {
        return array_column($this->search->search(self::INDEX, $query, $options + ['fuzzy' => false, 'limit' => 10])['results'], 'id');
    }

    public function test_a_title_that_is_the_query_comes_first_its_stop_words_aside(): void
    {
        $this->index();

        $this->assertSame('matrix', $this->ids('the matrix')[0]);
        $this->assertSame('matrix', $this->ids('matrix')[0]);
    }

    public function test_a_word_counts_where_it_begins_a_word_of_the_field(): void
    {
        $this->index();

        // 'matrix' begins a word of the title 'The Matrix Revisited', not of 'The Animatrix'
        $ids = $this->ids('matrix');
        $this->assertLessThan(array_search('animatrix', $ids, true), array_search('revisited', $ids, true));
    }

    public function test_a_word_cut_short_counts_in_the_title_it_begins(): void
    {
        $this->index(['prefix_last_token' => true]);

        $this->assertSame('inception', $this->ids('inept incept')[0]);
    }

    public function test_empty_field_weights_rank_by_text_relevance_alone(): void
    {
        $this->index(['field_weights' => []]);

        // The overviews that say matrix four times outrank the title that is the query
        $this->assertNotSame('matrix', $this->ids('the matrix')[0]);
    }

    public function test_boosts_of_the_fields(): void
    {
        $this->assertSame(['title' => 3.0, 'body' => 1.0], Indexer::boosts([
            'title' => ['boost' => 3.0],
            'body' => [],
            'url' => ['boost' => 9.0, 'index' => false],
        ]));
        $this->assertSame(['title' => 2.0, 'body' => 1.0], Indexer::boosts(['title' => ['boost' => 2.0], 'body']));
        // Nothing to weigh when every field has the same boost
        $this->assertSame([], Indexer::boosts(['title', 'body']));
        $this->assertSame([], Indexer::boosts(['title' => ['boost' => 2.0], 'body' => ['boost' => 2.0]]));
        $this->assertSame(3.0, Indexer::boosts(null)['title']);
    }

    public function test_results_of_the_same_score_keep_their_order(): void
    {
        $this->index();
        $twins = [];
        for ($i = 10; $i < 40; $i++) {
            $twins[] = ['id' => "twin{$i}", 'content' => ['title' => 'Widget', 'overview' => 'A widget']];
        }
        $this->search->indexBatch(self::INDEX, $twins);

        // Re-ranked by the boosts in PHP, whose sort before PHP 8 did not keep this order
        $this->assertSame(array_column($twins, 'id'), $this->ids('widget', ['limit' => 30]));
    }
}
