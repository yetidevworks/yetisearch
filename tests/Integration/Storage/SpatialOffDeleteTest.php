<?php

namespace YetiSearch\Tests\Integration\Storage;

use PHPUnit\Framework\TestCase;
use YetiSearch\Storage\SqliteStorage;

/**
 * An index made with `enable_spatial` false has no spatial table and, when it stores its own content, no id
 * map: documents are still written, rewritten and deleted in every way an index is stored, and an index whose
 * spatial tables were dropped by hand keeps working.
 */
class SpatialOffDeleteTest extends TestCase
{
    private const INDEX = 'places';

    public function modes(): array
    {
        $cases = [];
        foreach (['external' => [true, false], 'own single' => [false, false], 'own multi' => [false, true]] as $name => [$external, $multi]) {
            foreach ([false, true] as $noRTree) {
                $cases[$name . ($noRTree ? ', no R-tree' : '')] = [$external, $multi, $noRTree];
            }
        }

        return $cases;
    }

    public function rtreeModes(): array
    {
        return [
            'external' => [true, false],
            'own single' => [false, false],
            'own multi' => [false, true],
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

    private function open(bool $external, bool $multi, bool $noRTree, bool $spatial = false): SqliteStorage
    {
        $storage = new SqliteStorage();
        $storage->connect(['path' => ':memory:', 'external_content' => $external]);
        if ($noRTree) {
            $this->property($storage, 'rtreeSupport')->setValue($storage, false);
        }
        $storage->createIndex(self::INDEX, [
            'enable_spatial' => $spatial,
            'multi_column_fts' => $multi,
            'fields' => ['title', 'body'],
        ]);

        return $storage;
    }

    private function pdo(SqliteStorage $storage): \PDO
    {
        return $this->property($storage, 'connection')->getValue($storage);
    }

    private function tables(SqliteStorage $storage): array
    {
        return $this->pdo($storage)
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND (name LIKE '%\\_spatial' ESCAPE '\\' OR name LIKE '%\\_id\\_map' ESCAPE '\\')")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function doc(string $id, string $word, ?float $latitude = null): array
    {
        $document = ['id' => $id, 'content' => ['title' => 'Title ' . $word, 'body' => 'Body about the ' . $word]];
        if ($latitude !== null) {
            $document['geo'] = ['lat' => $latitude, 'lng' => -105.0];
        }

        return $document;
    }

    private function found(SqliteStorage $storage, string $word): int
    {
        return $storage->count(self::INDEX, ['query' => $word, 'fuzzy' => false]);
    }

    private function integrity(SqliteStorage $storage): void
    {
        $this->pdo($storage)->exec('INSERT INTO ' . self::INDEX . '_fts(' . self::INDEX . '_fts) VALUES(\'integrity-check\')');
        $this->addToAssertionCount(1);
    }

    /** @dataProvider modes */
    public function test_an_index_made_without_spatial_has_no_spatial_tables(bool $external, bool $multi, bool $noRTree): void
    {
        $storage = $this->open($external, $multi, $noRTree);

        $this->assertSame([], $this->tables($storage));
    }

    /** @dataProvider modes */
    public function test_delete_removes_a_document(bool $external, bool $multi, bool $noRTree): void
    {
        $storage = $this->open($external, $multi, $noRTree);
        $storage->insert(self::INDEX, $this->doc('a', 'giraffe'));
        $storage->insert(self::INDEX, $this->doc('b', 'walrus'));
        $this->assertSame(1, $this->found($storage, 'giraffe'));

        $storage->delete(self::INDEX, 'a');

        $this->assertNull($storage->getDocument(self::INDEX, 'a'));
        $this->assertSame(0, $this->found($storage, 'giraffe'));
        $this->assertSame(1, $this->found($storage, 'walrus'));
        $this->integrity($storage);
    }

    /** @dataProvider modes */
    public function test_delete_of_a_document_that_is_not_there_does_nothing(bool $external, bool $multi, bool $noRTree): void
    {
        $storage = $this->open($external, $multi, $noRTree);
        $storage->insert(self::INDEX, $this->doc('a', 'giraffe'));

        $storage->delete(self::INDEX, 'missing');

        $this->assertSame(1, $this->found($storage, 'giraffe'));
    }

    /** @dataProvider modes */
    public function test_delete_by_id_prefix_removes_the_documents_with_it(bool $external, bool $multi, bool $noRTree): void
    {
        $storage = $this->open($external, $multi, $noRTree);
        $storage->insert(self::INDEX, $this->doc('page', 'giraffe'));
        $storage->insert(self::INDEX, $this->doc('page#chunk0', 'giraffe'));
        $storage->insert(self::INDEX, $this->doc('page#chunk1', 'giraffe'));
        $storage->insert(self::INDEX, $this->doc('other', 'walrus'));

        $this->assertSame(2, $storage->deleteByIdPrefix(self::INDEX, 'page#chunk'));
        $this->assertSame(1, $this->found($storage, 'giraffe'));
        $this->assertSame(1, $storage->deleteByIdPrefix(self::INDEX, 'page'));
        $this->assertSame(0, $this->found($storage, 'giraffe'));
        $this->assertSame(1, $this->found($storage, 'walrus'));
        $this->assertSame(0, $storage->deleteByIdPrefix(self::INDEX, 'nothing'));
        $this->integrity($storage);
    }

    /** @dataProvider modes */
    public function test_a_document_written_again_replaces_its_old_text(bool $external, bool $multi, bool $noRTree): void
    {
        $storage = $this->open($external, $multi, $noRTree);
        $storage->insert(self::INDEX, $this->doc('a', 'giraffe'));
        $storage->insert(self::INDEX, $this->doc('b', 'walrus'));

        $storage->insert(self::INDEX, $this->doc('a', 'otter'));
        $this->assertSame(0, $this->found($storage, 'giraffe'));
        $this->assertSame(1, $this->found($storage, 'otter'));

        $storage->update(self::INDEX, 'a', $this->doc('a', 'heron'));
        $this->assertSame(0, $this->found($storage, 'otter'));
        $this->assertSame(1, $this->found($storage, 'heron'));

        $storage->insertBatch(self::INDEX, [$this->doc('a', 'lynx'), $this->doc('b', 'puma')]);
        $this->assertSame(0, $this->found($storage, 'heron'));
        $this->assertSame(0, $this->found($storage, 'walrus'));
        $this->assertSame(1, $this->found($storage, 'lynx'));
        $this->assertSame(1, $this->found($storage, 'puma'));
        $this->assertSame([], $this->tables($storage), 'Writing does not make the tables the index was made without');
        $this->integrity($storage);
    }

    /** @dataProvider modes */
    public function test_a_document_with_a_location_is_stored_without_one_and_written_again(bool $external, bool $multi, bool $noRTree): void
    {
        $storage = $this->open($external, $multi, $noRTree);

        $storage->insert(self::INDEX, $this->doc('a', 'giraffe', 40.0));
        $storage->insertBatch(self::INDEX, [$this->doc('b', 'walrus', 41.0)]);
        $storage->insert(self::INDEX, $this->doc('a', 'otter', 42.0));
        $storage->insertBatch(self::INDEX, [$this->doc('b', 'puma', 43.0)]);

        $this->assertSame(1, $this->found($storage, 'otter'));
        $this->assertSame(1, $this->found($storage, 'puma'));
        $this->assertSame([], $this->tables($storage));
        $storage->delete(self::INDEX, 'a');
        $this->assertSame(1, $storage->deleteByIdPrefix(self::INDEX, 'b'));
    }

    /** @dataProvider modes */
    public function test_clear_rebuild_and_drop_work_without_spatial_tables(bool $external, bool $multi, bool $noRTree): void
    {
        $storage = $this->open($external, $multi, $noRTree);
        $storage->insert(self::INDEX, $this->doc('a', 'giraffe'));
        $storage->insert(self::INDEX, $this->doc('b', 'walrus'));

        $storage->rebuildFts(self::INDEX);
        $this->assertSame(1, $this->found($storage, 'giraffe'));
        $this->integrity($storage);

        $storage->clear(self::INDEX);
        $this->assertSame(0, $this->found($storage, 'giraffe'));
        $storage->insert(self::INDEX, $this->doc('c', 'otter'));
        $this->assertSame(1, $this->found($storage, 'otter'));
        $storage->delete(self::INDEX, 'c');

        $storage->dropIndex(self::INDEX);
        $this->assertFalse($storage->indexExists(self::INDEX));
    }

    /** @dataProvider modes */
    public function test_an_index_whose_spatial_tables_were_dropped_by_hand_still_deletes(bool $external, bool $multi, bool $noRTree): void
    {
        $storage = $this->open($external, $multi, $noRTree, true);
        $storage->insert(self::INDEX, $this->doc('a', 'giraffe', 40.0));
        $storage->insert(self::INDEX, $this->doc('b', 'walrus', 41.0));
        $storage->insert(self::INDEX, $this->doc('c1', 'otter', 42.0));
        $storage->insert(self::INDEX, $this->doc('c2', 'otter', 43.0));
        $pdo = $this->pdo($storage);
        $pdo->exec('DROP TABLE ' . self::INDEX . '_spatial');
        if (!$external) {
            $pdo->exec('DROP TABLE ' . self::INDEX . '_id_map');
        }

        $storage->delete(self::INDEX, 'a');
        $this->assertSame(2, $storage->deleteByIdPrefix(self::INDEX, 'c'));

        $this->assertSame(0, $this->found($storage, 'giraffe'));
        $this->assertSame(0, $this->found($storage, 'otter'));
        $this->assertSame(1, $this->found($storage, 'walrus'));
        $this->integrity($storage);
    }

    /** @dataProvider rtreeModes */
    public function test_an_index_whose_spatial_tables_were_dropped_by_hand_makes_them_again_on_a_write(bool $external, bool $multi): void
    {
        $storage = $this->open($external, $multi, false, true);
        $hasRTree = new \ReflectionMethod($storage, 'hasRTreeSupport');
        if (PHP_VERSION_ID < 80100) {
            $hasRTree->setAccessible(true);
        }
        if (!$hasRTree->invoke($storage)) {
            $storage->disconnect();
            $this->markTestSkipped('This SQLite has no R-tree module (PHP on Windows), which is what makes the table again');
        }
        $storage->insert(self::INDEX, $this->doc('a', 'giraffe', 40.0));
        $pdo = $this->pdo($storage);
        $pdo->exec('DROP TABLE ' . self::INDEX . '_spatial');
        if (!$external) {
            $pdo->exec('DROP TABLE ' . self::INDEX . '_id_map');
        }

        $storage->insert(self::INDEX, $this->doc('b', 'walrus', 41.0));

        $this->assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM ' . self::INDEX . '_spatial')->fetchColumn());
        $storage->delete(self::INDEX, 'b');
        $this->assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM ' . self::INDEX . '_spatial')->fetchColumn());
    }
}
