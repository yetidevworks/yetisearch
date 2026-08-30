<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Tests\TestCase;
use YetiSearch\YetiSearch;

/**
 * External content is the default storage mode, and its FTS5 table holds no
 * copy of the indexed text — SQLite needs the original column values handed
 * back to it to remove a row's terms from the vocabulary. Deleting or replacing
 * a document without them leaves the old terms behind, pointing at a doc_id
 * that SQLite is then free to hand to the next document (doc_id is a plain
 * INTEGER PRIMARY KEY, so rowids are reused).
 */
class ExternalContentFtsDeleteTest extends TestCase
{
    private const INDEX = 'idx_external_delete';

    private function createExternalIndex(): YetiSearch
    {
        $search = $this->createSearchInstance([
            'storage' => ['external_content' => true],
        ]);
        $this->createTestIndex(self::INDEX);

        return $search;
    }

    private function ids(array $results): array
    {
        return array_column($results['results'], 'id');
    }

    public function test_a_deleted_documents_terms_stop_matching(): void
    {
        $search = $this->createExternalIndex();

        $search->index(self::INDEX, [
            'id' => 'x',
            'content' => ['title' => 'Zarquon Widget', 'content' => 'The zarquon is a rare thing.'],
        ]);
        $search->delete(self::INDEX, 'x');

        $results = $search->search(self::INDEX, 'zarquon');

        $this->assertSame(0, $results['total']);
    }

    /**
     * The regression proper: delete a document, then index a new one that takes
     * over the freed doc_id. The new document must not answer for the old one's
     * words, and must still be findable by its own.
     */
    public function test_a_reused_doc_id_does_not_inherit_the_deleted_documents_terms(): void
    {
        $search = $this->createExternalIndex();

        $search->index(self::INDEX, [
            'id' => 'x',
            'content' => ['title' => 'Zarquon Widget', 'content' => 'The zarquon is a rare thing.'],
        ]);
        $search->delete(self::INDEX, 'x');
        $search->index(self::INDEX, [
            'id' => 'y',
            'content' => ['title' => 'Ordinary Sprocket', 'content' => 'Nothing rare about this one.'],
        ]);

        $this->assertSame(
            [],
            $this->ids($search->search(self::INDEX, 'zarquon')),
            'The new document inherited the deleted document\'s terms'
        );
        $this->assertContains('y', $this->ids($search->search(self::INDEX, 'sprocket')));
    }

    public function test_an_updated_document_stops_matching_its_old_text(): void
    {
        $search = $this->createExternalIndex();

        $search->index(self::INDEX, [
            'id' => 'u',
            'content' => ['title' => 'Flibbertigibbet', 'content' => 'The original flibbertigibbet text.'],
        ]);
        $search->index(self::INDEX, [
            'id' => 'u',
            'content' => ['title' => 'Renamed', 'content' => 'Replacement text entirely.'],
        ]);

        $this->assertSame(
            [],
            $this->ids($search->search(self::INDEX, 'flibbertigibbet')),
            'The updated document still matches its replaced text'
        );
        $this->assertContains('u', $this->ids($search->search(self::INDEX, 'replacement')));
    }

    public function test_a_batch_updated_document_stops_matching_its_old_text(): void
    {
        $search = $this->createExternalIndex();

        $search->indexBatch(self::INDEX, [
            ['id' => 'z', 'content' => ['title' => 'Snorkwaffle', 'content' => 'The first snorkwaffle.']],
        ]);
        $search->getIndexer(self::INDEX)->flush();
        $search->indexBatch(self::INDEX, [
            ['id' => 'z', 'content' => ['title' => 'Batched Again', 'content' => 'Wholly different wording.']],
        ]);
        $search->getIndexer(self::INDEX)->flush();

        $this->assertSame(
            [],
            $this->ids($search->search(self::INDEX, 'snorkwaffle')),
            'The batch-updated document still matches its replaced text'
        );
        $this->assertContains('z', $this->ids($search->search(self::INDEX, 'wording')));
    }

    /**
     * deleteByIdPrefix() with $rebuildFts = false leaves the caller responsible
     * for resyncing, but the targeted deletes it does perform must be correct on
     * their own so that a following insert cannot inherit anything.
     */
    public function test_delete_by_prefix_removes_the_deleted_documents_terms(): void
    {
        $search = $this->createExternalIndex();

        $search->index(self::INDEX, [
            'id' => 'page#chunk1',
            'content' => ['title' => 'Chunk One', 'content' => 'Contains the word grimbleshanks.'],
        ]);
        $search->index(self::INDEX, [
            'id' => 'page#chunk2',
            'content' => ['title' => 'Chunk Two', 'content' => 'Contains the word wibbleflop.'],
        ]);

        $storage = new \ReflectionClass($search);
        $method = $storage->getMethod('getStorage');
        $method->setAccessible(true);
        /** @var \YetiSearch\Storage\SqliteStorage $sqlite */
        $sqlite = $method->invoke($search);
        $sqlite->deleteByIdPrefix(self::INDEX, 'page#chunk', false);

        $this->assertSame(0, $search->search(self::INDEX, 'grimbleshanks')['total']);
        $this->assertSame(0, $search->search(self::INDEX, 'wibbleflop')['total']);
    }

    /**
     * The FTS5 vocabulary itself must be clean, not merely masked by the join
     * back to the content table.
     */
    public function test_the_fts_vocabulary_no_longer_holds_the_deleted_term(): void
    {
        $search = $this->createExternalIndex();

        $search->index(self::INDEX, [
            'id' => 'x',
            'content' => ['title' => 'Zarquon', 'content' => 'zarquon'],
        ]);
        $search->delete(self::INDEX, 'x');

        $reflection = new \ReflectionClass($search);
        $method = $reflection->getMethod('getStorage');
        $method->setAccessible(true);
        /** @var \YetiSearch\Storage\SqliteStorage $sqlite */
        $sqlite = $method->invoke($search);

        $connection = new \ReflectionProperty(\YetiSearch\Storage\SqliteStorage::class, 'connection');
        $connection->setAccessible(true);
        /** @var \PDO $pdo */
        $pdo = $connection->getValue($sqlite);

        $index = self::INDEX;
        $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS {$index}_vocab USING fts5vocab('{$index}_fts', 'row')");
        $stmt = $pdo->prepare("SELECT cnt FROM {$index}_vocab WHERE term = ?");
        $stmt->execute(['zarquon']);
        $count = $stmt->fetchColumn();

        $this->assertFalse($count !== false && (int)$count > 0, 'Deleted term is still in the FTS vocabulary');
    }
}
