<?php

namespace YetiSearch\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use YetiSearch\Storage\FuzzyTermCache;

class FuzzyTermCacheTest extends TestCase
{
    private string $tmpDir;
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = YETISEARCH_TEST_TMP . '/cache';
        if (!is_dir($this->tmpDir)) {
            @mkdir($this->tmpDir, 0777, true);
        }
        $this->dbPath = $this->tmpDir . '/fuzzy_test.db';
        @unlink($this->dbPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
        @unlink($this->tmpDir . '/fuzzy_idx_fuzzy_cache.json');
        parent::tearDown();
    }

    public function test_set_get_and_persistence(): void
    {
        $cache = new FuzzyTermCache('fuzzy_idx', $this->dbPath, 100);
        $this->assertNull($cache->get('rocket'));

        $cache->set('rocket', ['rocket', 'rockets', 'rocketry']);
        $this->assertSame(['rocket', 'rockets', 'rocketry'], $cache->get('rOcKeT'));

        $cache->save();

        // New instance should load saved data
        $cache2 = new FuzzyTermCache('fuzzy_idx', $this->dbPath, 100);
        $this->assertSame(['rocket', 'rockets', 'rocketry'], $cache2->get('rocket'));
    }

    public function test_clear_removes_file_and_memory(): void
    {
        $cache = new FuzzyTermCache('fuzzy_idx', $this->dbPath, 100);
        $cache->set('star', ['star', 'stars']);
        $cache->save();

        $cache->clear();
        $this->assertNull($cache->get('star'));

        // File should be gone as well
        $file = dirname($this->dbPath) . '/fuzzy_idx_fuzzy_cache.json';
        $this->assertFileDoesNotExist($file);
    }

    public function test_eviction_when_max_size_reached(): void
    {
        $cache = new FuzzyTermCache('fuzzy_idx', $this->dbPath, 3);
        $cache->set('a', ['a']);
        $cache->set('b', ['b']);
        $cache->set('c', ['c']);
        $this->assertSame(['a'], $cache->get('a'));

        // Push over the limit; implementation trims oldest ~100 entries when full
        $cache->set('d', ['d']);

        // Either 'a' may be evicted depending on array_slice; assert new key exists and total <= max
        $present = array_filter([
            'a' => $cache->get('a'),
            'b' => $cache->get('b'),
            'c' => $cache->get('c'),
            'd' => $cache->get('d'),
        ], fn($v) => $v !== null);

        $this->assertArrayHasKey('d', $present);
        $this->assertLessThanOrEqual(3, count($present));
    }
}

