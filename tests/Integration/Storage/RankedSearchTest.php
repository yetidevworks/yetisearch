<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Tests\Integration\StemmingTestCase;
use YetiSearch\YetiSearch;

/**
 * A first page with nothing but text is ranked on the FTS table alone and reads the
 * documents of the page only, instead of joining every match to its document.
 * It has to return what the join returns, in every way an index is stored.
 */
class RankedSearchTest extends StemmingTestCase
{
    /** @var string[] */
    private array $statements = [];

    protected function tearDown(): void
    {
        $this->statements = [];
        parent::tearDown();
    }

    /** @return array{0: YetiSearch, 1: SqliteStorage, 2: \PDO} */
    private function openTraced(string $mode, bool $stemming): array
    {
        $path = $this->getTestDbPath();
        $search = $this->openSearch($mode, ['storage' => ['path' => $path]]);
        $this->createIndex($search, $mode, ['stemming' => $stemming]);

        $documents = [];
        // Equal text, so equal scores: the order of a page is the sorter's alone
        for ($i = 1; $i <= 12; $i++) {
            $documents[] = ['id' => "same{$i}", 'content' => ['title' => 'Connect devices', 'content' => 'connected printers']];
        }
        $documents[] = ['id' => 'best', 'content' => ['title' => 'Connect', 'content' => 'connect connect connect connect']];
        $documents[] = ['id' => 'run', 'content' => ['title' => 'Running', 'content' => 'He runs and ran, running shoes']];
        for ($i = 1; $i <= 30; $i++) {
            $documents[] = ['id' => "plain{$i}", 'content' => ['title' => "Plain {$i}", 'content' => "lorem ipsum number{$i} " . ($i % 5 === 0 ? 'connected' : 'dolor')]];
        }
        $search->indexBatch(self::INDEX, $documents);

        $traced = $this->tracingConnection($path);
        $storage = $this->storage($search);
        $property = new \ReflectionProperty(SqliteStorage::class, 'connection');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $property->setValue($storage, $traced);

        return [$search, $storage, $traced];
    }

    private function tracingConnection(string $path): \PDO
    {
        $log = &$this->statements;
        $pdo = new class ("sqlite:{$path}", $log) extends \PDO {
            private array $log;

            public function __construct(string $dsn, array &$log)
            {
                parent::__construct($dsn, null, null, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]);
                $this->log = &$log;
            }

            #[\ReturnTypeWillChange]
            public function prepare($query, $options = [])
            {
                $this->log[] = preg_replace('/\s+/', ' ', trim($query));

                return parent::prepare($query, $options);
            }
        };

        return $pdo;
    }

    /** @return string[] The statements run since the last call */
    private function takeStatements(): array
    {
        $statements = $this->statements;
        $this->statements = [];

        return $statements;
    }

    /**
     * What the join returns: the debug SQL of the search is that statement.
     *
     * @return array<int, array{string, float}> id and score of each row
     */
    private function joined(SqliteStorage $storage, \PDO $pdo, array $query): array
    {
        $debug = $storage->search(self::INDEX, $query + ['_debug_sql' => true]);
        $this->assertStringContainsString('INNER JOIN', $debug['_sql']);
        $stmt = $pdo->prepare($debug['_sql']);
        $stmt->execute($debug['_params']);

        return array_map(function ($row) {
            return [$row['id'], abs($row['rank'])];
        }, $stmt->fetchAll());
    }

    private function returned(SqliteStorage $storage, array $query): array
    {
        return array_map(function ($row) {
            return [$row['id'], $row['score']];
        }, $storage->search(self::INDEX, $query + ['bypass_cache' => true]));
    }

    private function ranked(array $statements): bool
    {
        foreach ($statements as $sql) {
            if (strpos($sql, ' AS rid, bm25(') !== false) {
                return true;
            }
        }

        return false;
    }

    /** @dataProvider schemaModes */
    public function test_a_plain_text_search_reads_only_the_documents_of_the_page(string $mode): void
    {
        [, $storage] = $this->openTraced($mode, false);
        $this->takeStatements();

        $rows = $storage->search(self::INDEX, ['query' => 'connected', 'limit' => 3, 'bypass_cache' => true]);
        $statements = $this->takeStatements();

        $this->assertCount(3, $rows);
        $this->assertTrue($this->ranked($statements), 'The matches are ranked on the FTS table');
        $key = $mode === 'external' ? 'doc_id' : 'id';
        $this->assertContains('SELECT d.* FROM ' . self::INDEX . " d WHERE d.{$key} IN (?,?,?)", $statements);
        foreach ($statements as $sql) {
            $this->assertStringNotContainsString('INNER JOIN', $sql, 'No match is joined to its document');
        }
    }

    /** @dataProvider schemaModes */
    public function test_a_search_with_a_filter_a_language_or_a_sort_is_still_a_join(string $mode): void
    {
        [, $storage] = $this->openTraced($mode, false);

        $variants = [
            'filter' => ['filters' => [['field' => 'type', 'operator' => '=', 'value' => 'default']]],
            'language' => ['language' => 'en'],
            'sort' => ['sort' => ['timestamp' => 'DESC']],
        ];
        foreach ($variants as $name => $extra) {
            $this->takeStatements();
            $storage->search(self::INDEX, ['query' => 'connected', 'limit' => 3, 'bypass_cache' => true] + $extra);
            $statements = $this->takeStatements();

            $this->assertFalse($this->ranked($statements), "A search with a $name is not ranked on the FTS table alone");
            $this->assertNotEmpty(array_filter($statements, function ($sql) {
                return strpos($sql, 'INNER JOIN') !== false;
            }));
        }
    }

    /** @dataProvider schemaModes */
    public function test_it_returns_what_the_join_returns_page_by_page(string $mode): void
    {
        foreach ([false, true] as $stemming) {
            [, $storage, $pdo] = $this->openTraced($mode, $stemming);

            $queries = ['connected', 'connect', 'running', 'dolor', 'connect OR running', 'lorem ipsum'];
            foreach ($queries as $text) {
                $query = ['query' => $text];
                if ($stemming) {
                    $query['stem_query'] = '(' . $text . ') OR _stems : connect OR _stems : run';
                }
                // Pages through the equal scores, and one beyond the end
                foreach ([[5, 0], [5, 5], [5, 10], [3, 13], [100, 0], [4, 200]] as [$limit, $offset]) {
                    $page = $query + ['limit' => $limit, 'offset' => $offset];
                    $this->assertSame(
                        $this->joined($storage, $pdo, $page),
                        $this->returned($storage, $page),
                        "$text, limit $limit offset $offset" . ($stemming ? ', stemming' : '')
                    );
                }
            }

            $this->takeStatements();
            $this->returned($storage, ['query' => 'connected', 'limit' => 5]);
            $this->assertTrue($this->ranked($this->takeStatements()));
        }
    }

    /** @dataProvider schemaModes */
    public function test_a_match_whose_document_is_gone_is_left_out_as_the_join_leaves_it_out(string $mode): void
    {
        [, $storage, $pdo] = $this->openTraced($mode, false);

        // The best match loses its document and not its FTS entry
        $pdo->exec('DELETE FROM ' . self::INDEX . " WHERE id = 'best'");
        $this->takeStatements();

        $query = ['query' => 'connect', 'limit' => 3];
        $expected = $this->joined($storage, $pdo, $query);
        $statements = $this->takeStatements();
        $returned = $this->returned($storage, $query);
        $statements = $this->takeStatements();

        $this->assertCount(3, $expected, 'The join fills the page from the matches that have a document');
        $this->assertNotContains('best', array_column($expected, 0));
        $this->assertSame($expected, $returned);
        $this->assertTrue($this->ranked($statements), 'The ranking was tried');
        $this->assertNotEmpty(array_filter($statements, function ($sql) {
            return strpos($sql, 'INNER JOIN') !== false;
        }), 'and the search was run as a join when it did not agree with the documents');

        // A page that does not reach the missing match is not affected
        $query = ['query' => 'connected', 'limit' => 2];
        $this->assertSame($this->joined($storage, $pdo, $query), $this->returned($storage, $query));
    }

    /** @dataProvider schemaModes */
    public function test_positive_offsets_use_the_join_when_an_orphan_precedes_the_page(string $mode): void
    {
        foreach ([false, true] as $stemming) {
            [, $storage, $pdo] = $this->openTraced($mode, $stemming);
            $query = ['query' => 'connect', 'limit' => 50];
            if ($stemming) {
                $query['stem_query'] = 'connect OR _stems : connect';
            }
            $all = $this->joined($storage, $pdo, $query);
            $this->assertSame('best', $all[0][0], 'The missing match precedes every positive offset');
            $pdo->exec('DELETE FROM ' . self::INDEX . " WHERE id = 'best'");
            $total = $storage->count(self::INDEX, $query);
            $this->assertSame(count($all) - 1, $total);

            foreach ([1, 7, $total - 1, $total] as $offset) {
                $page = array_replace($query, ['limit' => 1, 'offset' => $offset]);
                $expected = $this->joined($storage, $pdo, $page);
                $this->takeStatements();
                $this->assertSame($expected, $this->returned($storage, $page), "Offset $offset");
                $this->assertFalse($this->ranked($this->takeStatements()), 'A positive offset uses the join');
                if ($offset === $total) {
                    $this->assertSame([], $expected, 'No document is returned beyond the total');
                }
            }
        }
    }

    /** @dataProvider schemaModes */
    public function test_a_search_with_no_match_returns_nothing(string $mode): void
    {
        [, $storage] = $this->openTraced($mode, false);

        $this->assertSame([], $storage->search(self::INDEX, ['query' => 'zzzzqqqq', 'limit' => 5, 'bypass_cache' => true]));
    }
}
