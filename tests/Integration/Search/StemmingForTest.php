<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Stemmer\StemmerFactory;
use YetiSearch\Tests\Integration\StemmingTestCase;

/**
 * YetiSearch::stemmingFor() says what language an index stems in, as the storage does.
 */
class StemmingForTest extends StemmingTestCase
{
    /** @dataProvider schemaModes */
    public function test_an_index_that_does_not_stem_has_no_language(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => false]);

        $this->assertNull($search->stemmingFor(self::INDEX));
    }

    /** @dataProvider schemaModes */
    public function test_an_index_that_stems_names_its_canonical_language(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode);
        $this->assertSame('english', $search->stemmingFor(self::INDEX), 'English when it was given no language');

        $this->createIndex($search, $mode, ['language' => 'fr'], 'french');
        $this->assertSame('french', $search->stemmingFor('french'), 'A code is its canonical name');

        $this->createIndex($search, $mode, ['language' => 'de_DE'], 'german');
        $this->assertSame('german', $search->stemmingFor('german'), 'So is a locale');

        $this->createIndex($search, $mode, ['language' => 'IT'], 'italian');
        $this->assertSame('it', $search->stemmingFor('italian'), 'A language with no stemmer is named as it was given, in lower case');
    }

    /** @dataProvider schemaModes */
    public function test_it_follows_a_rebuild_and_a_registered_language(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => false]);
        $search->index(self::INDEX, ['id' => 'a', 'content' => ['title' => 'A', 'content' => 'des chansons']]);

        $search->rebuildFts(self::INDEX, ['stemming' => true, 'language' => 'fr']);
        $this->assertSame('french', $search->stemmingFor(self::INDEX));

        $search->rebuildFts(self::INDEX, ['stemming' => false]);
        $this->assertNull($search->stemmingFor(self::INDEX));

        StemmerFactory::register('italian', \YetiSearch\Stemmer\Languages\EnglishStemmer::class, ['it']);
        $search->rebuildFts(self::INDEX, ['stemming' => true, 'language' => 'it']);
        $this->assertSame('italian', $search->stemmingFor(self::INDEX));
    }

    public function test_an_index_that_does_not_exist_has_no_language(): void
    {
        $search = $this->openSearch('external');

        $this->assertNull($search->stemmingFor('nothing_here'));
    }

    public function test_it_is_the_storages_answer(): void
    {
        $search = $this->openSearch('external');
        $this->createIndex($search, 'external', ['language' => 'es']);

        $this->assertSame($this->storage($search)->stemmingFor(self::INDEX), $search->stemmingFor(self::INDEX));
        $this->assertSame('spanish', $search->stemmingFor(self::INDEX));
    }
}
