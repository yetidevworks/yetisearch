<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Tests\TestCase;

/**
 * deleteByIdPrefix() deletes exactly the documents whose id starts with the prefix as it is written:
 * case counts, and `%`, `_` and `\` are characters like any other.
 */
class DeleteByIdPrefixExactTest extends TestCase
{
    private function storage(bool $external, array $ids): SqliteStorage
    {
        $storage = new SqliteStorage();
        $storage->connect(['path' => ':memory:', 'external_content' => $external]);
        $storage->createIndex('t');
        foreach ($ids as $id) {
            $storage->insert('t', ['id' => $id, 'content' => ['content' => 'apple ' . $id], 'geo' => ['lat' => 10.0, 'lng' => 20.0]]);
        }

        return $storage;
    }

    /** @return string[] */
    private function remaining(SqliteStorage $storage): array
    {
        $ids = array_column($storage->search('t', ['query' => 'apple', 'limit' => 100]), 'id');
        sort($ids, SORT_STRING);

        return $ids;
    }

    public function prefixes(): array
    {
        $cases = [
            'case counts' => [['Page1', 'page1#chunk0', 'PAGE2', 'page2'], 'Page', ['PAGE2', 'page1#chunk0', 'page2']],
            'a backslash' => [['a\\b1', 'a\\b2', 'ax'], 'a\\b', ['ax']],
            'a trailing backslash' => [['a\\', 'a\\1', 'ax', 'a%'], 'a\\', ['a%', 'ax']],
            'a percent sign' => [['a%1', 'ab', 'a%'], 'a%', ['ab']],
            'an underscore' => [['a_1', 'ab1', 'a_'], 'a_', ['ab1']],
            'a multibyte character' => [['é1', 'é2', 'ê1', 'e1'], 'é', ['e1', 'ê1']],
            'the whole id' => [['doc', 'doc#chunk0', 'doc#chunk1', 'docs'], 'doc#', ['doc', 'docs']],
        ];
        $data = [];
        foreach (['external' => true, 'legacy' => false] as $mode => $external) {
            foreach ($cases as $name => [$ids, $prefix, $kept]) {
                $data["$mode, $name"] = [$external, $ids, $prefix, $kept];
            }
        }

        return $data;
    }

    /** @dataProvider prefixes */
    public function test_only_the_ids_that_start_with_the_prefix_go(bool $external, array $ids, string $prefix, array $kept): void
    {
        $storage = $this->storage($external, $ids);

        $this->assertSame(count($ids) - count($kept), $storage->deleteByIdPrefix('t', $prefix));
        $this->assertSame($kept, $this->remaining($storage));
        foreach ($ids as $id) {
            $this->assertSame(in_array($id, $kept, true), $storage->getDocument('t', $id) !== null, $id);
        }
    }

    public function test_more_ids_than_one_statement_takes_are_deleted(): void
    {
        $storage = new SqliteStorage();
        $storage->connect(['path' => ':memory:', 'external_content' => true]);
        $storage->createIndex('t');
        $documents = [];
        for ($i = 0; $i < 1200; $i++) {
            $documents[] = ['id' => "page#chunk{$i}", 'content' => ['content' => "apple {$i}"]];
        }
        $documents[] = ['id' => 'page', 'content' => ['content' => 'apple pear']];
        $storage->insertBatch('t', $documents);

        $this->assertSame(1200, $storage->deleteByIdPrefix('t', 'page#'));
        $this->assertSame(['page'], $this->remaining($storage));
    }
}
