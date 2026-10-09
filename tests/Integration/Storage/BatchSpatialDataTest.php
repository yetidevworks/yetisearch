<?php

namespace YetiSearch\Tests\Integration\Storage;

use PHPUnit\Framework\TestCase;
use YetiSearch\Storage\SqliteStorage;

class BatchSpatialDataTest extends TestCase
{
    public function modes(): array
    {
        return [
            'external R-tree' => [true, false, false],
            'single R-tree' => [false, false, false],
            'multi R-tree' => [false, true, false],
            'external fallback' => [true, false, true],
            'single fallback' => [false, false, true],
            'multi fallback' => [false, true, true],
        ];
    }

    private function property(SqliteStorage $storage, string $name): \ReflectionProperty
    {
        $property = new \ReflectionProperty($storage, $name);
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        return $property;
    }

    private function hasRTree(SqliteStorage $storage): bool
    {
        $method = new \ReflectionMethod($storage, 'hasRTreeSupport');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        return (bool)$method->invoke($storage);
    }

    private function document(string $id, ?float $latitude): array
    {
        $document = ['id' => $id, 'content' => ['title' => 'Local place', 'body' => 'Visit here']];
        if ($latitude !== null) {
            $document['geo'] = ['lat' => $latitude, 'lng' => -105.0];
        }

        return $document;
    }

    /** @dataProvider modes */
    public function test_batches_add_move_and_remove_locations_and_repair_missing_tables(bool $external, bool $multi, bool $fallback): void
    {
        $storage = new SqliteStorage();
        $storage->connect(['path' => ':memory:', 'external_content' => $external]);
        if (!$fallback && !$this->hasRTree($storage)) {
            $storage->disconnect();
            $this->markTestSkipped('This SQLite has no R-tree module (PHP on Windows); the fallback modes cover it');
        }
        if ($fallback) {
            $this->property($storage, 'rtreeSupport')->setValue($storage, false);
        }
        $storage->createIndex('places', ['multi_column_fts' => $multi, 'fields' => ['title', 'body']]);
        $pdo = $this->property($storage, 'connection')->getValue($storage);

        try {
            $storage->insertBatch('places', [$this->document('a', 40.0), $this->document('b', 41.0), $this->document('c', null)]);
            $storage->insertBatch('places', [$this->document('a', null), $this->document('b', 42.0), $this->document('c', 43.0), $this->document('d', null)]);

            $this->assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM places_spatial')->fetchColumn());
            $this->assertArrayNotHasKey('geo', $storage->getDocument('places', 'a'));
            $this->assertEqualsWithDelta(42.0, $storage->getDocument('places', 'b')['geo']['lat'], 0.0001);
            $this->assertEqualsWithDelta(43.0, $storage->getDocument('places', 'c')['geo']['lat'], 0.0001);
            $this->assertArrayNotHasKey('geo', $storage->getDocument('places', 'd'));

            // A later batch must check again, including after a table was removed.
            if (!$fallback) {
                $pdo->exec('DROP TABLE places_spatial');
                if (!$external) {
                    $pdo->exec('DROP TABLE places_id_map');
                }
            }
            $storage->insertBatch('places', [$this->document('b', null), $this->document('c', 44.0)]);
            $this->assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM places_spatial')->fetchColumn());
            $this->assertArrayNotHasKey('geo', $storage->getDocument('places', 'b'));
            $this->assertEqualsWithDelta(44.0, $storage->getDocument('places', 'c')['geo']['lat'], 0.0001);
            $this->assertSame(4, $storage->count('places', ['query' => 'place']));
        } finally {
            $storage->disconnect();
        }
    }
}
