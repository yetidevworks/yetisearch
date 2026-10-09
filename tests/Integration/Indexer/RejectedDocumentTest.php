<?php

namespace YetiSearch\Tests\Integration\Indexer;

use YetiSearch\Exceptions\StorageException;
use YetiSearch\Tests\Integration\StemmingTestCase;

/**
 * A document the storage would refuse, because its content or metadata cannot be encoded, is
 * refused before the indexer writes the document or any of its chunks.
 */
class RejectedDocumentTest extends StemmingTestCase
{
    private function open(string $mode, array $indexer = []): \YetiSearch\YetiSearch
    {
        $search = $this->openSearch($mode, [
            'indexer' => $indexer + ['chunk_size' => 100, 'chunk_overlap' => 0],
        ]);
        $this->createIndex($search, $mode);

        return $search;
    }

    /** @return string[] Every id that is stored, chunks included */
    private function storedIds(\YetiSearch\YetiSearch $search): array
    {
        $ids = $this->pdo($search)->query('SELECT id FROM ' . self::INDEX . ' ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);

        return $ids;
    }

    private function sentences(): string
    {
        return str_repeat('Running dogs chase the ghostword. ', 8);
    }

    /** @dataProvider schemaModes */
    public function test_a_parent_that_cannot_be_encoded_leaves_none_of_its_chunks(string $mode): void
    {
        $search = $this->open($mode);

        // The chunks carry their own content, so they encode; the parent holds the NAN
        try {
            $search->index(self::INDEX, [
                'id' => 'bad',
                'content' => ['title' => NAN],
                'chunks' => [
                    ['content' => ['content' => 'first ghostword chunk']],
                    ['content' => ['content' => 'second ghostword chunk']],
                ],
            ]);
            $this->fail('A document that cannot be encoded was accepted');
        } catch (StorageException $e) {
            $this->assertStringContainsString("'bad'", $e->getMessage());
        }

        $this->assertSame([], $this->storedIds($search));
        $this->assertSame([], $this->ids($search->search(self::INDEX, 'ghostword', ['fuzzy' => false])));
        $this->assertIntegrity($search);
    }

    /** @dataProvider schemaModes */
    public function test_a_chunk_that_cannot_be_encoded_leaves_neither_the_parent_nor_its_other_chunks(string $mode): void
    {
        $search = $this->open($mode);

        try {
            $search->index(self::INDEX, [
                'id' => 'bad',
                'content' => ['title' => 'Parent', 'content' => 'parent ghostword'],
                'chunks' => [
                    ['content' => ['content' => 'first ghostword chunk']],
                    ['content' => ['content' => 'second ghostword chunk'], 'metadata' => ['score' => INF]],
                ],
            ]);
            $this->fail('A chunk that cannot be encoded was accepted');
        } catch (StorageException $e) {
            $this->assertStringContainsString("'bad#chunk1'", $e->getMessage());
        }

        $this->assertSame([], $this->storedIds($search));
        $this->assertSame([], $this->ids($search->search(self::INDEX, 'ghostword', ['fuzzy' => false])));
        $this->assertIntegrity($search);
    }

    /** @dataProvider schemaModes */
    public function test_a_document_whose_automatic_chunks_cannot_be_encoded_leaves_nothing(string $mode): void
    {
        $search = $this->open($mode);

        try {
            $search->index(self::INDEX, [
                'id' => 'bad',
                'content' => ['title' => 'Long', 'content' => $this->sentences()],
                'metadata' => ['score' => NAN],
            ]);
            $this->fail('A document that cannot be encoded was accepted');
        } catch (StorageException $e) {
            $this->assertStringContainsString('metadata', $e->getMessage());
        }

        $this->assertSame([], $this->storedIds($search));
        $this->assertIntegrity($search);
    }

    /** @dataProvider schemaModes */
    public function test_a_batch_with_a_bad_document_writes_none_of_it_even_in_batches_of_one(string $mode): void
    {
        $search = $this->open($mode, ['batch_size' => 1]);

        try {
            $search->indexBatch(self::INDEX, [
                ['id' => 'first', 'content' => ['title' => 'First', 'content' => 'walrus']],
                ['id' => 'bad', 'content' => ['title' => NAN, 'content' => 'running']],
                ['id' => 'last', 'content' => ['title' => 'Last', 'content' => 'giraffe']],
            ]);
            $this->fail('A batch with a document that cannot be encoded was accepted');
        } catch (StorageException $e) {
            $this->assertStringContainsString("'bad'", $e->getMessage());
        }

        $this->assertSame([], $this->storedIds($search));
        $this->assertSame([], $this->ids($search->search(self::INDEX, 'walrus', ['fuzzy' => false])));
    }

    private function throwingSerializer(): \JsonSerializable
    {
        return new class implements \JsonSerializable {
            #[\ReturnTypeWillChange]
            public function jsonSerialize()
            {
                throw new \RuntimeException('cannot serialize');
            }
        };
    }

    /** @return array<string, array{0: string, 1: string}> Schema mode and where the serializer sits */
    public function throwingSerializers(): array
    {
        $cases = [];
        foreach (['external', 'legacy', 'multi'] as $mode) {
            $cases["{$mode}, in the content"] = [$mode, 'content'];
            $cases["{$mode}, in the metadata"] = [$mode, 'metadata'];
        }

        return $cases;
    }

    /** @dataProvider throwingSerializers */
    public function test_a_serializer_that_throws_refuses_the_call_instead_of_skipping_a_document(string $mode, string $where): void
    {
        $search = $this->open($mode);
        $bad = ['id' => 'bad', 'content' => ['title' => 'Bad', 'content' => 'running']];
        if ($where === 'content') {
            $bad['content']['title'] =$this->throwingSerializer();
        } else {
            $bad['metadata'] = ['extra' => $this->throwingSerializer()];
        }

        try {
            $search->getIndexer(self::INDEX)->insert([
                ['id' => 'first', 'content' => ['title' => 'First', 'content' => 'walrus']],
                $bad,
                ['id' => 'last', 'content' => ['title' => 'Last', 'content' => 'giraffe']],
            ]);
            $this->fail('A document whose serializer throws was accepted');
        } catch (StorageException $e) {
            $this->assertStringContainsString("'bad'", $e->getMessage());
            $this->assertStringContainsString($where, $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }

        $this->assertSame([], $this->storedIds($search));
        $this->assertSame([], $this->ids($search->search(self::INDEX, 'walrus', ['fuzzy' => false])));
        $this->assertIntegrity($search);
    }

    /** @dataProvider schemaModes */
    public function test_a_queued_batch_is_not_touched_by_a_bad_document(string $mode): void
    {
        $search = $this->open($mode, ['auto_flush' => false, 'batch_size' => 10]);
        $indexer = $search->getIndexer(self::INDEX);
        $indexer->insert(['id' => 'queued', 'content' => ['title' => 'Queued', 'content' => 'walrus']]);

        try {
            $indexer->insert([
                ['id' => 'fine', 'content' => ['title' => 'Fine', 'content' => 'giraffe']],
                ['id' => 'bad', 'content' => ['title' => NAN, 'content' => 'running']],
            ]);
            $this->fail('A batch with a document that cannot be encoded was accepted');
        } catch (StorageException $e) {
            $this->assertStringContainsString("'bad'", $e->getMessage());
        }

        $indexer->flush();
        $this->assertSame(['queued'], $this->storedIds($search));
    }

    /** @dataProvider schemaModes */
    public function test_a_chunked_document_and_its_chunks_are_written_in_one_call(string $mode): void
    {
        $search = $this->open($mode, ['auto_flush' => false, 'batch_size' => 10]);
        $indexer = $search->getIndexer(self::INDEX);

        $indexer->insert(['id' => 'long', 'content' => ['title' => 'Long', 'content' => $this->sentences()]]);
        $this->assertSame([], $this->storedIds($search), 'Neither the chunks nor the document are written before the flush');

        $indexer->flush();
        $ids = $this->storedIds($search);
        $this->assertContains('long', $ids);
        $this->assertContains('long#chunk0', $ids);
        $this->assertContains('long#chunk1', $ids);
    }

    public function test_updating_a_document_that_cannot_be_encoded_changes_nothing(): void
    {
        $search = $this->open('external');
        $search->index(self::INDEX, [
            'id' => 'doc',
            'content' => ['title' => 'Doc', 'content' => $this->sentences()],
        ]);
        $before = $this->storedIds($search);

        try {
            $search->update(self::INDEX, [
                'id' => 'doc',
                'content' => ['title' => 'Doc', 'content' => $this->sentences() . ' extra'],
                'metadata' => ['score' => NAN],
            ]);
            $this->fail('An update that cannot be encoded was accepted');
        } catch (\YetiSearch\Exceptions\YetiSearchException $e) {
            $this->assertStringContainsString('metadata', $e->getMessage());
        }

        $this->assertSame($before, $this->storedIds($search));
        $this->assertSame([], $this->ids($search->search(self::INDEX, 'extra', ['fuzzy' => false])));
    }
}
