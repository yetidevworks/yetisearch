<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Stemmer\StemmerFactory;
use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Tests\TestCase;

/**
 * A storage remembers what it has read about an index (its FTS columns, whether it
 * stems). Another connection can change those, so the memory has to follow the database.
 */
class StaleIndexSettingsTest extends TestCase
{
    /** @var SqliteStorage[] */
    private array $storages = [];

    protected function tearDown(): void
    {
        foreach ($this->storages as $storage) {
            $storage->disconnect();
        }
        $this->storages = [];
        StemmerFactory::reset();
        parent::tearDown();
    }

    private function open(string $path, array $config = []): SqliteStorage
    {
        $storage = new SqliteStorage();
        $storage->connect(['path' => $path] + $config);

        return $this->storages[] = $storage;
    }

    private function doc(string $id, string $text): array
    {
        return ['id' => $id, 'content' => ['title' => ucfirst($id), 'content' => $text]];
    }

    private function integrity(SqliteStorage $storage, string $index): void
    {
        $property = new \ReflectionProperty(SqliteStorage::class, 'connection');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $property->getValue($storage)->exec("INSERT INTO {$index}_fts({$index}_fts) VALUES('integrity-check')");
        $this->addToAssertionCount(1);
    }

    /** @return string[] */
    private function ids(array $results): array
    {
        $ids = array_column($results, 'id');
        sort($ids);

        return $ids;
    }

    public function external_modes(): array
    {
        return ['external content' => [true], 'own-content' => [false]];
    }

    /** @dataProvider external_modes */
    public function test_a_write_follows_stemming_that_another_connection_switched_on(bool $external): void
    {
        $path = $this->getTestDbPath();
        $a = $this->open($path, ['external_content' => $external]);
        $b = $this->open($path, ['external_content' => $external]);
        $a->createIndex('docs', ['external_content' => $external, 'multi_column_fts' => false]);
        $b->insert('docs', $this->doc('x', 'the birds were singing'));
        $this->assertNull($b->stemmingFor('docs'), 'B has now read that the index does not stem');

        $a->rebuildFts('docs', ['stemming' => true]);

        $b->update('docs', 'x', $this->doc('x', 'the dogs were running'));
        $this->assertSame('english', $b->stemmingFor('docs'));
        $found = $a->search('docs', ['query' => 'running', 'stem_query' => '_stems : run', 'limit' => 10]);
        $this->assertSame(['x'], $this->ids($found));
        $this->integrity($a, 'docs');

        // and a batch, a delete and a prefix delete from the same stale connection
        $c = $this->open($path, ['external_content' => $external]);
        $c->insert('docs', $this->doc('y', 'cats walking'));
        $a->rebuildFts('docs', ['stemming' => false]);
        $c->insertBatch('docs', [$this->doc('z', 'something else')]);
        $this->assertNull($c->stemmingFor('docs'));
        $c->delete('docs', 'x');
        $c->deleteByIdPrefix('docs', 'y');
        $this->integrity($a, 'docs');
        $this->assertSame(['z'], $this->ids($a->search('docs', ['query' => 'something', 'limit' => 10])));
    }

    public function test_a_write_follows_fts_columns_that_another_connection_changed(): void
    {
        $path = $this->getTestDbPath();
        $a = $this->open($path);
        $b = $this->open($path);
        $a->createIndex('docs', ['multi_column_fts' => false]);
        $b->insert('docs', $this->doc('x', 'the birds were singing'));

        $a->dropIndex('docs');
        $a->createIndex('docs', ['fields' => ['title', 'body'], 'multi_column_fts' => true]);

        $b->insert('docs', ['id' => 'y', 'content' => ['title' => 'Wrens', 'body' => 'small brown birds']]);
        $this->assertSame(['y'], $this->ids($b->search('docs', ['query' => 'wrens', 'limit' => 10])));
        $this->assertSame(['y'], $this->ids($a->search('docs', ['query' => 'brown', 'limit' => 10])));
    }

    public function test_search_and_count_follow_stemming_that_another_connection_switched_on(): void
    {
        $path = $this->getTestDbPath();
        $a = $this->open($path, ['external_content' => true]);
        $b = $this->open($path, ['external_content' => true]);
        $a->createIndex('docs', ['external_content' => true]);
        $a->insert('docs', $this->doc('x', 'the dogs were running'));
        $query = ['query' => 'running', 'stem_query' => '(running OR _stems : run)', 'limit' => 10];
        $this->assertSame(1, $b->count('docs', $query));

        $a->rebuildFts('docs', ['stemming' => true]);

        // B's old memory would search the query as typed, which is not in the text
        $this->assertSame(['x'], $this->ids($b->search('docs', ['query' => 'runs', 'stem_query' => '_stems : run', 'limit' => 10])));
        $this->assertSame(1, $b->count('docs', ['query' => 'runs', 'stem_query' => '_stems : run']));
    }
}
