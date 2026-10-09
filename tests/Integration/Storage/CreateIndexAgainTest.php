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

    public function lostMeta(): array
    {
        return [
            'external content, meta table dropped' => ['external', 'drop'],
            'external content, meta emptied' => ['external', 'empty'],
            'external content, one key lost' => ['external', 'schema_mode'],
            'own-content title and body, meta table dropped' => ['multi', 'drop'],
            'own-content title and body, meta emptied' => ['multi', 'empty'],
            'own-content title and body, fts_columns lost' => ['multi', 'fts_columns'],
            'own-content title and body, multi_column_fts lost' => ['multi', 'multi_column_fts'],
            'own-content title and body, schema_mode lost' => ['multi', 'schema_mode'],
        ];
    }

    /** @dataProvider lostMeta */
    public function test_an_index_whose_meta_is_missing_or_partial_is_repaired_by_creating_it_again(string $mode, string $lost): void
    {
        $options = $mode === 'external'
            ? ['external_content' => true]
            : ['external_content' => false, 'fields' => ['title', 'body']];
        $this->storage->createIndex('docs', $options);
        $this->storage->insert('docs', $this->doc('a', 'dogs running'));

        $pdo = new \PDO('sqlite:' . $this->path);
        if ($lost === 'drop') {
            $pdo->exec('DROP TABLE docs_meta');
        } elseif ($lost === 'empty') {
            $pdo->exec('DELETE FROM docs_meta');
        } else {
            $pdo->exec("DELETE FROM docs_meta WHERE key = '{$lost}'");
        }
        $pdo = null;

        // A new connection, as the next request would have: nothing remembered about the index
        $this->storage->disconnect();
        $this->storage = new SqliteStorage();
        $this->storage->connect(['path' => $this->path]);
        $this->storage->createIndex('docs', $options);

        $meta = $this->meta();
        $this->assertSame($mode === 'external' ? 'external' : 'legacy', $meta['schema_mode']);
        $this->assertSame($mode === 'external' ? '["content"]' : '["title","body"]', $meta['fts_columns']);
        $this->storage->insert('docs', $this->doc('b', 'cats walking'));
        $this->storage->insertBatch('docs', [$this->doc('c', 'birds singing')]);
        $this->assertSame(['b'], array_column($this->storage->search('docs', ['query' => 'walking']), 'id'));
        $this->assertSame(['c'], array_column($this->storage->search('docs', ['query' => 'birds']), 'id'));
        $this->assertSame(['a'], array_column($this->storage->search('docs', ['query' => 'dogs']), 'id'));
    }

    public function test_a_stemming_index_whose_meta_is_lost_still_stems(): void
    {
        $options = ['external_content' => false, 'fields' => ['title', 'body'], 'stemming' => true];
        $this->storage->createIndex('docs', $options);
        $this->storage->insert('docs', $this->doc('a', 'the dogs were running'));
        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->exec('DELETE FROM docs_meta');
        $pdo = null;

        $this->storage->disconnect();
        $this->storage = new SqliteStorage();
        $this->storage->connect(['path' => $this->path]);
        $this->storage->createIndex('docs', $options);
        $this->storage->insert('docs', $this->doc('b', 'cats are walking'));

        $this->assertSame('english', $this->storage->stemmingFor('docs'));
        $pdo = new \PDO('sqlite:' . $this->path);
        $stems = $pdo->query("SELECT _stems FROM docs_fts WHERE id = 'b'")->fetchColumn();
        $this->assertStringContainsString('walk', (string)$stems, 'The new document is given its stems');
    }
}
