<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Tests\TestCase;

class ListIndicesAndSpatialTest extends TestCase
{
    private function getStorage($search)
    {
        $ref = new \ReflectionClass($search);
        $m = $ref->getMethod('getStorage'); $m->setAccessible(true);
        return $m->invoke($search);
    }

    public function test_list_indices_and_ensure_spatial_idempotent(): void
    {
        $search = $this->createSearchInstance();
        $i1 = 'li_idx_1';
        $i2 = 'li_idx_2';
        $this->createTestIndex($i1);
        $this->createTestIndex($i2);

        $list = $search->listIndices();
        $names = array_map(fn($r) => $r['name'], $list);
        $this->assertContains($i1, $names);
        $this->assertContains($i2, $names);

        // Call ensureSpatialTableExists twice (idempotent)
        $storage = $this->getStorage($search);
        $storage->ensureSpatialTableExists($i1);
        $storage->ensureSpatialTableExists($i1);
        $this->assertTrue(true);
    }
}
