<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Tests\TestCase;

/**
 * createIndex() on an index that exists leaves the settings of its FTS table alone.
 */
class CreateIndexAgainTest extends TestCase
{
    private ?SqliteStorage $storage = null;
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = $this->getTestDbPath();
        $this->storage = new SqliteStorage();
        $this->storage->connect(['path' => $this->path]);
    }

    protected function tearDown(): void
    {
        $this->storage->disconnect();
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function meta(): array
    {
        $pdo = new \PDO('sqlite:' . $this->path);

        return $pdo->query("SELECT key, value FROM docs_meta WHERE key IN ('schema_mode', 'multi_column_fts', 'fts_columns', 'fts_detail') ORDER BY key")
            ->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    private function doc(string $id, string $text): array
    {
        return ['id' => $id, 'content' => ['title' => ucfirst($id), 'body' => $text]];
    }

    public function again(): array
    {
        return [
            'with no options' => [[]],
            'with other fields' => [['fields' => ['content']]],
            'with multi-column off' => [['multi_column_fts' => false]],
            'as external content' => [['external_content' => true]],
            'with another detail' => [['fts' => ['detail' => 'none']]],
        ];
    }

    /** @dataProvider again */
    public function test_an_own_content_index_with_a_column_per_field_keeps_working(array $options): void
    {
        $this->storage->createIndex('docs', ['external_content' => false, 'fields' => ['title', 'body'], 'fts' => ['detail' => 'full']]);
        $this->storage->insert('docs', $this->doc('a', 'dogs running'));
        $before = $this->meta();

        $this->storage->createIndex('docs', $options);

        $this->assertSame($before, $this->meta(), 'The settings of the FTS table are as they were');
        $this->storage->insert('docs', $this->doc('b', 'cats walking'));
        $this->storage->insertBatch('docs', [$this->doc('c', 'birds singing')]);
        $this->assertSame(['b'], array_column($this->storage->search('docs', ['query' => 'walking']), 'id'));
        $this->assertSame(['c'], array_column($this->storage->search('docs', ['query' => 'birds']), 'id'));
        $this->assertSame(['a'], array_column($this->storage->search('docs', ['query' => 'dogs']), 'id'));
    }

    public function test_an_external_content_index_stays_external(): void
    {
        $this->storage->createIndex('docs', ['external_content' => true]);
        $this->storage->insert('docs', $this->doc('a', 'dogs running'));
        $before = $this->meta();

        $this->storage->createIndex('docs', ['external_content' => false, 'fields' => ['title', 'body']]);

        $this->assertSame($before, $this->meta());
        $this->storage->insert('docs', $this->doc('b', 'cats walking'));
        $this->assertSame(['b'], array_column($this->storage->search('docs', ['query' => 'walking']), 'id'));
    }

    public function test_a_new_index_still_gets_its_settings(): void
    {
        $this->storage->createIndex('docs', ['external_content' => false, 'fields' => ['title', 'body'], 'fts' => ['detail' => 'column']]);

        $this->assertSame([
            'fts_columns' => '["title","body"]',
            'fts_detail' => 'column',
            'multi_column_fts' => '1',
            'schema_mode' => 'legacy',
        ], $this->meta());
    }
}
