<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Tests\TestCase;

/**
 * A search returns the page it asks for in the order it asks for: by score either way, by a field of
 * the content or the metadata, with field weights, and across several indexes.
 */
class SearchOrderAndPagingTest extends TestCase
{
    private function storage(string $mode, array $fields = ['title', 'content']): SqliteStorage
    {
        $storage = new SqliteStorage();
        $storage->connect(['path' => ':memory:', 'external_content' => $mode === 'external']);
        $storage->createIndex('t', $mode === 'multi' ? ['fields' => $fields] : []);

        return $storage;
    }

    private function insert(SqliteStorage $storage, string $index, string $id, array $content, array $metadata = []): void
    {
        $storage->insert($index, ['id' => $id, 'content' => $content, 'metadata' => $metadata, 'language' => 'en', 'timestamp' => 1000]);
    }

    /** @return string[] */
    private function ids(array $results): array
    {
        return array_column($results, 'id');
    }

    public function modes(): array
    {
        return ['external' => ['external'], 'legacy' => ['legacy'], 'multi-column' => ['multi']];
    }

    /** @dataProvider modes */
    public function test_score_sorts_best_first_when_descending(string $mode): void
    {
        $storage = $this->storage($mode);
        $this->insert($storage, 't', 'low', ['title' => 'x', 'content' => 'apple ' . str_repeat('filler ', 40)]);
        $this->insert($storage, 't', 'mid', ['title' => 'x', 'content' => 'apple apple ' . str_repeat('filler ', 20)]);
        $this->insert($storage, 't', 'high', ['title' => 'x', 'content' => 'apple apple apple apple']);
        for ($i = 0; $i < 5; $i++) {
            $this->insert($storage, 't', "other$i", ['title' => 'x', 'content' => "banana $i"]);
        }

        $this->assertSame(['high', 'mid', 'low'], $this->ids($storage->search('t', ['query' => 'apple'])));
        $this->assertSame(['high', 'mid', 'low'], $this->ids($storage->search('t', ['query' => 'apple', 'sort' => ['_score' => 'desc']])));
        $this->assertSame(['high', 'mid', 'low'], $this->ids($storage->search('t', ['query' => 'apple', 'sort' => ['_score' => 'DESC']])));
        $this->assertSame(['low', 'mid', 'high'], $this->ids($storage->search('t', ['query' => 'apple', 'sort' => ['_score' => 'asc']])));
    }

    /**
     * Ten documents rank first by BM25 with the word many times in the body, ten others have it
     * as their whole title; a title weight puts those first, so the pages come from re-ranked rows.
     */
    private function weighted(string $mode): SqliteStorage
    {
        $storage = $this->storage($mode);
        for ($i = 1; $i <= 10; $i++) {
            $this->insert($storage, 't', "body$i", ['title' => "Fruit notes $i", 'content' => str_repeat('apple ', 30 - $i) . str_repeat('filler ', 20)]);
            $this->insert($storage, 't', "title$i", ['title' => 'apple', 'content' => str_repeat('filler word ', 80 + $i)]);
        }

        return $storage;
    }

    /** @dataProvider modes */
    public function test_field_weighted_pages_follow_one_another(string $mode): void
    {
        $storage = $this->weighted($mode);
        $query = ['query' => 'apple', 'field_weights' => ['title' => 5.0, 'content' => 1.0]];

        $all = $this->ids($storage->search('t', $query + ['limit' => 20]));
        $this->assertCount(20, $all);
        $paged = [];
        foreach ([0, 5, 10, 15] as $offset) {
            $page = $this->ids($storage->search('t', $query + ['limit' => 5, 'offset' => $offset]));
            $this->assertCount(5, $page, "The page at offset $offset holds the limit");
            $paged = array_merge($paged, $page);
        }
        $this->assertSame($all, $paged, 'The pages are the full list, cut in order');
        $this->assertSame([], $storage->search('t', $query + ['limit' => 5, 'offset' => 20]));
    }

    /** @dataProvider modes */
    public function test_field_weights_keep_an_explicit_order(string $mode): void
    {
        $storage = $this->weighted($mode);
        $query = ['query' => 'apple', 'field_weights' => ['title' => 5.0], 'sort' => ['id' => 'asc'], 'limit' => 3];

        $this->assertSame(['body1', 'body10', 'body2'], $this->ids($storage->search('t', $query)));
        $this->assertSame(['body3', 'body4', 'body5'], $this->ids($storage->search('t', $query + ['offset' => 3])));
    }

    public function test_multiple_indexes_are_paged_once_and_counted_in_full(): void
    {
        $storage = $this->storage('legacy');
        foreach (['a', 'b'] as $index) {
            $storage->createIndex($index);
            for ($i = 0; $i < 25; $i++) {
                $extra = $index === 'b' ? ' pear' : '';
                $this->insert($storage, $index, "{$index}{$i}", ['title' => "apple {$index} {$i}", 'content' => str_repeat('apple ', $i + 1) . $extra]);
            }
        }

        $all = $storage->searchMultiple(['a', 'b'], ['query' => 'apple', 'limit' => 50]);
        $this->assertSame(50, $all['total']);
        $this->assertCount(50, $all['results']);

        foreach ([0, 10, 20, 30, 40] as $offset) {
            $page = $storage->searchMultiple(['a', 'b'], ['query' => 'apple', 'limit' => 10, 'offset' => $offset]);
            $this->assertSame(50, $page['total'], 'The total is every match, not the page');
            $this->assertSame(array_slice($this->ids($all['results']), $offset, 10), $this->ids($page['results']));
        }
    }

    /** @dataProvider modes */
    public function test_a_query_of_zero_is_text(string $mode): void
    {
        $storage = $this->storage($mode);
        $this->insert($storage, 't', 'a', ['title' => 'box', 'content' => 'size 0 box']);
        $this->insert($storage, 't', 'b', ['title' => 'pie', 'content' => 'apple pie']);
        $this->insert($storage, 't', 'c', ['title' => 'fruit', 'content' => 'banana']);

        $this->assertSame(['a'], $this->ids($storage->search('t', ['query' => '0'])));
        $this->assertSame(1, $storage->count('t', ['query' => '0']));
    }

    private function people(string $mode): SqliteStorage
    {
        $storage = $this->storage($mode);
        $this->insert($storage, 't', 'c', ['title' => 'Charlie apple', 'content' => 'apple', 'price' => 30], ['author' => 'Charlie', 'date' => '2024-03-01', 'rating' => 10]);
        $this->insert($storage, 't', 'a', ['title' => 'Alice apple', 'content' => 'apple', 'price' => 4], ['author' => 'Alice', 'date' => '2024-12-01', 'rating' => 9]);
        $this->insert($storage, 't', 'b', ['title' => 'Bob apple', 'content' => 'apple', 'price' => 100], ['author' => 'Bob', 'date' => '2024-01-15', 'rating' => 9.5]);

        return $storage;
    }

    public function fieldSorts(): array
    {
        $sorts = [
            'title' => [['title' => 'asc'], ['a', 'b', 'c']],
            'content.title' => [['content.title' => 'desc'], ['c', 'b', 'a']],
            'content number' => [['content.price' => 'asc'], ['a', 'c', 'b']],
            'bare content number' => [['price' => 'desc'], ['b', 'c', 'a']],
            'metadata string' => [['metadata.author' => 'desc'], ['c', 'b', 'a']],
            'metadata date' => [['metadata.date' => 'asc'], ['b', 'c', 'a']],
            'metadata date descending' => [['metadata.date' => 'desc'], ['a', 'c', 'b']],
            'metadata number' => [['metadata.rating' => 'asc'], ['a', 'b', 'c']],
        ];
        $data = [];
        foreach ($this->modes() as $mode => [$name]) {
            foreach ($sorts as $label => [$sort, $expected]) {
                foreach (['text' => 'apple', 'no text' => ''] as $kind => $text) {
                    $data["$mode, $label, $kind"] = [$name, $sort, $text, $expected];
                }
            }
        }

        return $data;
    }

    /** @dataProvider fieldSorts */
    public function test_a_field_sorts_by_its_type(string $mode, array $sort, string $text, array $expected): void
    {
        $storage = $this->people($mode);

        $this->assertSame($expected, $this->ids($storage->search('t', ['query' => $text, 'sort' => $sort])));
    }

    public function test_a_sort_field_must_be_a_safe_path(): void
    {
        $storage = $this->people('external');

        $this->expectException(\InvalidArgumentException::class);
        $storage->search('t', ['query' => 'apple', 'sort' => ["title') DESC; --" => 'asc']]);
    }
}
