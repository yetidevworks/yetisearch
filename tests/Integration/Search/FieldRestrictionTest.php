<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Tests\Fixtures\FakeEmbeddingProvider;
use YetiSearch\Tests\Integration\StemmingTestCase;
use YetiSearch\YetiSearch;

/**
 * A search restricted to some fields (the `fields` option, SearchQuery::inFields()) finds the
 * documents with a word of the query in one of them, and its total and facets count those,
 * in each way an index is stored, with and without stems.
 */
class FieldRestrictionTest extends StemmingTestCase
{
    private function open(string $mode, bool $stemming, array $config = []): YetiSearch
    {
        $search = $this->openSearch($mode, $config + ['search' => ['enable_fuzzy' => true, 'min_score' => 0.0]]);
        $this->createIndex($search, $mode, ['stemming' => $stemming]);
        $search->indexBatch(self::INDEX, [
            ['id' => 'title', 'content' => ['title' => 'Running shoes', 'content' => 'Light and fast', 'tags' => 'sport']],
            ['id' => 'body', 'content' => ['title' => 'Trail guide', 'content' => 'Shoes for running on rock', 'tags' => 'sport']],
            ['id' => 'tags', 'content' => ['title' => 'Socks', 'content' => 'Warm wool', 'tags' => 'running, Café']],
            ['id' => 'none', 'content' => ['title' => 'Hats', 'content' => 'Sun hats', 'tags' => 'sport']],
        ]);

        return $search;
    }

    public function modesAndStemming(): array
    {
        $cases = [];
        foreach ($this->schemaModes() as $name => [$mode]) {
            $cases[$name] = [$mode, false];
            $cases[$name . ', stemming'] = [$mode, true];
        }

        return $cases;
    }

    /**
     * @dataProvider modesAndStemming
     */
    public function test_only_the_documents_with_the_word_in_the_fields_are_found(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming);

        $all = $search->search(self::INDEX, 'running', ['fuzzy' => false]);
        $this->assertSame(['body', 'tags', 'title'], $this->ids($all));

        $title = $search->search(self::INDEX, 'running', ['fuzzy' => false, 'fields' => ['title']]);
        $this->assertSame(['title'], $this->ids($title));
        $this->assertSame(1, $title['total']);

        $titleOrTags = $search->search(self::INDEX, 'running', ['fuzzy' => false, 'fields' => ['title', 'tags']]);
        $this->assertSame(['tags', 'title'], $this->ids($titleOrTags));
        $this->assertSame(2, $titleOrTags['total']);

        $this->assertSame([], $search->search(self::INDEX, 'running', ['fuzzy' => false, 'fields' => ['nothing']])['results']);
    }

    /**
     * @dataProvider modesAndStemming
     */
    public function test_any_word_of_the_query_in_the_fields_will_do(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming);

        // 'shoes' is in the body of 'body', 'rock' too, but neither in its title
        $results = $search->search(self::INDEX, 'shoes rock', ['fuzzy' => false, 'fields' => ['title']]);

        $this->assertSame(['title'], $this->ids($results));
    }

    /**
     * @dataProvider schemaModes
     */
    public function test_a_stem_counts_on_an_index_that_stems(string $mode): void
    {
        $search = $this->open($mode, true);

        // 'runs' is in no document, its stem 'run' is in all three
        $this->assertSame(['title'], $this->ids($search->search(self::INDEX, 'runs', ['fuzzy' => false, 'fields' => ['title']])));
    }

    /**
     * @dataProvider schemaModes
     */
    public function test_corrections_prefixes_and_accents_count(string $mode): void
    {
        $search = $this->open($mode, false);

        $this->assertSame(['title'], $this->ids($search->search(self::INDEX, 'runnign', ['fuzzy' => true, 'fields' => ['title']])));
        $this->assertSame(['tags'], $this->ids($search->search(self::INDEX, 'cafe', ['fuzzy' => false, 'fields' => ['tags']])));

        $prefix = $this->open($mode, false, ['search' => ['prefix_last_token' => true]]);
        $this->assertSame(['title'], $this->ids($prefix->search(self::INDEX, 'runn', ['fuzzy' => false, 'fields' => ['title']])));
    }

    /**
     * @dataProvider schemaModes
     */
    public function test_facets_count_the_documents_found(string $mode): void
    {
        $search = $this->open($mode, false);

        $results = $search->search(self::INDEX, 'running', ['fuzzy' => false, 'fields' => ['title', 'tags'], 'facets' => ['tags']]);

        $this->assertSame(
            [['value' => 'running, Café', 'count' => 1], ['value' => 'sport', 'count' => 1]],
            $this->sortedFacet($results['facets']['tags'])
        );
    }

    private function sortedFacet(array $facet): array
    {
        usort($facet, function (array $a, array $b): int {
            return strcmp((string)$a['value'], (string)$b['value']);
        });

        return $facet;
    }

    /**
     * @dataProvider schemaModes
     */
    public function test_words_are_read_as_the_index_reads_them(string $mode): void
    {
        $search = $this->openSearch($mode, ['search' => ['enable_fuzzy' => false, 'min_score' => 0.0]]);
        $this->createIndex($search, $mode, ['stemming' => false]);
        $search->indexBatch(self::INDEX, [
            ['id' => 'sigma', 'content' => ['title' => 'Οδος', 'content' => 'x']],
            ['id' => 'tonos', 'content' => ['title' => 'Ελλάδα', 'content' => 'ελλαδα']],
            ['id' => 'hindi', 'content' => ['title' => 'हिन्दी भाषा', 'content' => 'x']],
            ['id' => 'accent', 'content' => ['title' => 'Crème brûlée', 'content' => 'x']],
            ['id' => 'vietnamese', 'content' => ['title' => 'Tiếng Việt', 'content' => 'x']],
        ]);
        $title = function (string $query) use ($search): array {
            return $this->ids($search->search(self::INDEX, $query, ['fuzzy' => false, 'fields' => ['title']]));
        };

        // Final and medial sigma are one letter; a Latin letter loses one accent, not two
        $this->assertSame(['sigma'], $title('οδοσ'));
        $this->assertSame(['accent'], $title('creme brulee'));
        $this->assertSame(['vietnamese'], $title('tiếng'));
        // Greek keeps its accents, so 'ελλαδα' is in the content of 'tonos' and not its title
        $this->assertSame(['tonos'], $this->ids($search->search(self::INDEX, 'ελλαδα', ['fuzzy' => false])));
        $this->assertSame([], $title('ελλαδα'));
        // A combining vowel sign ends a word: 'न' is a word of हिन्दी
        $this->assertSame(['hindi'], $title('न'));
    }

    public function test_a_match_by_meaning_alone_is_not_one_in_the_fields(): void
    {
        $search = $this->openSearch('external', ['search' => ['enable_fuzzy' => false, 'cache_ttl' => 0, 'min_score' => 0.0]]);
        $this->createIndex($search, 'external', ['stemming' => false]);
        $search->indexBatch(self::INDEX, [
            ['id' => 'cars', 'content' => ['title' => 'Choosing your first car', 'content' => 'A sedan: what to look for.']],
            ['id' => 'auto', 'content' => ['title' => 'Automobile guide', 'content' => 'x']],
        ]);
        $search->setEmbeddingProvider(new FakeEmbeddingProvider());
        $search->embedPending(self::INDEX);

        $this->assertSame(['auto', 'cars'], $this->ids($search->search(self::INDEX, 'automobile')));
        $restricted = $search->search(self::INDEX, 'automobile', ['fields' => ['title']]);
        $this->assertSame(['auto'], $this->ids($restricted));
        $this->assertSame(1, $restricted['total']);
    }

    public function test_the_two_pass_search_keeps_the_restriction(): void
    {
        $search = $this->open('external', false, ['search' => ['two_pass_search' => true, 'enable_fuzzy' => false, 'min_score' => 0.0]]);

        // The first pass searches titles; 'title' has running in its title only
        $this->assertSame(['tags'], $this->ids($search->search(self::INDEX, 'running', ['fuzzy' => false, 'fields' => ['tags']])));
    }
}
