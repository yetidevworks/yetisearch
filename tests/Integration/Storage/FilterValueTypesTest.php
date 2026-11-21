<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Tests\TestCase;

/**
 * A filter on a field of a document's JSON compares the numbers stored there as numbers and the
 * strings as text, whatever case its operator is written in. A filter that cannot be applied is
 * refused, not left out, and `contains` finds `%` and `_` as they are.
 */
class FilterValueTypesTest extends TestCase
{
    private function storage(bool $external): SqliteStorage
    {
        $storage = new SqliteStorage();
        $storage->connect(['path' => ':memory:', 'external_content' => $external]);
        $storage->createIndex('t');
        $documents = [
            ['1', ['content' => 'apple pie', 'year' => 2020, 'tags' => ['red', 'green']], ['price' => 5, 'on_sale' => true, 'cat' => 'a', 'code' => '00123', 'published' => '2024-01-15', 'name' => 'Alpha'], 1000],
            ['2', ['content' => 'apple tart', 'year' => 2021], ['price' => 15, 'on_sale' => false, 'cat' => 'b', 'code' => 123, 'published' => '2024-06-20', 'name' => 'Mike'], 2000],
            ['3', ['content' => 'apple cake', 'year' => 2022], ['price' => 25, 'cat' => '100%_off', 'published' => '2024-12-31', 'name' => 'Zulu'], 3000],
            ['4', ['content' => 'apple crumble', 'year' => '2023'], ['price' => 35.5, 'cat' => '100 percent', 'published' => '2025-03-01', 'name' => 'Bravo'], 4000],
        ];
        foreach ($documents as [$id, $content, $metadata, $timestamp]) {
            $storage->insert('t', [
                'id' => $id,
                'content' => $content,
                'metadata' => $metadata,
                'language' => 'en',
                'type' => 'default',
                'timestamp' => $timestamp,
            ]);
        }

        return $storage;
    }

    /** @return string[] */
    private function found(SqliteStorage $storage, array $filter): array
    {
        $query = ['query' => 'apple', 'filters' => [$filter], 'limit' => 10];
        $ids = array_column($storage->search('t', $query), 'id');
        sort($ids);
        $this->assertSame(count($ids), $storage->count('t', $query), 'count() finds what search() finds');

        return $ids;
    }

    public function schemaModes(): array
    {
        return ['external' => [true], 'legacy' => [false]];
    }

    public function typedFilters(): array
    {
        $cases = [
            'number = int' => [['metadata.price', '=', 5], ['1']],
            'number = numeric string' => [['metadata.price', '=', '5'], ['1']],
            'number = float' => [['metadata.price', '=', 35.5], ['4']],
            'number !=' => [['metadata.price', '!=', 5], ['2', '3', '4']],
            'number in' => [['metadata.price', 'in', [5, 15]], ['1', '2']],
            'number not in' => [['metadata.price', 'not in', [5]], ['2', '3', '4']],
            'true' => [['metadata.on_sale', '=', true], ['1']],
            'false' => [['metadata.on_sale', '=', false], ['2']],
            'true or missing' => [['metadata.on_sale', '=?', true], ['1', '3', '4']],
            'number >' => [['metadata.price', '>', 10], ['2', '3', '4']],
            'number <=' => [['metadata.price', '<=', 15], ['1', '2']],
            'number between' => [['metadata.price', 'between', [10, 30]], ['2', '3']],
            'content number' => [['content.year', '=', 2021], ['2']],
            'bare content field' => [['year', '>=', 2021], ['2', '3', '4']],
            'stored string matches as text' => [['metadata.code', '=', '00123'], ['1', '2']],
            'stored number matches a number' => [['metadata.code', '=', 123], ['2']],
            'stored number matches a numeric string' => [['metadata.code', '=', '123'], ['2']],
            'date >=' => [['metadata.published', '>=', '2024-06-01'], ['2', '3', '4']],
            'date <' => [['metadata.published', '<', '2024-06-01'], ['1']],
            'date between' => [['metadata.published', 'between', ['2024-06-01', '2024-12-31']], ['2', '3']],
            'name >' => [['metadata.name', '>', 'M'], ['2', '3']],
            'string in' => [['metadata.cat', 'in', ['a', 'b']], ['1', '2']],
        ];
        $data = [];
        foreach ($this->schemaModes() as $mode => [$external]) {
            foreach ($cases as $name => [[$field, $operator, $value], $expected]) {
                $data["$mode, $name"] = [$external, ['field' => $field, 'operator' => $operator, 'value' => $value], $expected];
            }
        }

        return $data;
    }

    /** @dataProvider typedFilters */
    public function test_json_values_are_compared_by_their_type(bool $external, array $filter, array $expected): void
    {
        $this->assertSame($expected, $this->found($this->storage($external), $filter));
    }

    public function operatorCases(): array
    {
        $cases = [
            'IN' => [['metadata.cat', 'IN', ['a', 'b']], ['1', '2']],
            'Not In' => [['metadata.cat', 'Not In', ['a']], ['2', '3', '4']],
            'LIKE' => [['metadata.cat', 'LIKE', 'a'], ['1']],
            'BETWEEN' => [['metadata.price', 'BETWEEN', [10, 30]], ['2', '3']],
            'IS NULL' => [['metadata.missing', 'IS NULL', null], ['1', '2', '3', '4']],
            'IS NOT NULL' => [['metadata.on_sale', 'IS NOT NULL', null], ['1', '2']],
            'Contains' => [['metadata.cat', 'Contains', 'percent'], ['4']],
            'Exists' => [['metadata.on_sale', 'Exists', null], ['1', '2']],
            'column IN' => [['language', 'IN', ['en']], ['1', '2', '3', '4']],
            'column BETWEEN' => [['timestamp', 'BETWEEN', [1500, 3500]], ['2', '3']],
        ];
        $data = [];
        foreach ($this->schemaModes() as $mode => [$external]) {
            foreach ($cases as $name => [[$field, $operator, $value], $expected]) {
                $data["$mode, $name"] = [$external, ['field' => $field, 'operator' => $operator, 'value' => $value], $expected];
            }
        }

        return $data;
    }

    /** @dataProvider operatorCases */
    public function test_an_operator_works_in_any_case(bool $external, array $filter, array $expected): void
    {
        $this->assertSame($expected, $this->found($this->storage($external), $filter));
    }

    public function unusableFilters(): array
    {
        return [
            'in with a scalar' => [['field' => 'metadata.cat', 'operator' => 'in', 'value' => 'a']],
            'not in with a scalar' => [['field' => 'metadata.cat', 'operator' => 'not in', 'value' => 'a']],
            'column in with a scalar' => [['field' => 'type', 'operator' => 'IN', 'value' => 'default']],
            'between with one value' => [['field' => 'metadata.price', 'operator' => 'between', 'value' => [10]]],
            'between with three values' => [['field' => 'timestamp', 'operator' => 'between', 'value' => [1, 2, 3]]],
            'between with a scalar' => [['field' => 'metadata.price', 'operator' => 'between', 'value' => 10]],
        ];
    }

    /** @dataProvider unusableFilters */
    public function test_a_filter_that_cannot_be_applied_is_refused(array $filter): void
    {
        $storage = $this->storage(true);

        $this->expectException(\InvalidArgumentException::class);
        $storage->search('t', ['query' => 'apple', 'filters' => [$filter]]);
    }

    /** @dataProvider schemaModes */
    public function test_an_operator_without_a_value_needs_no_value_key(bool $external): void
    {
        $storage = $this->storage($external);

        $this->assertSame(['1', '2'], $this->found($storage, ['field' => 'metadata.on_sale', 'operator' => 'exists']));
        $this->assertSame(['3', '4'], $this->found($storage, ['field' => 'metadata.on_sale', 'operator' => 'not exists']));
        $this->assertSame(['1', '2', '3', '4'], $this->found($storage, ['field' => 'type', 'operator' => 'is not null']));
    }

    /** @dataProvider schemaModes */
    public function test_contains_finds_wildcard_characters_as_they_are(bool $external): void
    {
        $storage = $this->storage($external);

        $this->assertSame(['3'], $this->found($storage, ['field' => 'metadata.cat', 'operator' => 'contains', 'value' => '%']));
        $this->assertSame(['3'], $this->found($storage, ['field' => 'metadata.cat', 'operator' => 'contains', 'value' => '%_']));
        $this->assertSame([], $this->found($storage, ['field' => 'metadata.cat', 'operator' => 'contains', 'value' => '\\']));
        $this->assertSame([], $this->found($storage, ['field' => 'id', 'operator' => 'contains', 'value' => '_']));
        $this->assertSame(['1'], $this->found($storage, ['field' => 'content.tags', 'operator' => 'contains', 'value' => 'green']));
    }

    /** @dataProvider schemaModes */
    public function test_a_list_with_keys_filters_a_column(bool $external): void
    {
        $storage = $this->storage($external);

        $this->assertSame(['1', '2'], $this->found($storage, ['field' => 'id', 'operator' => 'in', 'value' => ['x' => '1', 'y' => '2']]));
        $this->assertSame(['3', '4'], $this->found($storage, ['field' => 'id', 'operator' => 'not in', 'value' => ['x' => '1', 'y' => '2']]));
        $this->assertSame(['2', '3'], $this->found($storage, ['field' => 'timestamp', 'operator' => 'between', 'value' => ['from' => 1500, 'to' => 3500]]));
        $this->assertSame(['1', '2'], $this->found($storage, ['field' => 'metadata.cat', 'operator' => 'in', 'value' => ['x' => 'a', 'y' => 'b']]));
    }

    /** @dataProvider schemaModes */
    public function test_a_range_reads_a_number_stored_as_a_string_as_a_number(bool $external): void
    {
        $storage = $this->storage($external);

        // Document 4 stores its year as the string '2023', which as text would sort before '2100'
        $this->assertSame(['3', '4'], $this->found($storage, ['field' => 'year', 'operator' => '>', 'value' => 2021]));
        $this->assertSame(['1', '2', '3', '4'], $this->found($storage, ['field' => 'year', 'operator' => '<', 'value' => 2100]));
        $this->assertSame(['4'], $this->found($storage, ['field' => 'year', 'operator' => 'between', 'value' => ['2022.5', 2024]]));
        // A value that is not a number still compares as text
        $this->assertSame(['3', '4'], $this->found($storage, ['field' => 'metadata.published', 'operator' => '>=', 'value' => '2024-12-01']));
    }
}
