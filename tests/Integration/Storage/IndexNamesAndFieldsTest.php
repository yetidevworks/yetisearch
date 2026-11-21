<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Tests\TestCase;

/**
 * An index is listed by what it is, whatever its name ends with, and the fields its FTS table is
 * made with are columns: plain names, none the table uses itself, quoted where SQL is written.
 */
class IndexNamesAndFieldsTest extends TestCase
{
    private function storage(bool $external = false): SqliteStorage
    {
        $storage = new SqliteStorage();
        $storage->connect(['path' => ':memory:', 'external_content' => $external]);

        return $storage;
    }

    private function tables(SqliteStorage $storage): array
    {
        $property = new \ReflectionProperty(SqliteStorage::class, 'connection');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        return $property->getValue($storage)->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function schemaModes(): array
    {
        return ['external' => [true], 'legacy' => [false]];
    }

    /** @dataProvider schemaModes */
    public function test_every_index_is_listed_whatever_its_name_ends_with(bool $external): void
    {
        $storage = $this->storage($external);
        foreach (['products', 'drafts', 'gifts', 'midterms', 'notes_fts2', 'my_terms'] as $name) {
            $storage->createIndex($name);
            $storage->insert($name, ['id' => '1', 'content' => ['content' => "word {$name}"]]);
        }
        // Reading the terms makes vocabulary tables, which are not indexes either
        $storage->getIndexedTerms('drafts');

        $this->assertSame(
            ['drafts', 'gifts', 'midterms', 'my_terms', 'notes_fts2', 'products'],
            array_column($storage->listIndices(), 'name')
        );
    }

    /** @dataProvider schemaModes */
    public function test_the_terms_of_all_indexes_are_read(bool $external): void
    {
        $storage = $this->storage($external);
        $storage->createIndex('t');
        $storage->insert('t', ['id' => '1', 'content' => ['content' => 'apple banana apple']]);
        $storage->createIndex('u');
        $storage->insert('u', ['id' => '1', 'content' => ['content' => 'cherry']]);

        $terms = $storage->getIndexedTerms();
        ksort($terms);
        $this->assertSame(['apple' => 1, 'banana' => 1, 'cherry' => 1], $terms);
        $this->assertNotContains('t_meta_fts_vocab', $this->tables($storage));
    }

    public function test_a_field_name_is_never_sql(): void
    {
        $storage = $this->storage();
        try {
            $storage->createIndex('t', ['fields' => ['title', 'body); CREATE TABLE pwned(a); --']]);
            $this->fail('The field should have been refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid field name', $e->getMessage());
        }

        $this->assertNotContains('pwned', $this->tables($storage));
        $this->assertNotContains('t', $this->tables($storage), 'Nothing of the index was made');
    }

    public function refusedFields(): array
    {
        return [
            'rank' => ['rank'],
            'rowid' => ['ROWID'],
            'id' => ['id'],
            'the table name' => ['t_fts'],
            'a dash' => ['meta-title'],
            'a space' => ['meta title'],
            'a leading digit' => ['1st'],
            'empty' => [''],
        ];
    }

    /** @dataProvider refusedFields */
    public function test_a_field_that_cannot_be_a_column_is_refused(string $field): void
    {
        $storage = $this->storage();

        $this->expectException(\InvalidArgumentException::class);
        $storage->createIndex('t', ['fields' => ['title', $field]]);
    }

    public function test_a_field_named_after_an_sql_keyword_is_a_column(): void
    {
        $storage = $this->storage();
        $storage->createIndex('t', ['fields' => ['title', 'order', 'select']]);
        $storage->insert('t', ['id' => '1', 'content' => ['title' => 'apple', 'order' => 'first', 'select' => 'pear']]);
        $storage->insertBatch('t', [
            ['id' => '2', 'content' => ['title' => 'banana', 'order' => 'second', 'select' => 'plum']],
            ['id' => '3', 'content' => ['title' => 'cherry', 'order' => 'third', 'select' => 'pear']],
        ]);

        $this->assertSame(['2'], array_column($storage->search('t', ['query' => 'second']), 'id'));
        $this->assertSame(['1', '3'], $this->sorted(array_column($storage->search('t', ['query' => 'pear']), 'id')));

        $storage->rebuildFts('t');
        $this->assertSame(['3'], array_column($storage->search('t', ['query' => 'third', 'field_weights' => ['order' => 2.0]]), 'id'));
    }

    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
