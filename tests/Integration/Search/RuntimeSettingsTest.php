<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Tests\TestCase;

/**
 * Settings given to one search, or to the engine with updateConfig(), apply from the next search
 * on: nothing read with the old ones (terms, synonyms, results) is used again.
 */
class RuntimeSettingsTest extends TestCase
{
    private const INDEX = 'runtime_idx';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSearchInstance(['search' => ['enable_fuzzy' => true, 'min_score' => 0.0]]);
        $this->createTestIndex(self::INDEX);
    }

    private function index(array $titles): void
    {
        $documents = [];
        foreach ($titles as $id => $title) {
            $documents[] = ['id' => (string)$id, 'content' => ['title' => $title, 'content' => '']];
        }
        $this->search->indexBatch(self::INDEX, $documents);
    }

    private function ids(string $query, array $options = []): array
    {
        $results = $this->search->search(self::INDEX, $query, $options + ['fuzzy' => true, 'bypass_cache' => true]);

        return array_column($results['results'], 'id');
    }

    public function test_min_term_frequency_given_to_a_search_applies_to_it(): void
    {
        $this->index(['mollie' => 'Mollie', 'giraffe1' => 'Giraffe', 'giraffe2' => 'Giraffe']);

        // 'mollie' is in one document
        $this->assertSame(['mollie'], $this->ids('molie'));
        $this->assertSame([], $this->ids('molie', ['min_term_frequency' => 2]));
        $this->assertSame(['mollie'], $this->ids('molie', ['min_term_frequency' => 1]));
        $this->assertEqualsCanonicalizing(['giraffe1', 'giraffe2'], $this->ids('girafe', ['min_term_frequency' => 2]));
    }

    public function test_synonyms_given_to_a_search_apply_to_it(): void
    {
        $this->index(['car' => 'Car', 'truck' => 'Truck']);

        foreach (['car', 'truck', 'car'] as $target) {
            $ids = $this->ids('automobile', ['synonyms' => ['automobile' => [$target]], 'fuzzy' => false]);
            $this->assertSame([$target], $ids);
        }
        $this->assertSame([], $this->ids('automobile', ['fuzzy' => false]));
    }

    public function test_update_config_forgets_results_held_in_memory(): void
    {
        $this->index(['mollie' => 'Mollie']);
        $this->assertSame(1, $this->search->search(self::INDEX, 'mollie')['total']);

        $this->search->getSearchEngine(self::INDEX)->updateConfig(['min_score' => PHP_FLOAT_MAX]);

        $this->assertSame([], $this->search->search(self::INDEX, 'mollie')['results']);
    }

    public function test_a_known_misspelling_that_is_a_word_of_the_index_is_kept(): void
    {
        // 'fone' is the list's misspelling of 'phone', here also the name of a brand
        $this->index(['brand' => 'Fone', 'device' => 'Phone']);

        $this->assertSame(['brand'], $this->ids('fone'));
    }

    public function test_a_known_misspelling_of_a_much_commoner_word_is_corrected(): void
    {
        $titles = ['typo' => 'Teh cat sat', 'matrix' => 'The Matrix'];
        for ($i = 0; $i < 20; $i++) {
            $titles['other' . $i] = 'The story ' . $i;
        }
        $this->index($titles);

        $this->assertSame('matrix', $this->ids('teh matrix')[0]);
    }
}
