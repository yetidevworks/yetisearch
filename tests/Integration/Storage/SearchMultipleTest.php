<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Tests\TestCase;

class SearchMultipleTest extends TestCase
{
    public function test_search_multiple_indices_merges_results(): void
    {
        $search = $this->createSearchInstance();
        $i1 = 'sm_idx_1';
        $i2 = 'sm_idx_2';
        $this->createTestIndex($i1);
        $this->createTestIndex($i2);

        $search->indexBatch($i1, [
            ['id' => 'a1', 'content' => ['title' => 'Alpha rocket', 'content' => 'engine']]
        ]);
        $search->indexBatch($i2, [
            ['id' => 'a2', 'content' => ['title' => 'Beta rocket', 'content' => 'fuel']]
        ]);
        $search->getIndexer($i1)->flush();
        $search->getIndexer($i2)->flush();

        $res = $search->searchMultiple([$i1, $i2], 'rocket');
        $this->assertSame(2, $res['total']);
        $this->assertCount(2, $res['results']);
        // each result should have _index
        foreach ($res['results'] as $row) {
            $this->assertArrayHasKey('_index', $row);
            $this->assertTrue(in_array($row['_index'], [$i1, $i2], true));
        }
    }
}

