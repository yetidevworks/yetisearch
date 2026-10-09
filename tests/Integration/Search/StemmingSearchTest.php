<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Stemmer\StemmerFactory;
use YetiSearch\Stemmer\StemmerInterface;
use YetiSearch\Tests\Integration\StemmingTestCase;

/**
 * An index created with stemming finds a word by its stem: "connect" finds
 * "connected". It does so in every way an index can be stored.
 */
class StemmingSearchTest extends StemmingTestCase
{
    private function indexSamples(string $mode, array $config = [], array $indexOptions = []): \YetiSearch\YetiSearch
    {
        $search = $this->openSearch($mode, $config);
        $this->createIndex($search, $mode, $indexOptions);
        $search->indexBatch(self::INDEX, [
            ['id' => 'typed', 'content' => ['title' => 'Connect your devices', 'content' => 'How to connect a printer', 'category' => 'howto']],
            ['id' => 'past', 'content' => ['title' => 'Devices connected', 'content' => 'The printer was connected yesterday', 'category' => 'news']],
            ['id' => 'noun', 'content' => ['title' => 'A connection guide', 'content' => 'Fixing a broken connection', 'category' => 'howto']],
            ['id' => 'run', 'content' => ['title' => 'Running shoes', 'content' => 'He is running in the park', 'category' => 'news']],
            ['id' => 'other', 'content' => ['title' => 'Unrelated', 'content' => 'Nothing about plugs here', 'category' => 'news']],
        ]);
        $this->addFillers($search);

        return $search;
    }

    /** @dataProvider schemaModes */
    public function test_a_word_finds_the_forms_that_share_its_stem(string $mode): void
    {
        $search = $this->indexSamples($mode);

        $this->assertSame(['noun', 'past', 'typed'], $this->ids($search->search(self::INDEX, 'connect')));
        $this->assertSame(['noun', 'past', 'typed'], $this->ids($search->search(self::INDEX, 'connected')));
        $this->assertSame(['noun', 'past', 'typed'], $this->ids($search->search(self::INDEX, 'connection')));
        $this->assertSame(['run'], $this->ids($search->search(self::INDEX, 'runs')));
        $this->assertSame(['run'], $this->ids($search->search(self::INDEX, 'run')));
    }

    /** @dataProvider schemaModes */
    public function test_the_count_agrees_with_the_results(string $mode): void
    {
        $search = $this->indexSamples($mode);

        $results = $search->search(self::INDEX, 'connect');

        $this->assertSame(3, $results['total']);
        $this->assertCount(3, $results['results']);
    }

    /** @dataProvider schemaModes */
    public function test_an_exact_match_ranks_above_a_stem_only_match(string $mode): void
    {
        $search = $this->indexSamples($mode);

        $order = $this->orderedIds($search->search(self::INDEX, 'connect', ['fuzzy' => false]));

        $this->assertSame('typed', $order[0], 'The document with the word as typed comes first');
        $this->assertEqualsCanonicalizing(['past', 'noun'], array_slice($order, 1));

        // Typing the other form puts that document first
        $order = $this->orderedIds($search->search(self::INDEX, 'connected', ['fuzzy' => false]));
        $this->assertSame('past', $order[0]);
    }

    /** @dataProvider schemaModes */
    public function test_the_stems_column_is_weighed_by_stem_weight(string $mode): void
    {
        $search = $this->indexSamples($mode);
        $storage = $this->storage($search);

        $default = $storage->search(self::INDEX, [
            'query' => 'x', 'stem_query' => '(connect) OR _stems : connect', '_debug_sql' => true,
        ]);
        $weighted = $storage->search(self::INDEX, [
            'query' => 'x', 'stem_query' => '(connect) OR _stems : connect', 'stem_weight' => 2, '_debug_sql' => true,
        ]);

        $this->assertStringContainsString(', 0.5000) as rank', $default['_sql']);
        $this->assertStringContainsString(', 2.0000) as rank', $weighted['_sql']);
    }

    /** @dataProvider schemaModes */
    public function test_a_plain_index_is_searched_as_it_always_was(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => false]);
        $search->indexBatch(self::INDEX, [
            ['id' => 'typed', 'content' => ['title' => 'Connect your devices', 'content' => 'How to connect a printer']],
            ['id' => 'past', 'content' => ['title' => 'Devices connected', 'content' => 'The printer was connected yesterday']],
        ]);

        $this->assertSame(['typed'], $this->ids($search->search(self::INDEX, 'connect', ['fuzzy' => false])));
        $this->assertSame(['past'], $this->ids($search->search(self::INDEX, 'connected', ['fuzzy' => false])));
        $this->assertNotContains('_stems', $this->columns($search, self::INDEX . '_fts'));
        $this->assertNotContains('_stems', $this->columns($search, self::INDEX));
        $this->assertNull($this->storage($search)->stemmingFor(self::INDEX));
    }

    /** @dataProvider schemaModes */
    public function test_a_plain_index_keeps_english_stop_words_whatever_its_language_option(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => false, 'language' => 'fr']);
        $search->index(self::INDEX, ['id' => 'a', 'content' => ['title' => 'les chansons', 'content' => 'les chansons']]);

        // 'les' is not an English stop word, so it stays in the query and matches
        $this->assertSame(['a'], $this->ids($search->search(self::INDEX, 'les', ['fuzzy' => false])));
    }

    /** @dataProvider schemaModes */
    public function test_a_document_in_french_is_found_by_the_query_language(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode);
        $search->indexBatch(self::INDEX, [
            ['id' => 'fr', 'language' => 'fr', 'content' => ['title' => 'Les chansons', 'content' => 'Des chansons populaires']],
            ['id' => 'en', 'language' => 'en', 'content' => ['title' => 'Songs', 'content' => 'Popular songs']],
        ]);
        $this->addFillers($search);

        $this->assertSame(['fr'], $this->ids($search->search(self::INDEX, 'chanson', ['language' => 'fr'])));
        // The language is also a filter on the documents
        $this->assertSame([], $this->ids($search->search(self::INDEX, 'chanson', ['language' => 'en'])));
    }

    /** @dataProvider schemaModes */
    public function test_the_index_language_is_used_for_documents_and_queries_without_one(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['language' => 'fr']);
        $search->indexBatch(self::INDEX, [
            ['id' => 'a', 'content' => ['title' => 'Les chansons', 'content' => 'Des chansons populaires']],
            ['id' => 'b', 'content' => ['title' => 'Musique', 'content' => 'Une belle chanson']],
        ]);
        $this->addFillers($search);

        $this->assertSame(['a', 'b'], $this->ids($search->search(self::INDEX, 'chanson', ['fuzzy' => false])));
        $this->assertSame(['a', 'b'], $this->ids($search->search(self::INDEX, 'chansons', ['fuzzy' => false])));
        // 'les' is a French stop word, so a query of it and a word is a query of the word
        $this->assertSame(['a', 'b'], $this->ids($search->search(self::INDEX, 'les chanson', ['fuzzy' => false])));
        $this->assertSame('french', $this->storage($search)->stemmingFor(self::INDEX));
    }

    /** @dataProvider schemaModes */
    public function test_correction_mode_needs_every_word_but_accepts_either_form(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode);
        $search->indexBatch(self::INDEX, [
            ['id' => 'both', 'content' => ['title' => 'Users connected', 'content' => 'Users were connected']],
            ['id' => 'users', 'content' => ['title' => 'Users', 'content' => 'Only users here']],
            ['id' => 'connect', 'content' => ['title' => 'Connect', 'content' => 'Only connecting here']],
        ]);
        $this->addFillers($search);

        $results = $search->search(self::INDEX, 'user connect', ['fuzzy' => true]);

        $this->assertSame(['both'], $this->ids($results));
        $this->assertSame(1, $results['total']);
    }

    /** @dataProvider schemaModes */
    public function test_a_corrected_word_also_finds_what_the_word_as_typed_finds(string $mode): void
    {
        $search = $this->openSearch($mode, ['search' => ['enable_fuzzy' => true, 'fuzzy_correction_mode' => true]]);
        $this->createIndex($search, $mode);
        $search->indexBatch(self::INDEX, [
            ['id' => 'running', 'content' => ['title' => 'Marathon', 'content' => 'He loves running']],
            ['id' => 'rugs', 'content' => ['title' => 'Carpets', 'content' => 'Persian rugs']],
        ]);
        $this->addFillers($search);

        // 'runs' is not in the index, and 'rugs' is one letter from it
        $plain = $this->ids($search->search(self::INDEX, 'runs', ['fuzzy' => false]));
        $fuzzy = $this->ids($search->search(self::INDEX, 'runs', ['fuzzy' => true]));

        $this->assertSame(['running'], $plain);
        $this->assertSame(['rugs', 'running'], $fuzzy, 'Fuzzy finds what the word typed finds, and the correction too');
    }

    /** @dataProvider schemaModes */
    public function test_a_prefix_on_the_last_word_reaches_stems_that_begin_with_it(string $mode): void
    {
        $config = ['search' => ['prefix_last_token' => true]];
        $search = $this->openSearch($mode, $config);
        $this->createIndex($search, $mode);
        $search->indexBatch(self::INDEX, [
            ['id' => 'studies', 'content' => ['title' => 'Many studies', 'content' => 'Careful studies']],
            ['id' => 'studios', 'content' => ['title' => 'Film studios', 'content' => 'Big studios']],
        ]);
        $this->addFillers($search);

        // The stem of 'studying' is 'studi', which 'studi*' reaches in the stems of 'studies' and 'studios'
        $this->assertSame(['studies', 'studios'], $this->ids($search->search(self::INDEX, 'studying', ['fuzzy' => false])));
        // Without the prefix only the documents with that very stem are found
        $this->assertSame(['studies'], $this->ids($search->search(self::INDEX, 'studying', ['fuzzy' => false, 'prefix_last_token' => false, 'bypass_cache' => true])));

    }

    /** @dataProvider schemaModes */
    public function test_a_phrase_still_matches_and_ranks_first(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode);
        $search->indexBatch(self::INDEX, [
            ['id' => 'phrase', 'content' => ['title' => 'Connected users', 'content' => 'connected users everywhere']],
            ['id' => 'apart', 'content' => ['title' => 'Notes', 'content' => 'connected devices and users']],
        ]);
        $this->addFillers($search);

        $order = $this->orderedIds($search->search(self::INDEX, '"connected users"', ['fuzzy' => false]));

        $this->assertSame(['phrase', 'apart'], $order);
    }

    /** @dataProvider schemaModes */
    public function test_facet_counts_agree_with_the_results(string $mode): void
    {
        $search = $this->indexSamples($mode);

        $results = $search->search(self::INDEX, 'connect', ['facets' => ['category' => []]]);

        $this->assertSame(3, $results['total']);
        $counts = [];
        foreach ($results['facets']['category'] as $bucket) {
            $counts[$bucket['value']] = $bucket['count'];
        }
        $this->assertSame(['howto' => 2, 'news' => 1], $counts);
    }

    /** @dataProvider schemaModes */
    public function test_a_stemming_and_a_plain_index_can_be_searched_together(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode);
        $this->createIndex($search, $mode, ['stemming' => false], 'plain');
        $search->index(self::INDEX, ['id' => 's1', 'content' => ['title' => 'Devices connected', 'content' => 'The printer was connected']]);
        $search->index('plain', ['id' => 'p1', 'content' => ['title' => 'Devices connect', 'content' => 'The printer will connect']]);
        $search->index('plain', ['id' => 'p2', 'content' => ['title' => 'Devices connected', 'content' => 'The printer was connected']]);

        $results = $search->multiSearch([self::INDEX, 'plain'], 'connect');

        $found = [];
        foreach ($results['results'] as $result) {
            $found[] = $result['_index'] . ':' . $result['id'];
        }
        sort($found);
        // The plain index answers for the word as typed, the stemming one for its stems
        $this->assertSame(['plain:p1', self::INDEX . ':s1'], $found);
        $this->assertSame([self::INDEX, 'plain'], $results['indices_searched']);
        $this->assertSame(2, $results['total']);

        $same = $search->searchMultiple([self::INDEX, 'plain'], 'connect');
        $this->assertSame($results['total'], $same['total']);
    }

    /** @dataProvider schemaModes */
    public function test_multi_search_does_not_stem_when_no_index_stems(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => false], 'plain');
        $this->createIndex($search, $mode, ['stemming' => false], 'plain2');
        $search->index('plain', ['id' => 'p1', 'content' => ['title' => 'Devices connected', 'content' => 'The printer was connected']]);
        $search->index('plain2', ['id' => 'p2', 'content' => ['title' => 'Devices connected', 'content' => 'The printer was connected']]);
        CountingStemmer::$calls = 0;
        StemmerFactory::register('english', CountingStemmer::class);

        $results = $search->multiSearch(['plain', 'plain2', 'missing'], 'connected');

        $this->assertSame(2, $results['total']);
        $this->assertSame(0, CountingStemmer::$calls, 'No stemmer ran for indexes that do not stem');
    }

    /** @dataProvider schemaModes */
    public function test_multi_search_stems_when_one_of_the_indexes_stems(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode);
        $this->createIndex($search, $mode, ['stemming' => false], 'plain');
        $search->index(self::INDEX, ['id' => 's1', 'content' => ['title' => 'Devices connected', 'content' => 'The printer was connected']]);
        $search->index('plain', ['id' => 'p1', 'content' => ['title' => 'Devices connected', 'content' => 'The printer was connected']]);
        CountingStemmer::$calls = 0;
        StemmerFactory::register('english', CountingStemmer::class);

        $search->multiSearch(['plain', self::INDEX], 'connected');

        $this->assertGreaterThan(0, CountingStemmer::$calls);
    }

    /** @dataProvider schemaModes */
    public function test_multi_search_takes_its_language_from_its_options(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode);
        $search->index(self::INDEX, ['id' => 'fr', 'language' => 'fr', 'content' => ['title' => 'Chansons', 'content' => 'Des chansons']]);

        $this->assertSame(1, $search->multiSearch([self::INDEX], 'chanson', ['language' => 'fr'])['total']);
        $this->assertSame(0, $search->multiSearch([self::INDEX], 'chanson', ['language' => 'en'])['total']);
    }

    /** @dataProvider schemaModes */
    public function test_a_stem_query_cannot_be_smuggled_in_through_multi_search_options(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode);
        $search->index(self::INDEX, ['id' => 'a', 'content' => ['title' => 'Alpha', 'content' => 'something']]);

        $results = $search->multiSearch([self::INDEX], 'nothingmatches', ['stem_query' => 'alpha']);

        $this->assertSame(0, $results['total']);
    }

    /** @dataProvider schemaModes */
    public function test_a_registered_stemmer_is_used_when_indexing_and_searching(string $mode): void
    {
        StemmerFactory::register('italian', StripVowelsStemmer::class, ['it', 'italiano']);
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['language' => 'it']);
        $search->indexBatch(self::INDEX, [
            ['id' => 'gatto', 'content' => ['title' => 'Il gatto', 'content' => 'un gatto nero']],
            ['id' => 'gatti', 'language' => 'it', 'content' => ['title' => 'I gatti', 'content' => 'due gatti neri']],
        ]);
        $this->addFillers($search);

        // The index language stems the document without one, and the query with none
        $this->assertSame(['gatti', 'gatto'], $this->ids($search->search(self::INDEX, 'gatte', ['fuzzy' => false])));
        // A language that is an alias of the registered one stems the query
        $this->assertSame(['gatti'], $this->ids($search->search(self::INDEX, 'gatto', ['fuzzy' => false, 'language' => 'it'])));
        $this->assertSame('italian', $this->storage($search)->stemmingFor(self::INDEX));
    }

    /** @dataProvider schemaModes */
    public function test_a_replaced_built_in_stemmer_is_the_one_used(string $mode): void
    {
        StemmerFactory::register('english', StripVowelsStemmer::class);
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode);
        $search->index(self::INDEX, ['id' => 'a', 'content' => ['title' => 'Sample', 'content' => 'a sample text']]);
        $this->addFillers($search);

        // Both lose their last vowel, which the English stemmer would not do to 'sample'
        $this->assertSame(['a'], $this->ids($search->search(self::INDEX, 'sampl', ['fuzzy' => false])));
        $this->assertSame(['a'], $this->ids($search->search(self::INDEX, 'sampla', ['fuzzy' => false])));
    }

    /** @dataProvider schemaModes */
    public function test_a_language_without_a_stemmer_gets_no_stems_and_still_matches_exactly(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode);
        $search->indexBatch(self::INDEX, [
            ['id' => 'it', 'language' => 'it', 'content' => ['title' => 'Il gatto', 'content' => 'gatti neri correvano']],
            ['id' => 'en', 'language' => 'en', 'content' => ['title' => 'The cat', 'content' => 'black cats were running']],
        ]);
        $this->addFillers($search);

        $this->assertSame(['it'], $this->ids($search->search(self::INDEX, 'gatti', ['fuzzy' => false, 'language' => 'it'])));
        // Nothing is stemmed in Italian, so a different form does not find it
        $this->assertSame([], $this->ids($search->search(self::INDEX, 'gatta', ['fuzzy' => false, 'language' => 'it'])));
        $this->assertSame(['en'], $this->ids($search->search(self::INDEX, 'cat', ['fuzzy' => false, 'language' => 'en'])));

        $stems = $this->stemsOf($search, $mode, ['it', 'en']);
        $this->assertSame('', $stems['it']);
        $this->assertNotSame('', $stems['en']);
    }

    /** @dataProvider schemaModes */
    public function test_a_word_found_by_its_stem_is_highlighted(string $mode): void
    {
        $search = $this->indexSamples($mode);

        $results = $search->search(self::INDEX, 'connect', ['highlight' => true]);

        $byId = [];
        foreach ($results['results'] as $result) {
            $byId[$result['id']] = $result;
        }
        $past = json_encode($byId['past']['highlights'], JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Devices <mark>connected</mark>', $past);
        $this->assertStringContainsString('The printer was <mark>connected</mark> yesterday', $past);
        $this->assertStringContainsString('<mark>connection</mark>', json_encode($byId['noun']['highlights'], JSON_UNESCAPED_SLASHES));
        // A word with no shared stem is left alone
        $this->assertStringNotContainsString('<mark>printer', $past);
    }

    /** @dataProvider schemaModes */
    public function test_highlights_use_the_language_of_the_result(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode);
        $search->index(self::INDEX, ['id' => 'fr', 'language' => 'fr', 'content' => ['title' => 'Mes chansons', 'content' => 'Une chanson et des chansons']]);
        $this->addFillers($search);

        $results = $search->search(self::INDEX, 'chanson', ['language' => 'fr']);

        $highlights = $results['results'][0]['highlights'];
        // Marked once, though the word is also the term with an 's'
        $this->assertSame('Une <mark>chanson</mark> et des <mark>chansons</mark>', $highlights['content']);
        $this->assertSame('Mes <mark>chansons</mark>', $highlights['title']);
    }

    /** @dataProvider schemaModes */
    public function test_a_search_option_sets_the_stem_weight(string $mode): void
    {
        $search = $this->indexSamples($mode);
        $withWeight = $search->search(self::INDEX, 'connect', ['fuzzy' => false, 'stem_weight' => 0.0]);

        // Weighing the stems at nothing still finds the documents that have them
        $this->assertSame(['noun', 'past', 'typed'], $this->ids($withWeight));
    }

    /** @dataProvider schemaModes */
    public function test_a_held_result_is_not_returned_for_another_stem_weight(string $mode): void
    {
        $search = $this->indexSamples($mode);
        $scoreOf = function (float $weight) use ($search): float {
            $results = $search->search(self::INDEX, 'connected', ['fuzzy' => false, 'stem_weight' => $weight, 'min_score' => 0.0]);
            foreach ($results['results'] as $result) {
                if ($result['id'] === 'typed') {
                    return $result['score'];
                }
            }
            $this->fail('The document that has the word as a stem was not found');
        };

        $none = $scoreOf(0.0);
        $full = $scoreOf(1.0);
        $this->assertGreaterThan($none, $full, 'The second search was given the first one\'s held result');
        $this->assertSame($none, $scoreOf(0.0), 'The same search is still answered the same');
        $this->assertSame($full, $scoreOf(1.0));
    }

    /** @dataProvider schemaModes */
    public function test_a_held_result_follows_a_stem_weight_given_against_the_configured_one(string $mode): void
    {
        $search = $this->indexSamples($mode, ['search' => ['stem_weight' => 0.0]]);

        $first = $search->search(self::INDEX, 'connected', ['fuzzy' => false]);
        $second = $search->search(self::INDEX, 'connected', ['fuzzy' => false, 'stem_weight' => 2.0]);

        $this->assertNotEquals(
            array_column($first['results'], 'score', 'id'),
            array_column($second['results'], 'score', 'id')
        );
    }

    /** @dataProvider schemaModes */
    public function test_a_held_result_is_not_returned_for_other_runtime_options(string $mode): void
    {
        $search = $this->indexSamples($mode);

        $all = $search->search(self::INDEX, 'connect', ['fuzzy' => false, 'min_score' => 0.0]);
        $none = $search->search(self::INDEX, 'connect', ['fuzzy' => false, 'min_score' => 1.0e9]);
        $again = $search->search(self::INDEX, 'connect', ['min_score' => 0.0, 'fuzzy' => false]);

        $this->assertCount(3, $all['results']);
        $this->assertSame([], $none['results'], 'A search with another min_score is its own search');
        $this->assertSame($this->ids($all), $this->ids($again), 'The same options in another order are the same search');
    }

    /**
     * What the FTS and content tables hold as stems for the given documents.
     *
     * @return array<string, string>
     */
    private function stemsOf(\YetiSearch\YetiSearch $search, string $mode, array $ids): array
    {
        $pdo = $this->pdo($search);
        $stems = [];
        foreach ($ids as $id) {
            if ($mode === 'external') {
                $stems[$id] = (string)$pdo->query("SELECT _stems FROM " . self::INDEX . " WHERE id = '{$id}'")->fetchColumn();
            } else {
                $stems[$id] = (string)$pdo->query("SELECT _stems FROM " . self::INDEX . "_fts WHERE id = '{$id}'")->fetchColumn();
            }
        }

        return $stems;
    }
}

/** Stems like the built-in English stemmer, and counts how often it is asked to */
class CountingStemmer implements StemmerInterface
{
    public static int $calls = 0;

    public function stem(string $word): string
    {
        self::$calls++;

        return (new \YetiSearch\Stemmer\Languages\EnglishStemmer())->stem($word);
    }

    public function getLanguage(): string
    {
        return 'english';
    }
}

class StripVowelsStemmer implements StemmerInterface
{
    public function stem(string $word): string
    {
        return preg_replace('/[aeio]$/', '', $word);
    }

    public function getLanguage(): string
    {
        return 'xx';
    }
}
