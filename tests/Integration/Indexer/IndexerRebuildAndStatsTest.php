<?php

namespace YetiSearch\Tests\Integration\Indexer;

use YetiSearch\Tests\TestCase;

class IndexerRebuildAndStatsTest extends TestCase
{
    public function test_rebuild_and_stats(): void
    {
        $search = $this->createSearchInstance();
        $index = 'rebuild_idx';
        $indexer = $search->createIndex($index);

        // Seed some docs
        $docs = [
            ['id' => 'a', 'content' => ['title' => 'Alpha']],
            ['id' => 'b', 'content' => ['title' => 'Beta']],
        ];
        $indexer->insert($docs);

        $stats = $search->getStats($index);
        $this->assertSame(2, $stats['document_count'] ?? 0);

        // Rebuild with a different set
        $newDocs = [
            ['id' => 'c', 'content' => ['title' => 'Gamma']],
            ['id' => 'd', 'content' => ['title' => 'Delta']],
            ['id' => 'e', 'content' => ['title' => 'Epsilon']],
        ];
        $indexer->rebuild($newDocs);

        $stats2 = $search->getStats($index);
        $this->assertSame(3, $stats2['document_count'] ?? 0);
    }
}

