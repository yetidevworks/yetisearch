<?php

namespace YetiSearch\Tests\Integration\Indexer;

use YetiSearch\Tests\TestCase;

class ClearEmptyFlushTest extends TestCase
{
    public function test_flush_noop_and_clear_then_reindex(): void
    {
        $search = $this->createSearchInstance();
        $index = 'clear_flush_idx';
        $indexer = $search->createIndex($index);

        // Flush when empty should be a no-op
        $indexer->flush();

        // Add and clear
        $indexer->insert(['id' => 'a', 'content' => ['title' => 'A']]);
        $search->clear($index);

        // Re-index should work
        $indexer = $search->getIndexer($index);
        $indexer->insert(['id' => 'b', 'content' => ['title' => 'B']]);
        $res = $search->search($index, 'B');
        $this->assertSame(1, $res['total']);
    }
}
