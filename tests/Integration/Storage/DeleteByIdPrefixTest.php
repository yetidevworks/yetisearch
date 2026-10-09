<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Tests\Integration\StemmingTestCase;

/**
 * deleteByIdPrefix() takes the deleted documents out of the FTS table with the text they
 * were indexed with, however it is called, and never indexes the stored JSON.
 */
class DeleteByIdPrefixTest extends StemmingTestCase
{
    private function doc(string $id, string $content): array
    {
        return ['id' => $id, 'content' => ['title' => ucfirst($id), 'content' => $content, 'route' => '/' . $id]];
    }

    private function found(\YetiSearch\YetiSearch $search, string $query): array
    {
        return $this->ids($search->search(self::INDEX, $query, ['fuzzy' => false]));
    }

    public function plainAndStemming(): array
    {
        $cases = [];
        foreach (['external', 'legacy', 'multi'] as $mode) {
            foreach ([false, true] as $stemming) {
                $cases[$mode . ($stemming ? ', stemming' : ', plain')] = [$mode, $stemming];
            }
        }

        return $cases;
    }

    /** @dataProvider plainAndStemming */
    public function test_a_prefix_delete_does_not_index_the_field_names(string $mode, bool $stemming): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => $stemming]);
        $search->indexBatch(self::INDEX, [
            $this->doc('page#chunk0', 'dogs running in the park'),
            $this->doc('page#chunk1', 'cats walking home'),
            $this->doc('other', 'birds singing loudly'),
            $this->doc('another', 'fish swimming slowly'),
        ]);
        $this->assertSame([], $this->found($search, 'title'));
        $this->assertSame([], $this->found($search, 'route'));

        $this->assertSame(2, $search->deleteByIdPrefix(self::INDEX, 'page#'));

        $this->assertSame([], $this->found($search, 'title'), 'A field name is not a word of the documents');
        $this->assertSame([], $this->found($search, 'route'));
        $this->assertSame([], $this->found($search, 'dogs'));
        $this->assertSame(['other'], $this->found($search, 'birds'));
        $this->assertSame(['another'], $this->found($search, 'fish'));
        $terms = $this->vocabulary($search);
        foreach (['title', 'route', 'dogs', 'cats'] as $absent) {
            $this->assertNotContains($absent, $terms);
        }
        $this->assertIntegrity($search);

        // Later deletes find the text they are given, whichever the first one was
        $search->deleteByIdPrefix(self::INDEX, 'other');
        $search->delete(self::INDEX, 'another');
        $this->assertSame([], $this->vocabulary($search), 'Nothing is left of the documents in the index');
        $this->assertIntegrity($search);
    }

    /** @dataProvider plainAndStemming */
    public function test_the_rebuild_flag_makes_no_difference(string $mode, bool $stemming): void
    {
        $vocabularies = [];
        foreach ([true, false] as $flag) {
            $search = $this->openSearch($mode);
            $name = 'flag' . (int)$flag;
            $this->createIndex($search, $mode, ['stemming' => $stemming], $name);
            $search->indexBatch($name, [
                $this->doc('page#chunk0', 'dogs running in the park'),
                $this->doc('keep', 'birds singing loudly'),
            ]);

            $search->deleteByIdPrefix($name, 'page#', $flag);

            $vocabularies[$flag] = $this->vocabulary($search, $name);
            $this->assertSame([], $this->ids($search->search($name, 'title', ['fuzzy' => false])));
            $this->assertIntegrity($search, $name);
        }
        $this->assertSame($vocabularies[true], $vocabularies[false]);
    }

    public function test_a_meaning_only_document_is_not_given_terms_by_a_prefix_delete(): void
    {
        $search = $this->openSearch('external');
        $this->createIndex($search, 'external', ['stemming' => true]);
        $search->indexBatch(self::INDEX, [
            $this->doc('page#chunk0', 'dogs running in the park'),
            $this->doc('card', 'the printer connected') + ['meaning_only' => true],
            $this->doc('keep', 'birds singing loudly'),
        ]);

        $search->deleteByIdPrefix(self::INDEX, 'page#');

        $this->assertSame([], $this->found($search, 'connected'));
        $this->assertSame([], $this->found($search, 'connect'));
        $this->assertSame(['keep'], $this->found($search, 'birds'));
        $this->assertNotContains('printer', $this->vocabulary($search));
        $this->assertIntegrity($search);
    }
}
