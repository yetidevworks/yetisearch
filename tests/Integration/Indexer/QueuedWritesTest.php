<?php

namespace YetiSearch\Tests\Integration\Indexer;

use YetiSearch\Analyzers\StandardAnalyzer;
use YetiSearch\Index\Indexer;
use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Tests\TestCase;

/**
 * With auto_flush off, documents wait in a queue. Writes made straight to the storage and the
 * end of the indexer's life have to agree with that queue.
 */
class QueuedWritesTest extends TestCase
{
    private function ids(string $index, string $query): array
    {
        $results = $this->search->search($index, $query, ['fuzzy' => false])['results'];

        return array_column($results, 'id');
    }

    public function test_an_update_wins_over_a_queued_version_after_the_flush(): void
    {
        $search = $this->createSearchInstance();
        $this->createdIndexes[] = 'q';
        $indexer = $search->createIndex('q', ['auto_flush' => false]);

        $indexer->insert(['id' => '1', 'content' => ['title' => 'alpha', 'content' => 'first version alpha']]);
        $indexer->update(['id' => '1', 'content' => ['title' => 'beta', 'content' => 'second version beta']]);
        $indexer->flush();

        $this->assertSame(['1'], $this->ids('q', 'beta'));
        $this->assertSame([], $this->ids('q', 'alpha'));
    }

    public function test_a_delete_is_not_undone_by_the_flush(): void
    {
        $search = $this->createSearchInstance(['indexer' => ['chunk_size' => 50, 'chunk_overlap' => 0]]);
        $this->createdIndexes[] = 'q';
        $indexer = $search->createIndex('q', ['auto_flush' => false]);

        $indexer->insert(['id' => '1', 'content' => ['title' => 'gamma', 'content' => str_repeat('to be deleted gamma. ', 8)]]);
        $indexer->delete('1');
        $indexer->flush();

        $this->assertSame([], $this->ids('q', 'gamma'));
    }

    public function test_queued_documents_are_written_when_the_indexer_is_destroyed(): void
    {
        $path = $this->getTestDbPath();
        $storage = new SqliteStorage();
        $storage->connect(['path' => $path, 'external_content' => false]);

        $indexer = new Indexer($storage, new StandardAnalyzer(), 'idx', ['auto_flush' => false, 'batch_size' => 100]);
        $indexer->insert([
            ['id' => '1', 'content' => ['title' => 'alpha', 'content' => 'alpha doc']],
            ['id' => '2', 'content' => ['title' => 'beta', 'content' => 'beta doc']],
        ]);
        $this->assertNull($storage->getDocument('idx', '1'));

        unset($indexer);

        $this->assertNotNull($storage->getDocument('idx', '1'));
        $this->assertNotNull($storage->getDocument('idx', '2'));
    }
}
