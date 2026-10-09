<?php

namespace YetiSearch\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use YetiSearch\Analyzers\StandardAnalyzer;
use YetiSearch\Contracts\ProvidesStemming;
use YetiSearch\Contracts\StorageInterface;
use YetiSearch\Models\SearchQuery;
use YetiSearch\Search\SearchEngine;
use YetiSearch\Stemmer\StemmerFactory;

/**
 * What the engine hands the storage for a query on an index that stems: the
 * raw MATCH expression as it always was, and beside it the stem query.
 */
class StemQueryTest extends TestCase
{
    protected function tearDown(): void
    {
        StemmerFactory::reset();
        parent::tearDown();
    }

    /**
     * @param string|null $stemsIn Language the index stems in; null for an index that does not
     * @param bool $providesStemming Whether the storage can say (a custom storage may not)
     */
    private function storage(?string $stemsIn, bool $providesStemming = true): StorageInterface
    {
        $class = $providesStemming ? RecordingStemmingStorage::class : RecordingStorage::class;
        $storage = new $class();
        $storage->stemsIn = $stemsIn;

        return $storage;
    }

    private function engine(StorageInterface $storage, array $config = []): SearchEngine
    {
        return new SearchEngine(
            $storage,
            new StandardAnalyzer(),
            'idx',
            array_merge(['min_score' => 0.0, 'enable_suggestions' => false, 'enable_synonyms' => false], $config)
        );
    }

    private function lastQuery(StorageInterface $storage): array
    {
        return end($storage->searches);
    }

    public function test_plain_query_has_stems_added_as_alternatives(): void
    {
        $storage = $this->storage('english');
        $this->engine($storage)->search(new SearchQuery('connected users'));

        $query = $this->lastQuery($storage);
        $this->assertSame('"connected users" OR NEAR(connected users, 10) OR connected OR users', $query['query']);
        $this->assertSame(
            '("connected users" OR NEAR(connected users, 10) OR connected OR users) OR _stems : connect OR _stems : user',
            $query['stem_query']
        );
        $this->assertSame(0.5, $query['stem_weight']);
    }

    public function test_the_count_gets_the_same_stem_query(): void
    {
        $storage = $this->storage('english');
        $engine = $this->engine($storage);
        $engine->search(new SearchQuery('connected'));
        $engine->count(new SearchQuery('connected'));

        $this->assertSame('(connected) OR _stems : connect', $storage->searches[0]['stem_query']);
        $this->assertSame($storage->searches[0]['stem_query'], $storage->counts[0]['stem_query']);
        $this->assertSame($storage->searches[0]['query'], $storage->counts[0]['query']);
    }

    public function test_correction_mode_groups_each_term_with_its_stem(): void
    {
        $storage = $this->storage('english');
        $this->engine($storage)->search((new SearchQuery('connected users'))->fuzzy(true));

        $query = $this->lastQuery($storage);
        $this->assertSame('connected users', $query['query']);
        $this->assertSame('(connected OR _stems : connect) AND (users OR _stems : user)', $query['stem_query']);
    }

    public function test_a_corrected_term_also_matches_the_stem_of_the_word_as_typed(): void
    {
        $storage = $this->storage('english');
        $storage->terms = ['rugs' => 2, 'running' => 1];
        $this->engine($storage, ['enable_fuzzy' => true])->search((new SearchQuery('runs'))->fuzzy(true));

        $query = $this->lastQuery($storage);
        $this->assertSame('rugs', $query['query']);
        $this->assertSame('(rugs OR _stems : rug OR _stems : run)', $query['stem_query']);
    }

    public function test_a_term_that_needed_no_correction_is_grouped_as_before(): void
    {
        $storage = $this->storage('english');
        $storage->terms = ['rugs' => 2, 'running' => 1];
        $this->engine($storage, ['enable_fuzzy' => true])->search((new SearchQuery('running rugs'))->fuzzy(true));

        $this->assertSame(
            '(running OR _stems : run) AND (rugs OR _stems : rug)',
            $this->lastQuery($storage)['stem_query']
        );
    }

    public function test_each_corrected_term_keeps_the_stem_of_its_own_typed_word(): void
    {
        $storage = $this->storage('english');
        $storage->terms = ['rugs' => 2, 'running' => 1, 'cats' => 1];
        $this->engine($storage, ['enable_fuzzy' => true])->search((new SearchQuery('cats runs'))->fuzzy(true));

        $this->assertSame(
            '(cats OR _stems : cat) AND (rugs OR _stems : rug OR _stems : run)',
            $this->lastQuery($storage)['stem_query']
        );
    }

    public function test_two_tokens_merged_before_correction_are_stemmed_as_the_merged_word(): void
    {
        $storage = $this->storage('english');
        $storage->terms = ['robocop' => 3];
        $this->engine($storage, ['enable_fuzzy' => true, 'enable_word_merge' => true])
            ->search((new SearchQuery('robo cop'))->fuzzy(true));

        $query = $this->lastQuery($storage);
        $this->assertSame('robocop', $query['query']);
        $this->assertSame('(robocop OR _stems : robocop)', $query['stem_query']);
    }

    public function test_a_term_without_a_stem_stays_as_it_is_in_a_group(): void
    {
        $storage = $this->storage('english');
        // 'x' is below the analyzer's minimum word length, so it has no stem
        $this->engine($storage)->search((new SearchQuery('connected x'))->fuzzy(true));

        $this->assertSame('(connected OR _stems : connect) AND (x)', $this->lastQuery($storage)['stem_query']);
    }

    public function test_expansion_mode_adds_stems_of_the_original_terms_only(): void
    {
        $storage = $this->storage('english');
        $engine = $this->engine($storage, ['fuzzy_correction_mode' => false]);
        $engine->search((new SearchQuery('connected'))->fuzzy(true));

        $query = $this->lastQuery($storage);
        // No indexed terms to vary the word with, so the raw query is the plain one
        $this->assertSame('(' . $query['query'] . ') OR _stems : connect', $query['stem_query']);
    }

    public function test_prefix_last_token_keeps_the_star_on_the_last_stem(): void
    {
        $storage = $this->storage('english');
        $this->engine($storage, ['prefix_last_token' => true])->search(new SearchQuery('users connecting'));

        $query = $this->lastQuery($storage);
        $this->assertStringContainsString('connecting*', $query['query']);
        $this->assertStringEndsWith(' OR _stems : user OR _stems : connect*', $query['stem_query']);
    }

    public function test_synonyms_and_fuzzy_variants_are_not_stemmed(): void
    {
        $storage = $this->storage('english');
        $engine = $this->engine($storage, ['enable_synonyms' => true, 'synonyms' => ['car' => ['automobile']]]);
        $engine->search(new SearchQuery('car'));

        $query = $this->lastQuery($storage);
        $this->assertStringContainsString('automobile', $query['query']);
        $this->assertSame('(' . $query['query'] . ') OR _stems : car', $query['stem_query']);
    }

    public function test_stems_are_escaped_like_terms(): void
    {
        $storage = $this->storage('english');
        $this->engine($storage)->search(new SearchQuery('order-100821 notes'));

        $query = $this->lastQuery($storage);
        $this->assertStringContainsString('OR _stems : "order-100821"', $query['stem_query']);
        $this->assertStringContainsString('OR _stems : note', $query['stem_query']);
    }

    public function test_query_language_decides_the_stems_and_the_stop_words(): void
    {
        $storage = $this->storage('english');
        $this->engine($storage)->search((new SearchQuery('les chansons'))->language('fr')->fuzzy(true));

        $this->assertSame('(chansons OR _stems : chanson)', $this->lastQuery($storage)['stem_query']);
        $this->assertSame('chansons', $this->lastQuery($storage)['query']);
    }

    public function test_the_index_language_is_used_when_the_query_has_none(): void
    {
        $storage = $this->storage('french');
        $this->engine($storage)->search((new SearchQuery('les chansons a'))->fuzzy(true));

        $query = $this->lastQuery($storage);
        // 'les' is a French stop word and 'a' is not; with no language on a plain index it would be the other way round
        $this->assertSame('chansons a', $query['query']);
        $this->assertSame('(chansons OR _stems : chanson) AND (a)', $query['stem_query']);
    }

    public function test_a_plain_index_gets_no_stem_query_and_keeps_english_stop_words(): void
    {
        $storage = $this->storage(null);
        $this->engine($storage)->search((new SearchQuery('les chansons a'))->fuzzy(true));

        $query = $this->lastQuery($storage);
        $this->assertSame('les chansons', $query['query']);
        $this->assertArrayNotHasKey('stem_query', $query);
        $this->assertArrayNotHasKey('stem_weight', $query);
    }

    public function test_a_storage_that_cannot_say_gets_no_stem_query(): void
    {
        $storage = $this->storage('english', false);
        $this->engine($storage)->search(new SearchQuery('connected'));

        $this->assertArrayNotHasKey('stem_query', $this->lastQuery($storage));
    }

    public function test_a_language_without_a_stemmer_gets_no_stem_query(): void
    {
        $storage = $this->storage('italian');
        $this->engine($storage)->search(new SearchQuery('gatti'));
        $this->assertArrayNotHasKey('stem_query', $this->lastQuery($storage));

        // until a stemmer is registered for it
        StemmerFactory::register('italian', FakeItalianStemmer::class, ['it']);
        $storage = $this->storage('italian');
        $this->engine($storage)->search(new SearchQuery('gatti'));
        $this->assertSame('(gatti) OR _stems : gatt', $this->lastQuery($storage)['stem_query']);
    }

    public function test_a_query_of_only_stop_words_never_reaches_storage(): void
    {
        $storage = $this->storage('english');
        $this->engine($storage)->search(new SearchQuery('the'));

        $this->assertSame([], $storage->searches);
    }

    public function test_facets_are_counted_over_the_same_stem_query(): void
    {
        $storage = $this->storage('english');
        $engine = $this->engine($storage);
        $engine->search((new SearchQuery('connected'))->facet('category'));

        $this->assertCount(2, $storage->searches);
        $this->assertSame('(connected) OR _stems : connect', $storage->searches[0]['stem_query']);
        $this->assertSame($storage->searches[0]['stem_query'], $storage->searches[1]['stem_query']);
    }

    public function test_stem_weight_comes_from_the_search_config(): void
    {
        $storage = $this->storage('english');
        $this->engine($storage, ['stem_weight' => 2.5])->search(new SearchQuery('connected'));

        $this->assertSame(2.5, $this->lastQuery($storage)['stem_weight']);
    }
}

class RecordingStorage implements StorageInterface
{
    public $stemsIn = null;
    /** @var array<string, int> The indexed terms typo correction chooses from */
    public $terms = [];
    public $searches = [];
    public $counts = [];

    public function connect(array $config): void
    {
    }

    public function disconnect(): void
    {
    }

    public function createIndex(string $name, array $options = []): void
    {
    }

    public function dropIndex(string $name): void
    {
    }

    public function indexExists(string $name): bool
    {
        return true;
    }

    public function insert(string $index, array $document): void
    {
    }

    public function insertBatch(string $index, array $documents): void
    {
    }

    public function update(string $index, string $id, array $document): void
    {
    }

    public function delete(string $index, string $id): void
    {
    }

    public function search(string $index, array $query): array
    {
        $this->searches[] = $query;

        return [];
    }

    public function count(string $index, array $query): int
    {
        $this->counts[] = $query;

        return 0;
    }

    public function getDocument(string $index, string $id): ?array
    {
        return null;
    }

    public function optimize(string $index): void
    {
    }

    public function getIndexStats(string $index): array
    {
        return [];
    }

    public function listIndices(): array
    {
        return [];
    }

    public function searchMultiple(array $indices, array $query): array
    {
        return [];
    }

    public function ensureSpatialTableExists(string $name): void
    {
    }

    public function getIndexedTerms(?string $indexName = null, int $minFrequency = 1, int $limit = 10000): array
    {
        return $this->terms;
    }
}

class RecordingStemmingStorage extends RecordingStorage implements ProvidesStemming
{
    public function stemmingFor(string $index): ?string
    {
        return $this->stemsIn;
    }
}

class FakeItalianStemmer implements \YetiSearch\Stemmer\StemmerInterface
{
    public function stem(string $word): string
    {
        return preg_replace('/[aeio]$/', '', $word);
    }

    public function getLanguage(): string
    {
        return 'it';
    }
}
