<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Exceptions\StorageException;
use YetiSearch\Tests\Integration\StemmingTestCase;

/**
 * What is indexed for a document is the content as stored: a later update or delete rebuilds the
 * indexed text from the stored JSON, so the two must never disagree.
 */
class StoredContentTest extends StemmingTestCase
{
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

    public function singleColumn(): array
    {
        $cases = [];
        foreach (['external', 'legacy'] as $mode) {
            foreach ([false, true] as $stemming) {
                $cases[$mode . ($stemming ? ', stemming' : ', plain')] = [$mode, $stemming];
            }
        }

        return $cases;
    }

    /** @return array A document whose content holds invalid UTF-8 in an indexed and in an unindexed value */
    private function brokenDocument(string $id, string $text): array
    {
        return [
            'id' => $id,
            'content' => [
                'title' => 'Broken',
                'content' => $text . " \xB1 tail",
                'raw' => (object)['blob' => "\xB1\x31"],
            ],
        ];
    }

    /** @dataProvider plainAndStemming */
    public function test_invalid_utf8_is_stored_replaced_and_the_document_can_be_updated(string $mode, bool $stemming): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => $stemming]);
        $storage = $this->storage($search);

        $storage->insert(self::INDEX, $this->brokenDocument('a', 'running dogs'));

        $stored = $storage->getDocument(self::INDEX, 'a');
        $this->assertSame("running dogs \u{FFFD} tail", $stored['content']['content']);
        $this->assertSame(['blob' => "\u{FFFD}1"], $stored['content']['raw']);
        $this->assertSame(['a'], $this->found($search, 'running'));

        $storage->insert(self::INDEX, $this->brokenDocument('a', 'giraffe'));

        $this->assertSame([], $this->found($search, 'running'), 'The old words are gone');
        $this->assertNotContains('running', $this->vocabulary($search));
        $this->assertSame(['a'], $this->found($search, 'giraffe'));
        $this->assertIntegrity($search);
    }

    /** @dataProvider plainAndStemming */
    public function test_invalid_utf8_in_a_batch_is_stored_replaced_and_the_document_can_be_updated(string $mode, bool $stemming): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => $stemming]);
        $storage = $this->storage($search);

        $storage->insertBatch(self::INDEX, [$this->brokenDocument('a', 'running dogs')]);
        $this->assertSame(['a'], $this->found($search, 'running'));

        $storage->insertBatch(self::INDEX, [$this->brokenDocument('a', 'giraffe')]);

        $this->assertSame([], $this->found($search, 'running'));
        $this->assertNotContains('running', $this->vocabulary($search));
        $this->assertSame(['a'], $this->found($search, 'giraffe'));
        $this->assertIntegrity($search);
    }

    /** @dataProvider plainAndStemming */
    public function test_a_prefix_delete_of_such_a_document_leaves_nothing_for_the_next_one_to_inherit(string $mode, bool $stemming): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => $stemming]);
        $storage = $this->storage($search);
        $storage->insert(self::INDEX, $this->brokenDocument('page#chunk0', 'running dogs'));

        $this->assertSame(1, $storage->deleteByIdPrefix(self::INDEX, 'page#', false));
        $storage->insert(self::INDEX, ['id' => 'next', 'content' => ['title' => 'Next', 'content' => 'giraffe']]);

        $this->assertSame([], $this->found($search, 'running'), 'The next document reuses the freed row but not its words');
        $this->assertNotContains('running', $this->vocabulary($search));
        $this->assertSame(['next'], $this->found($search, 'giraffe'));
        $this->assertIntegrity($search);
    }

    /**
     * Not the multi-column mode, which indexes only the fields it has a column for.
     *
     * @dataProvider singleColumn
     */
    public function test_the_text_of_an_object_value_is_indexed_as_it_is_stored(string $mode, bool $stemming): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => $stemming]);
        $storage = $this->storage($search);

        $storage->insert(self::INDEX, [
            'id' => 'a',
            'content' => ['title' => 'Object', 'content' => 'plain', 'extra' => (object)['note' => 'zebra']],
        ]);
        $this->assertSame(['a'], $this->found($search, 'zebra'), 'What a delete reads back is what was indexed');

        $storage->insert(self::INDEX, ['id' => 'a', 'content' => ['title' => 'Object', 'content' => 'plain']]);

        $this->assertSame([], $this->found($search, 'zebra'));
        $this->assertNotContains('zebra', $this->vocabulary($search));
        $this->assertIntegrity($search);
    }

    /** @dataProvider plainAndStemming */
    public function test_content_that_cannot_be_encoded_is_refused_and_nothing_is_written(string $mode, bool $stemming): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => $stemming]);
        $storage = $this->storage($search);
        $storage->insert(self::INDEX, ['id' => 'keep', 'content' => ['title' => 'Keep', 'content' => 'walrus']]);

        try {
            $storage->insert(self::INDEX, ['id' => 'bad', 'content' => ['title' => 'Bad', 'content' => 'running', 'n' => NAN]]);
            $this->fail('A document that cannot be encoded was accepted');
        } catch (StorageException $e) {
            $this->assertStringContainsString("'bad'", $e->getMessage());
        }
        try {
            $storage->insertBatch(self::INDEX, [
                ['id' => 'fine', 'content' => ['title' => 'Fine', 'content' => 'giraffe']],
                ['id' => 'bad', 'content' => ['title' => 'Bad', 'content' => 'running', 'n' => INF]],
            ]);
            $this->fail('A batch with a document that cannot be encoded was accepted');
        } catch (StorageException $e) {
            $this->assertStringContainsString("'bad'", $e->getMessage());
        }
        try {
            $storage->insert(self::INDEX, ['id' => 'bad', 'content' => ['content' => 'running'], 'metadata' => ['n' => NAN]]);
            $this->fail('A document whose metadata cannot be encoded was accepted');
        } catch (StorageException $e) {
            $this->assertStringContainsString('metadata', $e->getMessage());
        }

        $this->assertFalse($this->pdo($search)->inTransaction());
        $this->assertNull($storage->getDocument(self::INDEX, 'bad'));
        $this->assertNull($storage->getDocument(self::INDEX, 'fine'), 'The batch was rolled back as a whole');
        $this->assertSame([], $this->found($search, 'running'));
        $this->assertSame(['keep'], $this->found($search, 'walrus'));
        $this->assertIntegrity($search);
    }
}
