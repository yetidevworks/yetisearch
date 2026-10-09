<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Exceptions\StorageException;
use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Tests\Integration\StemmingTestCase;
use YetiSearch\YetiSearch;

/**
 * A document written again loses the chunks of its earlier version that the new one does not have,
 * in the transaction of the write, in every way an index is stored.
 */
class StaleChunksTest extends StemmingTestCase
{
    /** @var string[] */
    private array $statements = [];

    protected function tearDown(): void
    {
        $this->statements = [];
        parent::tearDown();
    }

    public function modes(): array
    {
        $cases = [];
        foreach (['external', 'legacy', 'multi'] as $mode) {
            foreach ([false, true] as $stemming) {
                $cases[$mode . ($stemming ? ', stemming' : ', plain')] = [$mode, $stemming];
            }
        }

        return $cases;
    }

    private function open(string $mode, bool $stemming = false, array $config = []): YetiSearch
    {
        $search = $this->openSearch($mode, array_replace_recursive(['indexer' => ['chunk_size' => 200, 'chunk_overlap' => 0]], $config));
        $this->createIndex($search, $mode, ['stemming' => $stemming, 'fields' => ['title', 'content']]);

        return $search;
    }

    /** About 120 characters a sentence, so a text of n sentences makes about n/1.5 chunks of 200 */
    private function longText(string $word, int $sentences = 12): string
    {
        $text = [];
        for ($i = 1; $i <= $sentences; $i++) {
            $text[] = "Sentence number {$i} is about the {$word} and a few other plain words to fill the line out nicely.";
        }

        return implode(' ', $text);
    }

    private function doc(string $id, string $content, array $extra = []): array
    {
        return ['id' => $id, 'content' => ['title' => ucfirst($id), 'content' => $content]] + $extra;
    }

    /** @return string[] */
    private function storedIds(YetiSearch $search, string $like = '%'): array
    {
        $stmt = $this->pdo($search)->prepare('SELECT id FROM ' . self::INDEX . ' WHERE id LIKE ? ORDER BY id');
        $stmt->execute([$like]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return string[] */
    private function found(YetiSearch $search, string $query): array
    {
        return $this->ids($search->search(self::INDEX, $query, ['fuzzy' => false, 'limit' => 50]));
    }

    private function rows(YetiSearch $search, string $table): int
    {
        return (int)$this->pdo($search)->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    /** @dataProvider modes */
    public function test_a_document_indexed_again_with_short_content_loses_its_chunks(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming);
        $search->index(self::INDEX, $this->doc('page', $this->longText('giraffe')));
        $chunks = $this->storedIds($search, 'page#chunk%');
        $this->assertGreaterThan(2, count($chunks), 'The long text is in chunks');
        $this->assertContains('page#chunk0', $this->found($search, 'giraffe'));

        $search->index(self::INDEX, $this->doc('page', 'short now'));

        $this->assertSame(['page'], $this->storedIds($search), 'Every chunk is gone');
        $this->assertSame([], $this->found($search, 'giraffe'), 'A word of the old text finds nothing');
        $this->assertSame(['page'], $this->found($search, 'short'));
        $this->assertNotContains('giraffe', $this->vocabulary($search));
        $this->assertSame(1, $this->rows($search, self::INDEX));
        $this->assertIntegrity($search);
    }

    /** @dataProvider modes */
    public function test_a_document_with_fewer_chunks_keeps_the_ones_it_has_and_loses_the_rest(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming);
        $search->index(self::INDEX, $this->doc('page', $this->longText('giraffe', 14)));
        $before = $this->storedIds($search, 'page#chunk%');
        $search->index(self::INDEX, $this->doc('page', $this->longText('walrus', 5)));
        $after = $this->storedIds($search, 'page#chunk%');

        $this->assertGreaterThan(1, count($after));
        $this->assertLessThan(count($before), count($after));
        $this->assertSame(array_slice($before, 0, count($after)), $after, 'The chunks it has are chunks 0 to n-1');
        $this->assertSame([], $this->found($search, 'giraffe'));
        $this->assertSame(array_merge(['page'], $after), $this->found($search, 'walrus'));
        $this->assertIntegrity($search);
    }

    /** @dataProvider modes */
    public function test_chunks_of_other_documents_are_left_alone(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming);
        $search->indexBatch(self::INDEX, [
            $this->doc('page', $this->longText('giraffe')),
            $this->doc('other', $this->longText('zebra')),
            $this->doc('page2', $this->longText('okapi')),
        ]);
        $others = array_merge($this->storedIds($search, 'other%'), $this->storedIds($search, 'page2%'));

        $search->index(self::INDEX, $this->doc('page', 'short now'));

        $this->assertSame($others, array_merge($this->storedIds($search, 'other%'), $this->storedIds($search, 'page2%')));
        $this->assertSame(['page'], array_values(array_diff($this->storedIds($search), $others)));
        $this->assertContains('other#chunk0', $this->found($search, 'zebra'));
        $this->assertContains('page2#chunk0', $this->found($search, 'okapi'));
        $this->assertIntegrity($search);
    }

    /** @dataProvider modes */
    public function test_a_batch_that_writes_documents_again_drops_their_stale_chunks(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming);
        $search->indexBatch(self::INDEX, [
            $this->doc('a', $this->longText('giraffe')),
            $this->doc('b', $this->longText('zebra')),
            $this->doc('c', $this->longText('okapi')),
        ]);
        $bChunks = $this->storedIds($search, 'b#chunk%');

        $search->indexBatch(self::INDEX, [
            $this->doc('a', 'short now'),
            $this->doc('c', $this->longText('walrus', 4)),
            $this->doc('d', 'brand new'),
        ]);

        $this->assertSame([], $this->found($search, 'giraffe'));
        $this->assertSame([], $this->found($search, 'okapi'));
        $this->assertSame($bChunks, $this->storedIds($search, 'b#chunk%'), 'A document that is not in the batch is untouched');
        $this->assertSame(['a'], $this->storedIds($search, 'a%'));
        $this->assertGreaterThan(0, count($this->storedIds($search, 'c#chunk%')));
        $this->assertLessThan(5, count($this->storedIds($search, 'c#chunk%')));
        $this->assertContains('c#chunk0', $this->found($search, 'walrus'));
        $this->assertNotContains('okapi', $this->vocabulary($search));
        $this->assertIntegrity($search);
    }

    /** @dataProvider modes */
    public function test_a_document_repeated_in_a_batch_keeps_the_chunks_of_its_last_version_only(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming);
        $chunked = $this->doc('page', 'first version', ['chunks' => [['content' => 'old giraffe'], ['content' => 'old zebra']]]);

        // A short version after a chunked one
        $search->indexBatch(self::INDEX, [$chunked, $this->doc('page', 'short replacement'), $this->doc('other', 'plain')]);

        $this->assertSame(['other', 'page'], $this->storedIds($search), 'The chunks of the version that lost are not kept');
        $this->assertSame([], $this->found($search, 'giraffe'));
        $this->assertSame([], $this->found($search, 'zebra'));
        $this->assertSame(['page'], $this->found($search, 'replacement'));
        $this->assertNotContains('zebra', $this->vocabulary($search));

        // A chunked version after a chunked one with more chunks: the chunks of the last are the ones left
        $search->indexBatch(self::INDEX, [
            $this->doc('page', 'second', ['chunks' => [['content' => 'one'], ['content' => 'two'], ['content' => 'three']]]),
            $this->doc('page', 'third', ['chunks' => [['content' => 'lemur']]]),
        ]);
        $this->assertSame(['other', 'page', 'page#chunk0'], $this->storedIds($search));
        $this->assertSame(['page#chunk0'], $this->found($search, 'lemur'));
        $this->assertSame([], $this->found($search, 'three'));

        // A chunked version after a short one
        $search->indexBatch(self::INDEX, [$this->doc('page', 'short again'), $this->doc('page', 'long', ['chunks' => [['content' => 'okapi'], ['content' => 'walrus']]])]);
        $this->assertSame(['other', 'page', 'page#chunk0', 'page#chunk1'], $this->storedIds($search));
        $this->assertSame([], $this->found($search, 'lemur'));
        $this->assertSame(['page#chunk1'], $this->found($search, 'walrus'));
        $this->assertIntegrity($search);
    }

    /** @dataProvider modes */
    public function test_chunks_of_a_document_repeated_in_a_batch_do_not_cost_those_of_another(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming);

        $search->indexBatch(self::INDEX, [
            $this->doc('a', 'first', ['chunks' => [['content' => 'giraffe'], ['content' => 'zebra']]]),
            $this->doc('b', 'plain', ['chunks' => [['content' => 'okapi']]]),
            $this->doc('a', 'last'),
            $this->doc('b', 'plain again', ['chunks' => [['content' => 'walrus']]]),
        ]);

        $this->assertSame(['a', 'b', 'b#chunk0'], $this->storedIds($search));
        $this->assertSame(['b#chunk0'], $this->found($search, 'walrus'));
        $this->assertSame([], $this->found($search, 'okapi'));
        $this->assertSame([], $this->found($search, 'zebra'));
    }

    /** @dataProvider modes */
    public function test_a_document_repeated_in_a_queued_batch_keeps_the_chunks_of_its_last_version_only(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming, ['indexer' => ['auto_flush' => false, 'batch_size' => 100]]);

        $search->indexBatch(self::INDEX, [$this->doc('page', 'first version', ['chunks' => [['content' => 'old giraffe'], ['content' => 'old zebra']]])]);
        $search->indexBatch(self::INDEX, [$this->doc('page', 'short replacement')]);
        $search->getIndexer(self::INDEX)->flush();

        $this->assertSame(['page'], $this->storedIds($search));
        $this->assertSame([], $this->found($search, 'zebra'));
    }

    /** @dataProvider modes */
    public function test_the_storage_resolves_a_document_repeated_in_a_batch_to_its_last_group(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming);
        $storage = $this->storage($search);
        $chunk = function (string $id, string $word) {
            return $this->doc($id, $word, ['metadata' => ['is_chunk' => true]]);
        };

        // The chunks of a document come before it
        $storage->insertBatch(self::INDEX, [
            $chunk('page#chunk0', 'giraffe'),
            $chunk('page#chunk1', 'zebra'),
            $this->doc('page', 'parent'),
            $chunk('page#chunk0', 'walrus'),
            $this->doc('page', 'parent again'),
            $this->doc('other', 'plain'),
        ]);

        $this->assertSame(['other', 'page', 'page#chunk0'], $this->storedIds($search));
        $this->assertSame(['page#chunk0'], $this->found($search, 'walrus'));
        $this->assertSame([], $this->found($search, 'zebra'));
        $this->assertIntegrity($search);
    }

    /** @dataProvider modes */
    public function test_update_drops_stale_chunks_of_a_document_that_no_longer_chunks(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming);
        $search->index(self::INDEX, $this->doc('page', $this->longText('giraffe')));
        $this->assertContains('page#chunk0', $this->found($search, 'giraffe'));

        // A single storage document goes through the storage's update()
        $search->update(self::INDEX, $this->doc('page', 'short now'));

        $this->assertSame(['page'], $this->storedIds($search));
        $this->assertSame([], $this->found($search, 'giraffe'));

        // and a chunked one through insertBatch()
        $search->update(self::INDEX, $this->doc('page', $this->longText('okapi')));
        $this->assertGreaterThan(1, count($this->storedIds($search)));
        $search->update(self::INDEX, $this->doc('page', $this->longText('walrus', 3)));
        $this->assertSame([], $this->found($search, 'okapi'));
        $this->assertContains('page', $this->found($search, 'walrus'));
        $this->assertIntegrity($search);
    }

    /** @dataProvider modes */
    public function test_pre_chunked_documents_drop_the_chunks_they_no_longer_have(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming);
        $search->index(self::INDEX, $this->doc('page', 'parent text', ['chunks' => ['first giraffe', 'second zebra', 'third okapi']]));
        $this->assertSame(['page', 'page#chunk0', 'page#chunk1', 'page#chunk2'], $this->storedIds($search));

        $search->index(self::INDEX, $this->doc('page', 'parent text', ['chunks' => ['only walrus']]));
        $this->assertSame(['page', 'page#chunk0'], $this->storedIds($search));
        $this->assertSame([], $this->found($search, 'zebra'));
        $this->assertSame([], $this->found($search, 'okapi'));

        $search->index(self::INDEX, $this->doc('page', 'parent text'));
        $this->assertSame(['page'], $this->storedIds($search));
        $this->assertSame([], $this->found($search, 'walrus'));
        $this->assertIntegrity($search);
    }

    /** @dataProvider modes */
    public function test_the_storage_drops_stale_chunks_on_insert_and_on_batch(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming);
        $storage = $this->storage($search);
        $storage->insertBatch(self::INDEX, [
            $this->doc('page#chunk0', 'giraffe'),
            $this->doc('page#chunk1', 'zebra'),
            $this->doc('page#chunk2', 'okapi'),
            $this->doc('page', 'parent'),
        ]);

        $storage->insert(self::INDEX, $this->doc('page', 'parent again'));
        $this->assertSame(['page'], $this->storedIds($search));

        $storage->insertBatch(self::INDEX, [$this->doc('page#chunk0', 'walrus'), $this->doc('page#chunk1', 'lemur'), $this->doc('page', 'parent')]);
        $storage->insertBatch(self::INDEX, [$this->doc('page#chunk0', 'walrus'), $this->doc('page', 'parent')]);
        $this->assertSame(['page', 'page#chunk0'], $this->storedIds($search));
        $this->assertSame([], $this->found($search, 'lemur'));
        $this->assertIntegrity($search);
    }

    /** @dataProvider modes */
    public function test_a_document_with_chunk_in_its_id_deletes_nothing_that_is_not_its_own(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming);
        $search->indexBatch(self::INDEX, [
            $this->doc('a', 'parent'),
            $this->doc('a#chunk0', 'zero'),
            $this->doc('a#chunk1', 'one'),
            $this->doc('a#chunk2', 'two'),
            $this->doc('a#chunky', 'not a chunk number'),
            $this->doc('a#chunk', 'no number'),
            $this->doc('ab#chunk0', 'another parent'),
            $this->doc('a#chunk10', 'ten'),
        ]);
        $all = $this->storedIds($search);

        // Written alone, a chunk-like id takes only what is under it
        $search->index(self::INDEX, $this->doc('a#chunk1', 'one, changed'));
        $this->assertSame($all, $this->storedIds($search));
        $search->update(self::INDEX, $this->doc('a#chunk1', 'one, changed again'));
        $this->storage($search)->insert(self::INDEX, $this->doc('a#chunk1', 'one, and again'));
        $this->storage($search)->insertBatch(self::INDEX, [$this->doc('a#chunk1', 'one, and once more')]);
        $this->assertSame($all, $this->storedIds($search));

        // Its own chunks are the ones under its id
        $this->storage($search)->insert(self::INDEX, $this->doc('a#chunk1#chunk0', 'under one'));
        $this->storage($search)->insert(self::INDEX, $this->doc('a#chunk1', 'one, once more'));
        $this->assertSame($all, $this->storedIds($search));

        // The parent loses chunks named a#chunk followed by a number, and nothing else
        $search->index(self::INDEX, $this->doc('a', 'parent, alone'));
        $this->assertSame(['a', 'a#chunk', 'a#chunky', 'ab#chunk0'], $this->storedIds($search));
        $this->assertIntegrity($search);
    }

    /** @dataProvider modes */
    public function test_ids_with_like_wildcards_match_only_themselves(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming);
        $search->indexBatch(self::INDEX, [
            $this->doc('a_b', 'underscore'),
            $this->doc('a_b#chunk0', 'underscore chunk'),
            $this->doc('axb', 'other'),
            $this->doc('axb#chunk0', 'other chunk'),
            $this->doc('a%', 'percent'),
            $this->doc('a%#chunk0', 'percent chunk'),
            $this->doc('azzz#chunk0', 'percent would match this'),
        ]);

        $search->index(self::INDEX, $this->doc('a_b', 'underscore, alone'));
        $search->index(self::INDEX, $this->doc('a%', 'percent, alone'));

        $this->assertSame(['a%', 'a_b', 'axb', 'axb#chunk0', 'azzz#chunk0'], $this->storedIds($search));
    }

    /** @dataProvider modes */
    public function test_a_stale_chunk_loses_everything_a_deleted_document_loses(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming, ['storage' => ['search' => ['enable_fuzzy' => true, 'fuzzy_algorithm' => 'levenshtein']]]);
        $geo = ['geo' => ['lat' => 40.0, 'lng' => -105.0]];
        $chunks = ['chunks' => [
            ['content' => 'first giraffe'],
            ['content' => 'second zebra'],
            ['content' => 'third okapi'],
        ]];
        // The same index with documents that are deleted, to compare with
        $search->index(self::INDEX, $this->doc('gone', 'parent', $geo));
        $search->index(self::INDEX, $this->doc('page', 'parent', $geo + $chunks));
        $search->index(self::INDEX, $this->doc('keep', 'parent kept', $geo + ['chunks' => [['content' => 'kept okapi']]]));
        $this->assertSame(7, $this->rows($search, self::INDEX . '_spatial'));
        $legacy = $mode !== 'external';
        if ($legacy) {
            $this->assertSame(7, $this->rows($search, self::INDEX . '_id_map'));
        }
        $termsOf = function (string $id) use ($search) {
            $stmt = $this->pdo($search)->prepare('SELECT COUNT(*) FROM ' . self::INDEX . '_terms WHERE document_id = ?');
            $stmt->execute([$id]);

            return (int)$stmt->fetchColumn();
        };
        $this->assertGreaterThan(0, $termsOf('page#chunk1'));

        $search->index(self::INDEX, $this->doc('page', 'parent', $geo));

        $this->assertSame(['gone', 'keep', 'keep#chunk0', 'page'], $this->storedIds($search));
        $this->assertSame(0, $termsOf('page#chunk0'));
        $this->assertSame(0, $termsOf('page#chunk1'));
        $this->assertSame(0, $termsOf('page#chunk2'));
        $this->assertGreaterThan(0, $termsOf('page'), 'The parent has its terms');
        $this->assertGreaterThan(0, $termsOf('keep#chunk0'), 'Another document keeps its chunk terms');
        $this->assertSame(4, $this->rows($search, self::INDEX . '_spatial'), 'The spatial rows of the chunks are gone');
        if ($legacy) {
            $this->assertSame(4, $this->rows($search, self::INDEX . '_id_map'));
        }
        $this->assertNotContains('zebra', $this->vocabulary($search));
        $this->assertContains('okapi', $this->vocabulary($search), 'The chunk of another document still has its words');
        $this->assertSame(['keep#chunk0'], $this->found($search, 'okapi'));
        $this->assertIntegrity($search);

        // What a delete leaves is what the stale chunks leave
        $search->delete(self::INDEX, 'gone');
        $this->assertSame(3, $this->rows($search, self::INDEX . '_spatial'));
    }

    /** @dataProvider modes */
    public function test_an_index_without_spatial_tables_still_writes_documents_again(string $mode, bool $stemming): void
    {
        $search = $this->openSearch($mode, ['indexer' => ['chunk_size' => 200, 'chunk_overlap' => 0]]);
        $this->createIndex($search, $mode, ['stemming' => $stemming, 'enable_spatial' => false, 'fields' => ['title', 'content']]);
        $search->index(self::INDEX, $this->doc('page', $this->longText('giraffe')));
        $this->assertGreaterThan(1, count($this->storedIds($search)));

        $search->index(self::INDEX, $this->doc('page', 'short now'));

        $this->assertSame(['page'], $this->storedIds($search));
        $this->assertSame([], $this->found($search, 'giraffe'));
    }

    /** @dataProvider modes */
    public function test_a_write_that_fails_keeps_the_chunks_it_would_have_dropped(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming);
        $storage = $this->storage($search);
        $search->index(self::INDEX, $this->doc('page', $this->longText('giraffe')));
        $before = $this->storedIds($search);

        try {
            $storage->insert(self::INDEX, ['id' => 'page', 'content' => ['title' => 'Page', 'content' => NAN]]);
            $this->fail('A content that cannot be encoded was stored');
        } catch (StorageException $e) {
            $this->assertStringContainsString('encode', $e->getMessage());
        }
        $this->assertSame($before, $this->storedIds($search));

        try {
            $storage->insertBatch(self::INDEX, [
                $this->doc('page', 'short now'),
                ['id' => 'bad', 'content' => ['title' => 'Bad', 'content' => NAN]],
            ]);
            $this->fail('A content that cannot be encoded was stored');
        } catch (StorageException $e) {
            $this->assertStringContainsString('encode', $e->getMessage());
        }
        $this->assertSame($before, $this->storedIds($search), 'The batch was rolled back with its deletes');
        $this->assertContains('page#chunk0', $this->found($search, 'giraffe'));
        $this->assertFalse($this->pdo($search)->inTransaction());
        $this->assertIntegrity($search);
    }

    /** @dataProvider modes */
    public function test_the_chunk_lookup_is_a_range_search_on_the_id_index(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming);
        $search->index(self::INDEX, $this->doc('page', $this->longText('giraffe')));
        $this->trace($this->storage($search));

        $search->index(self::INDEX, $this->doc('page', 'short now'));

        $lookups = array_values(array_filter($this->statements, function ($statement) {
            return strpos($statement, 'FROM ' . self::INDEX . ' WHERE id >= ? AND id < ?') !== false;
        }));
        $this->assertNotEmpty($lookups, 'The lookup was run');
        $pdo = $this->pdo($search);
        foreach ($lookups as $sql) {
            $plan = $pdo->prepare('EXPLAIN QUERY PLAN ' . $sql);
            $plan->execute(['page#chunk', 'page#chunl']);
            $details = implode(' | ', array_column($plan->fetchAll(), 'detail'));
            $this->assertStringContainsString('SEARCH', $details);
            $this->assertStringContainsString('USING', $details);
            $this->assertStringContainsString('id>? AND id<?', $details);
            $this->assertStringNotContainsString('SCAN', $details);
        }
        $this->assertCount(1, $lookups, 'One lookup for one document');
        foreach ($this->statements as $statement) {
            $this->assertStringNotContainsStringIgnoringCase(' LIKE ', $statement, 'No statement of the write scans with LIKE');
        }
    }

    /** @dataProvider modes */
    public function test_a_batch_of_new_documents_looks_up_each_parent_once_and_deletes_nothing(string $mode, bool $stemming): void
    {
        $search = $this->open($mode, $stemming);
        $this->trace($this->storage($search));
        $documents = [];
        for ($i = 0; $i < 10; $i++) {
            $documents[] = $this->doc("new{$i}", $this->longText("word{$i}", 3));
        }

        $search->indexBatch(self::INDEX, $documents);

        $lookups = array_filter($this->statements, function ($statement) {
            return strpos($statement, 'FROM ' . self::INDEX . ' WHERE id >= ? AND id < ?') !== false;
        });
        // A lookup is prepared once and run for each document that is not itself a chunk
        $this->assertCount(1, $lookups);
        $this->assertSame([], array_filter($this->statements, function ($statement) {
            return stripos($statement, 'DELETE FROM ' . self::INDEX . ' WHERE id IN') === 0;
        }), 'A first write has nothing to delete');
    }

    /** A connection that records what is prepared through it, put in place of the storage's own */
    private function trace(SqliteStorage $storage): void
    {
        $log = &$this->statements;
        $property = new \ReflectionProperty(SqliteStorage::class, 'connection');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $path = $property->getValue($storage)->query('PRAGMA database_list')->fetch()['file'];
        $property->setValue($storage, new class ("sqlite:{$path}", $log) extends \PDO {
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
        });
        $version = new \ReflectionProperty(SqliteStorage::class, 'cacheDataVersion');
        if (PHP_VERSION_ID < 80100) {
            $version->setAccessible(true);
        }
        $version->setValue($storage, null);
        $this->statements = [];
    }
}
