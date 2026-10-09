<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Tests\TestCase;

/**
 * rebuildFts() makes the FTS5 table again, with the options the table had.
 */
class RebuildFtsOptionsTest extends TestCase
{
    /** @var SqliteStorage[] */
    private array $storages = [];
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = $this->getTestDbPath();
    }

    protected function tearDown(): void
    {
        foreach ($this->storages as $storage) {
            $storage->disconnect();
        }
        $this->storages = [];
        parent::tearDown();
    }

    private function open(array $config = []): SqliteStorage
    {
        $storage = new SqliteStorage();
        $storage->connect(['path' => $this->path] + $config);

        return $this->storages[] = $storage;
    }

    private function createStatement(string $index = 'docs'): string
    {
        $pdo = new \PDO('sqlite:' . $this->path);
        $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE name = '{$index}_fts'")->fetchColumn();

        return (string)$sql;
    }

    private function doc(string $id, string $text): array
    {
        return ['id' => $id, 'content' => ['title' => ucfirst($id), 'body' => $text]];
    }

    /** @return string[] */
    private function ids(array $results): array
    {
        $ids = array_column($results, 'id');
        sort($ids);

        return $ids;
    }

    public function modes(): array
    {
        return [
            'external content' => ['external'],
            'own-content, one column' => ['legacy'],
            'own-content, a column per field' => ['multi'],
        ];
    }

    private function createIndex(SqliteStorage $storage, string $mode, array $options = []): void
    {
        $options += ['external_content' => $mode === 'external'];
        if ($mode === 'legacy') {
            $options['multi_column_fts'] = false;
        } elseif ($mode === 'multi') {
            $options['fields'] = ['title', 'body'];
        }
        $storage->createIndex('docs', $options);
    }

    /** @dataProvider modes */
    public function test_an_index_keeps_its_own_prefix_and_detail_when_rebuilt(string $mode): void
    {
        $storage = $this->open();
        $this->createIndex($storage, $mode, ['fts' => ['prefix' => [2, 4], 'detail' => 'column']]);
        $storage->insert('docs', $this->doc('a', 'the dogs were running'));
        $this->assertStringContainsString("prefix='2 4'", $this->createStatement());
        $this->assertStringContainsString("detail='column'", $this->createStatement());

        $storage->rebuildFts('docs');

        $sql = $this->createStatement();
        $this->assertStringContainsString("prefix='2 4'", $sql);
        $this->assertStringContainsString("detail='column'", $sql);
        $this->assertSame(['a'], $this->ids($storage->search('docs', ['query' => 'runn*'])));
    }

    /** @dataProvider modes */
    public function test_the_prefix_comes_from_the_table_and_not_from_the_config_of_the_connection(string $mode): void
    {
        $this->createIndex($this->open(['search' => ['fts_prefix' => [2]]]), $mode);
        $this->assertStringContainsString("prefix='2'", $this->createStatement());

        $other = $this->open(['search' => ['fts_prefix' => [3, 5]]]);
        $other->insert('docs', $this->doc('a', 'the dogs were running'));
        $other->rebuildFts('docs');

        $this->assertStringContainsString("prefix='2'", $this->createStatement());
        $this->assertStringNotContainsString('3 5', $this->createStatement());
    }

    /** @dataProvider modes */
    public function test_an_index_without_a_prefix_is_not_given_the_one_in_the_config(string $mode): void
    {
        $this->createIndex($this->open(), $mode);
        $this->assertStringNotContainsString('prefix', $this->createStatement());

        $other = $this->open(['search' => ['fts_prefix' => [2, 3]]]);
        $other->rebuildFts('docs');

        $this->assertStringNotContainsString('prefix', $this->createStatement());
        $this->assertStringNotContainsString('detail', $this->createStatement());
    }

    /** @dataProvider modes */
    public function test_switching_to_stemming_keeps_the_prefix_and_detail(string $mode): void
    {
        $storage = $this->open();
        $this->createIndex($storage, $mode, ['fts' => ['prefix' => [2, 3], 'detail' => 'full']]);
        $storage->insert('docs', $this->doc('a', 'the dogs were running'));

        $storage->rebuildFts('docs', ['stemming' => true]);

        $sql = $this->createStatement();
        $this->assertStringContainsString("prefix='2 3'", $sql);
        $this->assertStringContainsString("detail='full'", $sql);
        $this->assertStringContainsString('_stems', $sql);
        $this->assertSame('english', $storage->stemmingFor('docs'));
    }

    /** @dataProvider modes */
    public function test_the_detail_of_an_index_with_no_meta_for_it_still_stops_a_switch_to_stemming(string $mode): void
    {
        // An index an older version made kept no meta for its FTS options
        $storage = $this->open();
        $this->createIndex($storage, $mode, ['fts' => ['detail' => 'column']]);
        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->exec("DELETE FROM docs_meta WHERE key = 'fts_detail'");
        $other = $this->open();

        $this->expectException(\InvalidArgumentException::class);
        $other->rebuildFts('docs', ['stemming' => true]);
    }

    /** @dataProvider modes */
    public function test_an_index_with_no_fts_table_falls_back_to_the_configured_prefix(string $mode): void
    {
        $storage = $this->open(['search' => ['fts_prefix' => [2, 3]]]);
        $this->createIndex($storage, $mode, ['fts' => ['prefix' => [4]]]);
        $storage->insert('docs', $this->doc('a', 'the dogs were running'));
        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->exec('DROP TABLE docs_fts');

        $storage->rebuildFts('docs');

        $this->assertStringContainsString("prefix='2 3'", $this->createStatement());
        $this->assertSame(['a'], $this->ids($storage->search('docs', ['query' => 'dogs'])));
    }

    public function test_a_legacy_index_moved_to_external_content_keeps_its_prefix(): void
    {
        $storage = $this->open();
        $this->createIndex($storage, 'legacy', ['fts' => ['prefix' => [2, 3]]]);
        $storage->insert('docs', $this->doc('a', 'the dogs were running'));

        $storage->migrateToExternalContent('docs');

        $this->assertStringContainsString("prefix='2 3'", $this->createStatement());
        $this->assertStringContainsString("content='docs'", $this->createStatement());
        $this->assertSame(['a'], $this->ids($storage->search('docs', ['query' => 'running'])));
    }

    /**
     * Replace the index's FTS table with one written by hand, as another tool might have, and
     * give it the documents again.
     */
    private function handWrittenTable(SqliteStorage $storage, string $arguments): void
    {
        $storage->insert('docs', $this->doc('a', 'the dogs were running'));
        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->exec('DROP TABLE docs_fts');
        $pdo->exec("CREATE VIRTUAL TABLE docs_fts USING fts5(content, content='docs', content_rowid='doc_id', {$arguments})");
        $storage->rebuildFts('docs');
    }

    public function writtenOptions(): array
    {
        $tokenizerText = '"unicode61 tokenchars \' prefix=9 detail=column \'"';

        return [
            'text that looks like options in the tokenizer' => ["tokenize={$tokenizerText}, prefix='2 4', detail=full"],
            'the same text after the real options' => ["prefix='2 4', detail=full, tokenize={$tokenizerText}"],
            'the same text in single quotes with escapes' => ["prefix='2 4', detail=full, tokenize='unicode61 tokenchars '' prefix=9 detail=column '''"],
            'brackets' => ['prefix=[2 4], detail=[full]'],
            'backticks' => ['prefix=`2 4`, detail=`full`'],
            'double quotes' => ['prefix="2 4", detail="full"'],
            'single quotes' => ["prefix='2 4', detail='full'"],
            'spacing around the equals sign' => ["  prefix  =  '2 4' ,   detail =  full  "],
            'uppercase names' => ["PREFIX = '2 4', DETAIL = 'full'"],
            'a comma-separated prefix' => ["prefix='2,4', detail=full"],
        ];
    }

    /** @dataProvider writtenOptions */
    public function test_the_options_of_a_hand_written_table_are_read_by_name_outside_any_quoting(string $arguments): void
    {
        $storage = $this->open();
        $this->createIndex($storage, 'external');
        $this->handWrittenTable($storage, $arguments);

        $sql = $this->createStatement();
        $this->assertStringContainsString("prefix='2 4'", $sql);
        $this->assertStringContainsString("detail='full'", $sql);
        $this->assertStringNotContainsString("prefix='9", $sql);
        $this->assertStringNotContainsString("detail='column'", $sql);
        $this->assertSame(['a'], $this->ids($storage->search('docs', ['query' => 'running'])));
    }

    public function test_a_bare_prefix_is_read(): void
    {
        $storage = $this->open();
        $this->createIndex($storage, 'external');
        $this->handWrittenTable($storage, 'prefix=3');

        $this->assertStringContainsString("prefix='3'", $this->createStatement());
    }

    public function test_detail_column_in_brackets_still_refuses_stemming(): void
    {
        $storage = $this->open();
        $this->createIndex($storage, 'external');
        $this->handWrittenTable($storage, 'detail=[column]');
        $this->assertStringContainsString("detail='column'", $this->createStatement());

        $this->expectException(\InvalidArgumentException::class);
        $storage->rebuildFts('docs', ['stemming' => true]);
    }

    public function test_options_named_only_inside_a_tokenizer_are_not_options(): void
    {
        $storage = $this->open();
        $this->createIndex($storage, 'external');
        $this->handWrittenTable($storage, 'tokenize="unicode61 tokenchars \' prefix=9 detail=column \'"');

        $sql = $this->createStatement();
        $this->assertStringNotContainsString('prefix=', $sql);
        $this->assertStringNotContainsString('detail=', $sql);
        // It has the full detail, so nothing stops it from stemming
        $storage->rebuildFts('docs', ['stemming' => true]);
        $this->assertSame('english', $storage->stemmingFor('docs'));
    }

    /** @dataProvider modes */
    public function test_the_options_of_the_tables_this_library_creates_are_read_back(string $mode): void
    {
        $read = new \ReflectionMethod(SqliteStorage::class, 'existingFtsOptions');
        if (PHP_VERSION_ID < 80100) {
            $read->setAccessible(true);
        }
        $connection = new \ReflectionProperty(SqliteStorage::class, 'connection');
        if (PHP_VERSION_ID < 80100) {
            $connection->setAccessible(true);
        }

        $storage = $this->open();
        $this->createIndex($storage, $mode, ['fts' => ['prefix' => [2, 4], 'detail' => 'column']]);
        $this->assertSame(['prefix' => [2, 4], 'detail' => 'column'], $read->invoke($storage, 'docs'));

        $connection->getValue($storage)->exec('DROP TABLE docs_fts');
        $this->createIndex($storage, $mode);
        $this->assertSame(['prefix' => [], 'detail' => null], $read->invoke($storage, 'docs'));

        $connection->getValue($storage)->exec('DROP TABLE docs_fts');
        $this->assertNull($read->invoke($storage, 'docs'));
    }
}
