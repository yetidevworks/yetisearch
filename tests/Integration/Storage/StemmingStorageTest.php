<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Stemmer\StemmerFactory;
use YetiSearch\Stemmer\StemmerInterface;
use YetiSearch\Tests\Integration\StemmingTestCase;

/**
 * What an index that stems keeps in its tables, and that every way of writing to
 * it keeps the stems in step with the text.
 */
class StemmingStorageTest extends StemmingTestCase
{
    private function doc(string $id, string $content, array $extra = []): array
    {
        return ['id' => $id, 'content' => ['title' => ucfirst($id), 'content' => $content]] + $extra;
    }

    private function stemmingSearch(string $mode, array $options = []): \YetiSearch\YetiSearch
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, $options);

        return $search;
    }

    private function found(\YetiSearch\YetiSearch $search, string $query, array $options = []): array
    {
        return $this->ids($search->search(self::INDEX, $query, $options + ['fuzzy' => false]));
    }

    // ------------------------------------------------------------------
    // What gets created
    // ------------------------------------------------------------------

    /** @dataProvider schemaModes */
    public function test_the_stems_column_is_where_the_schema_puts_it(string $mode): void
    {
        $search = $this->stemmingSearch($mode);

        $fts = $this->columns($search, self::INDEX . '_fts');
        $this->assertSame('_stems', end($fts), 'The stems column comes after the raw columns');
        $this->assertSame($mode === 'external', in_array('_stems', $this->columns($search, self::INDEX), true));
    }

    /** @dataProvider schemaModes */
    public function test_settings_are_kept_in_the_meta_table(string $mode): void
    {
        $search = $this->stemmingSearch($mode, ['language' => ' fr ']);
        $meta = $this->pdo($search)->query('SELECT key, value FROM ' . self::INDEX . '_meta')->fetchAll(\PDO::FETCH_KEY_PAIR);

        $this->assertSame('1', $meta['stemming']);
        $this->assertSame('fr', $meta['stemming_language']);
        $this->assertSame('french', $this->storage($search)->stemmingFor(self::INDEX));
    }

    /** @dataProvider schemaModes */
    public function test_an_index_without_a_language_stems_in_english(string $mode): void
    {
        $search = $this->stemmingSearch($mode);

        $this->assertSame('english', $this->storage($search)->stemmingFor(self::INDEX));
    }

    /** @dataProvider schemaModes */
    public function test_the_indexer_config_creates_a_stemming_index(string $mode): void
    {
        $search = $this->openSearch($mode, ['indexer' => ['stemming' => true, 'language' => 'fr']]);
        $this->createdIndexes[] = self::INDEX;
        // Created by the first document, as an index is when nothing created it before
        $search->index(self::INDEX, $this->doc('a', 'des chansons populaires'));

        $this->assertSame('french', $this->storage($search)->stemmingFor(self::INDEX));
        $this->assertSame(['a'], $this->found($search, 'chanson'));
    }

    public function test_a_field_named_stems_is_refused_on_a_stemming_index(): void
    {
        $search = $this->openSearch('multi');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('_stems');
        $search->createIndex(self::INDEX, [
            'stemming' => true,
            'fields' => ['title' => ['boost' => 2.0], '_stems' => ['boost' => 1.0]],
        ]);
    }

    public function test_a_stemming_index_cannot_have_an_fts_detail_of_none(): void
    {
        $search = $this->openSearch('multi');

        $this->expectException(\InvalidArgumentException::class);
        $search->createIndex(self::INDEX, ['stemming' => true, 'fts' => ['detail' => 'none']]);
    }

    /** @dataProvider schemaModes */
    public function test_creating_an_existing_plain_index_again_does_not_start_stemming_it(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => false]);
        $search->index(self::INDEX, $this->doc('a', 'they connected the printer'));

        $this->storage($search)->createIndex(self::INDEX, ['stemming' => true, 'language' => 'fr']);

        $this->assertNull($this->storage($search)->stemmingFor(self::INDEX));
        $this->assertNotContains('_stems', $this->columns($search, self::INDEX . '_fts'));
        $search->index(self::INDEX, $this->doc('b', 'a second one'));
        $this->assertSame([], $this->found($search, 'connect'));
        $this->assertSame(['a'], $this->found($search, 'connected'));
    }

    /** @dataProvider schemaModes */
    public function test_creating_an_existing_stemming_index_again_keeps_its_settings(string $mode): void
    {
        $search = $this->stemmingSearch($mode, ['language' => 'fr']);
        $search->index(self::INDEX, $this->doc('a', 'des chansons', ['language' => 'fr']));

        $this->storage($search)->createIndex(self::INDEX, ['stemming' => false, 'language' => 'de']);

        $this->assertSame('french', $this->storage($search)->stemmingFor(self::INDEX));
        $this->assertContains('_stems', $this->columns($search, self::INDEX . '_fts'));
        $this->assertSame(['a'], $this->found($search, 'chanson'));
    }

    /** @dataProvider schemaModes */
    public function test_dropping_an_index_forgets_its_stemming(string $mode): void
    {
        $search = $this->stemmingSearch($mode);
        $this->assertSame('english', $this->storage($search)->stemmingFor(self::INDEX));

        $search->dropIndex(self::INDEX);
        $this->createIndex($search, $mode, ['stemming' => false]);

        $this->assertNull($this->storage($search)->stemmingFor(self::INDEX));
        $this->assertNotContains('_stems', $this->columns($search, self::INDEX . '_fts'));
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /** @dataProvider schemaModes */
    public function test_updating_a_document_drops_its_old_stems(string $mode): void
    {
        $search = $this->stemmingSearch($mode);
        $search->index(self::INDEX, $this->doc('a', 'the dogs were running quickly'));
        $search->index(self::INDEX, $this->doc('b', 'a bird in the sky'));
        $this->assertSame(['a'], $this->found($search, 'runs'));

        $search->update(self::INDEX, $this->doc('a', 'the cats were walking slowly'));

        $this->assertSame([], $this->found($search, 'runs'), 'The old stem still finds the document');
        $this->assertSame([], $this->found($search, 'running'));
        $this->assertSame([], $this->found($search, 'dog'));
        $this->assertSame(['a'], $this->found($search, 'walks'));
        $this->assertSame(['a'], $this->found($search, 'cat'));
        $terms = $this->vocabulary($search);
        foreach (['run', 'running', 'dog', 'dogs', 'quickli', 'quickly'] as $old) {
            $this->assertNotContains($old, $terms);
        }
        $this->assertContains('walk', $terms);
        $this->assertIntegrity($search);
    }

    /** @dataProvider schemaModes */
    public function test_a_batch_that_repeats_an_id_keeps_only_the_last_version(string $mode): void
    {
        $search = $this->stemmingSearch($mode);
        $search->indexBatch(self::INDEX, [
            $this->doc('a', 'the dogs were running'),
            $this->doc('b', 'a bird in the sky'),
        ]);
        $search->indexBatch(self::INDEX, [
            $this->doc('a', 'the cats were walking'),
            $this->doc('a', 'the fish were swimming'),
        ]);

        $this->assertSame([], $this->found($search, 'runs'));
        $this->assertSame([], $this->found($search, 'walks'));
        $this->assertSame(['a'], $this->found($search, 'swims'));
        $this->assertSame(['a'], $this->found($search, 'fish'));
        $this->assertIntegrity($search);
    }

    /** @dataProvider schemaModes */
    public function test_deleting_a_document_drops_its_stems(string $mode): void
    {
        $search = $this->stemmingSearch($mode);
        $search->index(self::INDEX, $this->doc('a', 'the dogs were running'));
        $search->index(self::INDEX, $this->doc('b', 'a bird in the sky'));

        $search->delete(self::INDEX, 'a');

        $this->assertSame([], $this->found($search, 'runs'));
        $this->assertSame(['b'], $this->found($search, 'birds'));
        $this->assertNotContains('run', $this->vocabulary($search));
        $this->assertIntegrity($search);
    }

    /** @dataProvider schemaModes */
    public function test_deleting_by_id_prefix_drops_the_stems_of_those_documents(string $mode): void
    {
        foreach ([true, false] as $rebuild) {
            $search = $this->openSearch($mode);
            $name = 'prefixed' . ($rebuild ? '1' : '0');
            $this->createIndex($search, $mode, [], $name);
            $search->indexBatch($name, [
                $this->doc('page#chunk0', 'the dogs were running'),
                $this->doc('page#chunk1', 'cats are walking'),
                $this->doc('other#chunk0', 'a bird in the sky'),
            ]);

            $this->assertSame(2, $search->deleteByIdPrefix($name, 'page#', $rebuild));

            $found = fn(string $q) => $this->ids($search->search($name, $q, ['fuzzy' => false]));
            $this->assertSame([], $found('runs'), "rebuild=$rebuild");
            $this->assertSame([], $found('walks'), "rebuild=$rebuild");
            $this->assertSame(['other#chunk0'], $found('birds'), "rebuild=$rebuild");
            $terms = $this->vocabulary($search, $name);
            $this->assertNotContains('run', $terms, "rebuild=$rebuild");
            $this->assertNotContains('walk', $terms, "rebuild=$rebuild");

            // The stems of what is left, and of what is added after
            $search->index($name, $this->doc('new', 'children are playing'));
            $this->assertSame(['new'], $found('plays'), "rebuild=$rebuild");
            $this->assertIntegrity($search, $name);
        }
    }

    /** @dataProvider schemaModes */
    public function test_chunks_are_stemmed_like_other_documents(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['chunk_size' => 120, 'chunk_overlap' => 0]);
        $text = 'First the dogs were running across the field. ' . str_repeat('Filler sentence about nothing at all. ', 6)
            . 'Finally the cats were walking home.';
        $search->index(self::INDEX, ['id' => 'long', 'content' => ['title' => 'Long', 'content' => $text]]);

        $ids = $this->found($search, 'runs');
        $this->assertContains('long', $ids);
        $this->assertContains('long#chunk0', $ids);
        $this->assertNotContains('long#chunk1', $ids, 'Only the chunk with the word is found');
        $walking = array_values(array_filter($this->found($search, 'walks'), function ($id) {
            return strpos($id, '#chunk') !== false;
        }));
        $this->assertNotEmpty($walking);

        $search->deleteByIdPrefix(self::INDEX, 'long#chunk');
        $this->assertSame(['long'], $this->found($search, 'runs'), 'Only the parent document is left');
        $search->delete(self::INDEX, 'long');
        $this->assertSame([], $this->found($search, 'runs'));
        $this->assertSame([], $this->found($search, 'walks'));
        $this->assertIntegrity($search);
    }

    /** @dataProvider schemaModes */
    public function test_a_meaning_only_document_has_no_stems(string $mode): void
    {
        $search = $this->stemmingSearch($mode);
        $search->index(self::INDEX, $this->doc('words', 'the dogs were running'));
        $search->index(self::INDEX, $this->doc('meaning', 'the dogs were running', ['meaning_only' => true]));

        $this->assertSame(['words'], $this->found($search, 'runs'));
        $this->assertSame(['words'], $this->found($search, 'running'));

        // A keyword document that becomes meaning-only loses its stems
        $search->index(self::INDEX, $this->doc('words', 'the dogs were running', ['meaning_only' => true]));
        $this->assertSame([], $this->found($search, 'runs'));
        if ($mode === 'external') {
            $stems = $this->pdo($search)->query('SELECT _stems FROM ' . self::INDEX . ' ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertSame([null, null], $stems);
        }
        $this->assertIntegrity($search);

        // and a batch of meaning-only documents does as well
        $search->indexBatch(self::INDEX, [
            $this->doc('batch', 'the dogs were running', ['meaning_only' => true]),
            $this->doc('keyword', 'a bird was singing'),
        ]);
        $this->assertSame([], $this->found($search, 'runs'));
        $this->assertSame(['keyword'], $this->found($search, 'sings'));
        $search->deleteByIdPrefix(self::INDEX, 'keyword');
        $this->assertSame([], $this->found($search, 'sings'));
        $this->assertIntegrity($search);
    }

    /** @dataProvider schemaModes */
    public function test_clearing_an_index_drops_its_stems(string $mode): void
    {
        $search = $this->stemmingSearch($mode);
        $search->index(self::INDEX, $this->doc('a', 'the dogs were running'));

        $search->clear(self::INDEX);
        $search->index(self::INDEX, $this->doc('b', 'a bird in the sky'));

        $this->assertSame([], $this->found($search, 'runs'));
        $this->assertSame([], $this->found($search, 'dogs'));
        $this->assertSame(['b'], $this->found($search, 'birds'));
        $terms = $this->vocabulary($search);
        foreach (['dogs', 'dog', 'run', 'running', 'were'] as $gone) {
            $this->assertNotContains($gone, $terms);
        }
        $this->assertIntegrity($search);
    }

    /**
     * An external-content FTS5 table can only delete the terms it is handed, and
     * a stemmer can change between writes, so the stems are kept with the row
     * and read back, never made again, to delete it.
     */
    public function test_a_row_is_deleted_with_the_stems_it_was_given_even_after_the_stemmer_changed(): void
    {
        $search = $this->stemmingSearch('external');
        $search->index(self::INDEX, $this->doc('a', 'the dogs were running'));
        $search->index(self::INDEX, $this->doc('b', 'a bird in the sky'));
        $this->assertSame(['a'], $this->found($search, 'runs'));
        $before = $this->pdo($search)->query('SELECT _stems FROM ' . self::INDEX . " WHERE id = 'a'")->fetchColumn();

        StemmerFactory::register('english', ReversingStemmer::class);

        // The update makes new stems, and deletes the old ones as they were stored
        $search->index(self::INDEX, $this->doc('a', 'the cats were walking'));
        $this->assertIntegrity($search);
        $terms = $this->vocabulary($search);
        foreach (explode(' ', $before) as $oldStem) {
            $this->assertNotContains($oldStem, $terms, "Stem '$oldStem' of the old text was left behind");
        }
        $this->assertContains('gniklaw', $terms, 'The new stems come from the replacement stemmer');

        // and so does a delete, and a document written by a batch
        $search->index(self::INDEX, $this->doc('b', 'a bird in the sky'));
        $search->delete(self::INDEX, 'b');
        $search->indexBatch(self::INDEX, [$this->doc('a', 'something else entirely')]);
        $this->assertIntegrity($search);
        $this->assertSame([], array_values(array_intersect($this->vocabulary($search), ['gniklaw', 'stac', 'drib', 'cats', 'walking', 'bird'])));
    }

    // ------------------------------------------------------------------
    // Switching an existing index
    // ------------------------------------------------------------------

    /** @dataProvider schemaModes */
    public function test_rebuilding_with_stemming_switches_an_existing_index_on_and_off(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => false]);
        $search->indexBatch(self::INDEX, [
            $this->doc('a', 'the dogs were running'),
            $this->doc('b', 'a bird in the sky', ['language' => 'en']),
            $this->doc('c', 'the printer connected', ['meaning_only' => true]),
        ]);
        $this->assertSame([], $this->found($search, 'runs'));
        $this->assertSame(['a'], $this->found($search, 'running'));

        $search->rebuildFts(self::INDEX, ['stemming' => true]);

        $this->assertSame('english', $this->storage($search)->stemmingFor(self::INDEX));
        $this->assertSame(['a'], $this->found($search, 'runs'));
        $this->assertSame(['a'], $this->found($search, 'running'));
        $this->assertSame(['b'], $this->found($search, 'birds'));
        $this->assertSame([], $this->found($search, 'connect'), 'A meaning-only document is not given stems');
        $fts = $this->columns($search, self::INDEX . '_fts');
        $this->assertSame('_stems', end($fts));
        $this->assertSame($mode === 'external', in_array('_stems', $this->columns($search, self::INDEX), true));
        $this->assertIntegrity($search);

        // New writes keep the stems from then on
        $search->update(self::INDEX, $this->doc('a', 'the cats were walking'));
        $this->assertSame([], $this->found($search, 'runs'));
        $this->assertSame(['a'], $this->found($search, 'walks'));

        $search->rebuildFts(self::INDEX, ['stemming' => false]);

        $this->assertNull($this->storage($search)->stemmingFor(self::INDEX));
        $this->assertSame([], $this->found($search, 'walks'));
        $this->assertSame(['a'], $this->found($search, 'walking'));
        $this->assertNotContains('_stems', $this->columns($search, self::INDEX . '_fts'));
        if ($mode === 'external') {
            $stale = $this->pdo($search)->query('SELECT COUNT(*) FROM ' . self::INDEX . ' WHERE _stems IS NOT NULL')->fetchColumn();
            $this->assertSame(0, (int)$stale, 'The stems column holds no stale text');
        }
        $search->index(self::INDEX, $this->doc('d', 'another one'));
        $this->assertSame(['d'], $this->found($search, 'another'));
        $this->assertIntegrity($search);
    }

    /** @dataProvider schemaModes */
    public function test_rebuilding_without_options_keeps_the_settings_and_makes_the_stems_again(string $mode): void
    {
        $search = $this->stemmingSearch($mode, ['language' => 'en']);
        $search->index(self::INDEX, $this->doc('a', 'the dogs were running'));
        $this->assertSame(['a'], $this->found($search, 'runs'));

        StemmerFactory::register('english', ReversingStemmer::class);
        // Still found by the stems it was indexed with, until it is rebuilt
        $search->rebuildFts(self::INDEX);

        $this->assertSame('english', $this->storage($search)->stemmingFor(self::INDEX));
        $this->assertSame([], $this->found($search, 'runs'), 'The old stems are gone');
        $this->assertSame(['a'], $this->found($search, 'running'));
        $this->assertContains('gnin' . 'nur', $this->vocabulary($search));
        $this->assertIntegrity($search);
    }

    /** @dataProvider schemaModes */
    public function test_rebuilding_can_set_the_language(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => false]);
        $search->index(self::INDEX, $this->doc('a', 'des chansons populaires'));
        $search->index(self::INDEX, $this->doc('b', 'popular songs', ['language' => 'en']));

        $search->rebuildFts(self::INDEX, ['stemming' => true, 'language' => 'fr']);

        $this->assertSame('french', $this->storage($search)->stemmingFor(self::INDEX));
        $this->assertSame(['a'], $this->found($search, 'chanson'));
        // The document with a language of its own is stemmed in it
        $this->assertSame(['b'], $this->found($search, 'song', ['language' => 'en']));
        $this->assertIntegrity($search);
    }

    public function test_rebuilding_through_the_facade_forgets_results_held_in_memory(): void
    {
        $search = $this->openSearch('external');
        $this->createIndex($search, 'external', ['stemming' => false]);
        $search->index(self::INDEX, $this->doc('a', 'the dogs were running'));

        $this->assertSame([], $this->found($search, 'runs'));
        $search->rebuildFts(self::INDEX, ['stemming' => true]);

        $this->assertSame(['a'], $this->found($search, 'runs'));
    }

    public function test_rebuilding_a_stemming_index_refuses_a_stems_field(): void
    {
        $search = $this->openSearch('multi');
        $search->createIndex(self::INDEX, ['fields' => ['title' => ['boost' => 1.0], '_stems' => ['boost' => 1.0]]]);
        $this->createdIndexes[] = self::INDEX;

        $this->expectException(\InvalidArgumentException::class);
        $search->rebuildFts(self::INDEX, ['stemming' => true]);
    }

    /** @dataProvider schemaModes */
    public function test_a_failed_switch_changes_nothing(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => false]);
        $search->index(self::INDEX, $this->doc('a', 'the dogs were running'));
        StemmerFactory::register('english', ExplodingStemmer::class);

        try {
            $search->rebuildFts(self::INDEX, ['stemming' => true]);
            $this->fail('The stemmer should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('no stems today', $e->getMessage());
        }

        StemmerFactory::reset();
        $this->assertNull($this->storage($search)->stemmingFor(self::INDEX));
        $this->assertNotContains('_stems', $this->columns($search, self::INDEX . '_fts'));
        $this->assertSame(['a'], $this->found($search, 'running'));
    }

    public function test_a_legacy_index_that_stems_can_move_to_external_content(): void
    {
        $search = $this->openSearch('legacy');
        $this->createIndex($search, 'legacy');
        $search->indexBatch(self::INDEX, [
            $this->doc('a', 'the dogs were running'),
            $this->doc('b', 'a bird in the sky'),
        ]);

        $this->storage($search)->migrateToExternalContent(self::INDEX);

        $this->assertContains('_stems', $this->columns($search, self::INDEX));
        $this->assertSame(['a'], $this->found($search, 'runs'));
        $search->update(self::INDEX, $this->doc('a', 'the cats were walking'));
        $this->assertSame([], $this->found($search, 'runs'));
        $this->assertSame(['a'], $this->found($search, 'walks'));
        $this->assertIntegrity($search);
    }

    // ------------------------------------------------------------------
    // Typo correction vocabulary
    // ------------------------------------------------------------------

    /** @dataProvider schemaModes */
    public function test_the_typo_correction_vocabulary_leaves_out_stems(string $mode): void
    {
        $search = $this->stemmingSearch($mode);
        $search->index(self::INDEX, $this->doc('a', 'quickly running studies'));
        $search->index(self::INDEX, $this->doc('b', 'quickly running'));

        $terms = array_keys($this->storage($search)->getIndexedTerms(self::INDEX));

        foreach (['quickly', 'running', 'studies'] as $word) {
            $this->assertContains($word, $terms);
        }
        foreach (['quickli', 'run', 'studi'] as $stem) {
            $this->assertNotContains($stem, $terms);
        }
        // The frequency of a term is the number of documents that hold it
        $this->assertSame(2, $this->storage($search)->getIndexedTerms(self::INDEX)['quickly']);
    }

    /** @dataProvider schemaModes */
    public function test_a_plain_index_vocabulary_is_unchanged(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => false]);
        $search->index(self::INDEX, $this->doc('a', 'quickly running'));

        $terms = array_keys($this->storage($search)->getIndexedTerms(self::INDEX));

        $this->assertContains('quickly', $terms);
        $this->assertNotContains('quickli', $terms);
    }

    /** @dataProvider schemaModes */
    public function test_the_levenshtein_terms_table_holds_words_and_no_stems(string $mode): void
    {
        $search = $this->openSearch($mode, [
            'storage' => ['search' => ['enable_fuzzy' => true, 'fuzzy_algorithm' => 'levenshtein']],
        ]);
        $this->createIndex($search, $mode);
        $search->index(self::INDEX, $this->doc('a', 'quickly running'));

        $terms = $this->pdo($search)->query('SELECT DISTINCT term FROM ' . self::INDEX . '_terms')->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertContains('quickly', $terms);
        $this->assertNotContains('quickli', $terms);
        $this->assertNotContains('run', $terms);
    }

    // ------------------------------------------------------------------
    // Writes to an index that does not stem, which share the code
    // ------------------------------------------------------------------

    /** @dataProvider schemaModes */
    public function test_a_plain_index_drops_the_old_text_of_a_document_written_again(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => false]);
        $search->index(self::INDEX, $this->doc('a', 'hello world'));
        $search->update(self::INDEX, $this->doc('a', 'goodbye moon'));
        $search->indexBatch(self::INDEX, [$this->doc('b', 'first version'), $this->doc('c', 'unrelated')]);
        $search->indexBatch(self::INDEX, [$this->doc('b', 'second version')]);

        $this->assertSame([], $this->found($search, 'hello'));
        $this->assertSame(['a'], $this->found($search, 'goodbye'));
        $this->assertSame([], $this->found($search, 'first'));
        $this->assertSame(['b'], $this->found($search, 'second'));
        $this->assertSame(1, $search->search(self::INDEX, 'goodbye')['total']);
        $this->assertIntegrity($search);
    }

    /** @dataProvider schemaModes */
    public function test_a_plain_index_forgets_everything_when_cleared(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => false]);
        $search->index(self::INDEX, $this->doc('a', 'hello world'));

        $search->clear(self::INDEX);
        $search->index(self::INDEX, $this->doc('b', 'goodbye moon'));

        $this->assertSame([], $this->found($search, 'world'));
        $this->assertSame(['b'], $this->found($search, 'goodbye'));
        $this->assertIntegrity($search);
    }
}

/** Writes each word backwards, so its stems are not those of any built-in stemmer */
class ReversingStemmer implements StemmerInterface
{
    public function stem(string $word): string
    {
        return strrev($word);
    }

    public function getLanguage(): string
    {
        return 'xx';
    }
}

class ExplodingStemmer implements StemmerInterface
{
    public function stem(string $word): string
    {
        throw new \RuntimeException('no stems today');
    }

    public function getLanguage(): string
    {
        return 'xx';
    }
}
