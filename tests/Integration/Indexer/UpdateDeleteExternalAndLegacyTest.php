<?php

namespace YetiSearch\Tests\Integration\Indexer;

use YetiSearch\Tests\TestCase;

class UpdateDeleteExternalAndLegacyTest extends TestCase
{
    private function getPdo($search): \PDO
    {
        $ref = new \ReflectionClass($search);
        $m = $ref->getMethod('getStorage'); $m->setAccessible(true);
        $storage = $m->invoke($search);
        $sref = new \ReflectionClass($storage);
        $p = $sref->getProperty('connection'); $p->setAccessible(true);
        return $p->getValue($storage);
    }

    public function test_update_and_delete_in_legacy_schema(): void
    {
        $search = $this->createSearchInstance(['storage' => ['external_content' => false], 'search' => ['min_score' => 0.0]]);
        $index = 'upd_legacy_idx';
        $this->createTestIndex($index);

        $doc = ['id' => 'x', 'content' => ['title' => 'T'], 'geo' => ['lat' => 10.0, 'lng' => 20.0]];
        $search->index($index, $doc);
        $pdo = $this->getPdo($search);
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM {$index}")->fetchColumn());
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM {$index}_fts")->fetchColumn());
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM {$index}_spatial")->fetchColumn());

        // Update geo
        $search->update($index, ['id' => 'x', 'content' => ['title' => 'T2'], 'geo' => ['lat' => 11.0, 'lng' => 22.0]]);
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM {$index}_spatial")->fetchColumn());

        // Delete
        $search->delete($index, 'x');
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM {$index}")->fetchColumn());
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM {$index}_fts")->fetchColumn());
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM {$index}_spatial")->fetchColumn());
    }

    public function test_update_and_delete_in_external_schema(): void
    {
        $search = $this->createSearchInstance(['storage' => ['external_content' => true], 'search' => ['min_score' => 0.0]]);
        $index = 'upd_external_idx';
        $this->createTestIndex($index);

        $doc = ['id' => 'y', 'content' => ['title' => 'T'], 'geo' => ['lat' => 30.0, 'lng' => 40.0]];
        $search->index($index, $doc);
        $pdo = $this->getPdo($search);
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM {$index}")->fetchColumn());
        // FTS row count in external schema is content-linked; ensure spatial populated
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM {$index}_spatial")->fetchColumn());

        // Update geo
        $search->update($index, ['id' => 'y', 'content' => ['title' => 'T2'], 'geo' => ['lat' => 31.0, 'lng' => 41.0]]);
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM {$index}_spatial")->fetchColumn());

        // Delete
        $search->delete($index, 'y');
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM {$index}")->fetchColumn());
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM {$index}_spatial")->fetchColumn());
    }
}
