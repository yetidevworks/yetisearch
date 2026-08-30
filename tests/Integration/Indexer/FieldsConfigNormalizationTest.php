<?php

namespace YetiSearch\Tests\Integration\Indexer;

use YetiSearch\Exceptions\InvalidArgumentException;
use YetiSearch\Tests\TestCase;

/**
 * The documented shape of the indexer's "fields" option is an associative map
 * of field name to per-field config. A flat list of field names is a natural
 * shorthand that used to be accepted silently and then corrupt the index, so it
 * is now normalized; anything else is rejected loudly.
 */
class FieldsConfigNormalizationTest extends TestCase
{
    private function ftsColumns(\YetiSearch\YetiSearch $search, string $index): array
    {
        $ref = new \ReflectionClass($search);
        $method = $ref->getMethod('getStorage');
        $method->setAccessible(true);
        $storage = $method->invoke($search);

        $storageRef = new \ReflectionClass($storage);
        $columns = $storageRef->getMethod('getFtsColumns');
        $columns->setAccessible(true);

        return $columns->invoke($storage, $index);
    }

    public function test_flat_field_list_produces_the_same_columns_as_the_assoc_form(): void
    {
        $flatSearch = $this->createSearchInstance();
        $flatSearch->createIndex('idx_fields_flat', ['fields' => ['title', 'sku']]);
        $this->createdIndexes[] = 'idx_fields_flat';
        $flatColumns = $this->ftsColumns($flatSearch, 'idx_fields_flat');

        $assocSearch = $this->createSearchInstance();
        $assocSearch->createIndex('idx_fields_assoc', [
            'fields' => [
                'title' => ['boost' => 1.0],
                'sku' => ['boost' => 1.0],
            ],
        ]);
        $this->createdIndexes[] = 'idx_fields_assoc';
        $assocColumns = $this->ftsColumns($assocSearch, 'idx_fields_assoc');

        $this->assertSame(['title', 'sku'], $flatColumns);
        $this->assertSame($assocColumns, $flatColumns);
    }

    public function test_flat_field_list_indexes_and_returns_documents(): void
    {
        $search = $this->createSearchInstance();
        $search->createIndex('idx_fields_flat_search', ['fields' => ['title', 'sku']]);
        $this->createdIndexes[] = 'idx_fields_flat_search';

        $search->index('idx_fields_flat_search', [
            'id' => 'p1',
            'content' => ['title' => 'Blue Widget', 'sku' => 'ABC123'],
        ]);

        $results = $search->search('idx_fields_flat_search', 'Widget');

        $this->assertGreaterThan(0, $results['total']);
        $this->assertSame('p1', $results['results'][0]['id']);
    }

    public function test_mixed_flat_and_assoc_field_config_is_accepted(): void
    {
        $search = $this->createSearchInstance();
        $search->createIndex('idx_fields_mixed', [
            'fields' => ['title', 'sku' => ['boost' => 2.0]],
        ]);
        $this->createdIndexes[] = 'idx_fields_mixed';

        $this->assertSame(['title', 'sku'], $this->ftsColumns($search, 'idx_fields_mixed'));
    }

    public function test_index_option_is_still_honoured_in_the_assoc_form(): void
    {
        $search = $this->createSearchInstance();
        $search->createIndex('idx_fields_not_indexed', [
            'fields' => [
                'title' => ['boost' => 1.0],
                'url' => ['boost' => 1.0, 'index' => false],
            ],
        ]);
        $this->createdIndexes[] = 'idx_fields_not_indexed';

        $this->assertSame(['title'], $this->ftsColumns($search, 'idx_fields_not_indexed'));
    }

    /**
     * @dataProvider malformedFieldConfigs
     */
    public function test_malformed_field_config_throws(array $fields): void
    {
        $search = $this->createSearchInstance();

        $this->expectException(InvalidArgumentException::class);
        $search->createIndex('idx_fields_bad', ['fields' => $fields]);
    }

    public static function malformedFieldConfigs(): array
    {
        return [
            'scalar field config' => [['title' => 'yes']],
            'numeric field config' => [['title' => 3.0]],
            'null field config' => [['title' => null]],
            'non-string field name in list' => [[123]],
            'array as field name in list' => [[['title']]],
            'empty field name in list' => [['']],
            'whitespace field name in list' => [['   ']],
            'empty assoc field name' => [['' => ['boost' => 1.0]]],
        ];
    }

    public function test_field_names_must_be_valid_identifiers(): void
    {
        $search = $this->createSearchInstance();

        $this->expectException(InvalidArgumentException::class);
        $search->createIndex('idx_fields_bad_name', ['fields' => ['title', 'drop table']]);
    }
}
