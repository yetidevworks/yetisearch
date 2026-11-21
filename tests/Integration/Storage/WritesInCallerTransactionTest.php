<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Tests\TestCase;

/**
 * A write made while the caller has a transaction open joins it: it neither fails for the
 * transaction being there nor commits or rolls back the caller's work.
 */
class WritesInCallerTransactionTest extends TestCase
{
    private SqliteStorage $storage;
    private \PDO $pdo;

    private function open(bool $external, array $indexOptions = []): void
    {
        $this->storage = new SqliteStorage();
        $this->storage->connect(['path' => ':memory:', 'external_content' => $external]);
        $this->storage->createIndex('t', $indexOptions);
        $property = new \ReflectionProperty(SqliteStorage::class, 'connection');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $this->pdo = $property->getValue($this->storage);
        $this->pdo->exec('CREATE TABLE app_log (entry TEXT)');
    }

    /** Begin the caller's transaction, with work of its own in it */
    private function beginCallerWork(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->exec("INSERT INTO app_log VALUES ('caller work')");
    }

    private function assertCallerWorkKept(): void
    {
        $this->assertTrue($this->pdo->inTransaction(), "The caller's transaction is still open");
        $this->pdo->commit();
        $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM app_log')->fetchColumn());
    }

    public function schemaModes(): array
    {
        return ['external' => [true], 'legacy' => [false]];
    }

    /** @dataProvider schemaModes */
    public function test_clear(bool $external): void
    {
        $this->open($external);
        $this->storage->insert('t', ['id' => '1', 'content' => ['content' => 'apple']]);

        $this->beginCallerWork();
        $this->storage->clear('t');
        $this->assertCallerWorkKept();

        $this->assertSame([], $this->storage->search('t', ['query' => 'apple']));
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    public function test_migrate_to_external_content(): void
    {
        $this->open(false);
        $this->storage->insert('t', ['id' => '1', 'content' => ['content' => 'apple pie']]);

        $this->beginCallerWork();
        $this->storage->migrateToExternalContent('t');
        $this->assertCallerWorkKept();

        $this->assertSame(['1'], array_column($this->storage->search('t', ['query' => 'apple']), 'id'));
        $this->assertSame('external', $this->pdo->query("SELECT value FROM t_meta WHERE key = 'schema_mode'")->fetchColumn());
    }

    public function test_upsert_vectors(): void
    {
        $this->open(true);
        $this->storage->insert('t', ['id' => '1', 'content' => ['content' => 'apple']]);

        $this->beginCallerWork();
        $this->storage->upsertVectors('t', [['id' => '1', 'hash' => 'h', 'model' => 'm', 'vector' => [0.6, 0.8]]]);
        $this->assertCallerWorkKept();

        $this->assertTrue($this->storage->hasVectors('t', 'm'));
    }

    public function test_save_probe_vectors(): void
    {
        $this->open(true);

        $this->beginCallerWork();
        $this->storage->saveProbeVectors('m', '', ['a probe' => [1.0, 0.0]]);
        $this->assertCallerWorkKept();

        $this->assertSame(['a probe'], array_keys($this->storage->loadProbeVectors('m')));
    }
}
