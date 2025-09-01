<?php

namespace YetiSearch\Tests\Integration\Indexer;

use YetiSearch\Tests\TestCase;

class IndexerBehaviorTest extends TestCase
{
    public function test_chunking_and_metadata_and_store_flags(): void
    {
        $search = $this->createSearchInstance([
            'indexer' => [
                'chunk_size' => 50,
                'chunk_overlap' => 10,
                'fields' => [
                    'title' => ['boost' => 3.0, 'store' => true, 'index' => true],
                    'content' => ['boost' => 1.0, 'store' => true, 'index' => true],
                    'url' => ['boost' => 1.0, 'store' => true, 'index' => false],
                ],
            ],
        ]);

        $index = 'idx_chunk';
        $this->createTestIndex($index);

        $long = str_repeat('Sentence. ', 20); // ~160 chars
        $doc = [
            'id' => 'doc1',
            'content' => [
                'title' => 'Alpha',
                'content' => $long,
                'url' => 'http://example.com/a',
            ],
            'metadata' => ['k' => 'v'],
            'language' => 'en',
        ];

        $search->index($index, $doc);

        // Search and ensure chunks exist and metadata flags were set
        $res = $search->search($index, 'Sentence', ['limit' => 50]);
        $this->assertGreaterThanOrEqual(2, $res['total']);
        $hasChunk = false; $parentCount = 0; $sawUrl = false;
        foreach ($res['results'] as $r) {
            if (strpos($r['id'], '#chunk') !== false) { $hasChunk = true; }
            if ($r['id'] === 'doc1') { $parentCount++; }
            // url is stored; verify via storage readback for parent
            if ($r['id'] === 'doc1') { $sawUrl = true; }
        }
        $this->assertTrue($hasChunk);
        $this->assertSame(1, $parentCount);

        // Verify stored URL via storage API
        $ref = new \ReflectionClass($search);
        $m = $ref->getMethod('getStorage'); $m->setAccessible(true);
        $storage = $m->invoke($search);
        $docStored = $storage->getDocument($index, 'doc1');
        $this->assertSame('http://example.com/a', $docStored['content']['url'] ?? null);
    }

    public function test_auto_flush_false_batches_then_flush(): void
    {
        $search = $this->createSearchInstance([
            'indexer' => [
                'auto_flush' => false,
                'batch_size' => 10,
            ],
        ]);
        $index = 'idx_queue';
        $this->createTestIndex($index);

        for ($i = 1; $i <= 5; $i++) {
            $search->index($index, [
                'id' => 'q' . $i,
                'content' => ['title' => 'Queued ' . $i, 'content' => 'body'],
            ]);
        }
        // Without flush, results may not be in DB yet
        $res = $search->search($index, 'Queued');
        $this->assertSame(0, $res['total']);

        // Trigger flush via getIndexer()->flush()
        $search->getIndexer($index)->flush();
        // Verify persistence via DB count
        $ref = new \ReflectionClass($search);
        $m = $ref->getMethod('getStorage'); $m->setAccessible(true);
        $storage = $m->invoke($search);
        $pdoRef = new \ReflectionClass($storage);
        $prop = $pdoRef->getProperty('connection'); $prop->setAccessible(true);
        $pdo = $prop->getValue($storage);
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM {$index}")->fetchColumn();
        $this->assertSame(5, $cnt);
    }
}
