<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Exceptions\StorageException;
use YetiSearch\Stemmer\StemmerFactory;
use YetiSearch\Stemmer\StemmerInterface;
use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Tests\Integration\StemmingTestCase;
use YetiSearch\YetiSearch;

/**
 * YetiSearch 2.5.x ran FTS5's own 'rebuild' command in deleteByIdPrefix(), which indexed the raw
 * JSON of an external-content index, so every field name became a word of every document. An
 * index that records it was built by 2.6.1 or later is sound; one that does not has its FTS table
 * built again, once, by its first write.
 */
class PollutedIndexHealingTest extends StemmingTestCase
{
    private const KEY = 'fts_built_by';

    /** @var string[] The statements the traced connection ran through exec(), prepare() and query() */
    private array $statements = [];

    protected function tearDown(): void
    {
        $this->statements = [];
        parent::tearDown();
    }

    private function doc(string $id, string $content): array
    {
        return ['id' => $id, 'content' => ['title' => ucfirst($id), 'content' => $content, 'route' => '/' . $id]];
    }

    /** @return string[] */
    private function found(YetiSearch $search, string $query): array
    {
        return $this->ids($search->search(self::INDEX, $query, ['fuzzy' => false]));
    }

    /** An external-content index of five documents, in the state 2.5.x left after a prefix delete */
    private function polluted(bool $stemming = false, ?string $path = null): YetiSearch
    {
        $search = $this->openSearch('external', $path !== null ? ['storage' => ['path' => $path]] : []);
        $this->createIndex($search, 'external', ['stemming' => $stemming]);
        $search->indexBatch(self::INDEX, [
            $this->doc('alpha', 'dogs running in the park'),
            $this->doc('beta', 'cats walking home'),
            $this->doc('gamma', 'birds singing loudly'),
            $this->doc('delta', 'fish swimming slowly'),
            $this->doc('epsilon', 'horses galloping past'),
        ]);
        $this->pollute($this->raw($search));

        return $search;
    }

    /**
     * Another connection to the database. What a storage changes through its own connection is
     * known to it, so a test that stands in for another process has to use one of its own.
     */
    private function raw(YetiSearch $search): \PDO
    {
        $file = $this->pdo($search)->query('PRAGMA database_list')->fetch()['file'];

        return new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    private function pollute(\PDO $pdo): void
    {
        $pdo->exec('INSERT INTO ' . self::INDEX . '_fts(' . self::INDEX . "_fts) VALUES('rebuild')");
        $pdo->exec('DELETE FROM ' . self::INDEX . '_meta WHERE key = ' . $pdo->quote(self::KEY));
    }

    private function builtBy(\PDO $pdo): ?string
    {
        $value = $pdo->query('SELECT value FROM ' . self::INDEX . '_meta WHERE key = ' . $pdo->quote(self::KEY))->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @return string[] */
    private function storedIds(YetiSearch $search): array
    {
        return $this->pdo($search)->query('SELECT id FROM ' . self::INDEX . ' ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** What FTS5 holds for a word, whatever the engine would do with a query for it */
    private function ftsMatches(\PDO $pdo, string $word): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . self::INDEX . '_fts WHERE ' . self::INDEX . '_fts MATCH ?');
        $stmt->execute([$word]);

        return (int)$stmt->fetchColumn();
    }

    private function assertHealed(YetiSearch $search): void
    {
        $this->assertSame('2.6.1', $this->builtBy($this->pdo($search)));
        $this->assertSame(0, $this->ftsMatches($this->pdo($search), 'title'), 'A field name is not a word of the documents');
        $this->assertSame(0, $this->ftsMatches($this->pdo($search), 'route'));
        $this->assertSame([], $this->found($search, 'title'));
        $this->assertIntegrity($search);
    }

    /** A connection that records what is run through it, put in place of the storage's own */
    private function trace(SqliteStorage $storage): void
    {
        $log = &$this->statements;
        $property = new \ReflectionProperty(SqliteStorage::class, 'connection');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $original = $property->getValue($storage);
        $path = $original->query('PRAGMA database_list')->fetch()['file'];
        $traced = new class ("sqlite:{$path}", $log) extends \PDO {
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
            public function exec($statement)
            {
                $this->log[] = preg_replace('/\s+/', ' ', trim($statement));

                return parent::exec($statement);
            }

            #[\ReturnTypeWillChange]
            public function prepare($query, $options = [])
            {
                $this->log[] = preg_replace('/\s+/', ' ', trim($query));

                return parent::prepare($query, $options);
            }
        };
        $property->setValue($storage, $traced);
        // The new connection has its own data_version, so what the storage has read is read again
        $version = new \ReflectionProperty(SqliteStorage::class, 'cacheDataVersion');
        if (PHP_VERSION_ID < 80100) {
            $version->setAccessible(true);
        }
        $version->setValue($storage, null);
    }

    /** How many times the FTS table was dropped to be made again since the last call */
    private function rebuilds(): int
    {
        $count = 0;
        foreach ($this->statements as $statement) {
            if (stripos($statement, 'DROP TABLE IF EXISTS ' . self::INDEX . '_fts') === 0 && substr($statement, -4) === '_fts') {
                $count++;
            }
        }
        $this->statements = [];

        return $count;
    }

    public function test_a_delete_in_the_state_2_5_x_left_does_not_leave_an_index_that_cannot_be_searched(): void
    {
        // Field names are words of every document, and the first delete of 2.6.0 turned that
        // into an index that fails with 'database disk image is malformed' when searched for them
        $search = $this->polluted();
        $this->assertSame(5, $this->ftsMatches($this->pdo($search), 'title'));
        $this->assertSame(5, count($this->found($search, 'title')));
        $this->assertNull($this->builtBy($this->pdo($search)));

        $search->delete(self::INDEX, 'beta');

        $this->assertSame([], $this->found($search, 'title'));
        $this->assertSame(['gamma'], $this->found($search, 'birds'));
        $this->assertSame([], $this->found($search, 'cats'));
        $this->assertHealed($search);
    }

    public function writes(): array
    {
        $cases = [];
        foreach ([false, true] as $stemming) {
            $suffix = $stemming ? ', stemming' : '';
            $cases['insert' . $suffix] = [$stemming, function (YetiSearch $s, self $t) {
                $s->index(self::INDEX, $t->doc('zeta', 'lions roaring'));
            }, ['alpha', 'beta', 'delta', 'epsilon', 'gamma', 'zeta']];
            $cases['update' . $suffix] = [$stemming, function (YetiSearch $s, self $t) {
                $s->update(self::INDEX, $t->doc('beta', 'mice squeaking'));
            }, ['alpha', 'beta', 'delta', 'epsilon', 'gamma']];
            $cases['batch' . $suffix] = [$stemming, function (YetiSearch $s, self $t) {
                $s->indexBatch(self::INDEX, [$t->doc('zeta', 'lions roaring'), $t->doc('beta', 'mice squeaking')]);
            }, ['alpha', 'beta', 'delta', 'epsilon', 'gamma', 'zeta']];
            $cases['delete' . $suffix] = [$stemming, function (YetiSearch $s) {
                $s->delete(self::INDEX, 'beta');
            }, ['alpha', 'delta', 'epsilon', 'gamma']];
            $cases['prefix delete' . $suffix] = [$stemming, function (YetiSearch $s) {
                $s->deleteByIdPrefix(self::INDEX, 'ep');
            }, ['alpha', 'beta', 'delta', 'gamma']];
            $cases['chunked update' . $suffix] = [$stemming, function (YetiSearch $s) {
                $s->update(self::INDEX, [
                    'id' => 'beta',
                    'content' => ['title' => 'Beta', 'content' => 'mice squeaking'],
                    'chunks' => [['content' => ['content' => 'moles digging']]],
                ]);
            }, ['alpha', 'beta', 'beta#chunk0', 'delta', 'epsilon', 'gamma']];
        }

        return $cases;
    }

    /** @dataProvider writes */
    public function test_the_first_write_builds_the_fts_table_again_and_records_it(bool $stemming, callable $write, array $remaining): void
    {
        $search = $this->polluted($stemming);
        $this->assertSame(5, $this->ftsMatches($this->pdo($search), 'title'));

        $write($search, $this);

        $this->assertHealed($search);
        $this->assertSame($remaining, $this->storedIds($search));
        $this->assertSame(['gamma'], $this->found($search, 'birds'));
    }

    public function test_a_healed_index_keeps_its_stems_and_its_settings(): void
    {
        $search = $this->polluted(true);
        $this->assertSame('english', $this->storage($search)->stemmingFor(self::INDEX));

        $search->delete(self::INDEX, 'beta');

        $this->assertHealed($search);
        $this->assertSame('english', $this->storage($search)->stemmingFor(self::INDEX));
        $this->assertSame(['alpha'], $this->found($search, 'runs'), 'dogs running is found by its stem');
        $this->assertSame(['epsilon'], $this->found($search, 'gallop'));
        $this->assertContains('_stems', $this->columns($search, self::INDEX . '_fts'));
    }

    public function test_the_fts_table_is_built_again_once(): void
    {
        $search = $this->polluted();
        $this->trace($this->storage($search));

        $search->delete(self::INDEX, 'beta');
        $this->assertSame(1, $this->rebuilds());

        $search->index(self::INDEX, $this->doc('zeta', 'lions roaring'));
        $search->delete(self::INDEX, 'zeta');
        $search->indexBatch(self::INDEX, [$this->doc('eta', 'owls hooting')]);
        $search->deleteByIdPrefix(self::INDEX, 'eta');
        $this->assertSame(0, $this->rebuilds());
        $this->assertHealed($search);
    }

    public function test_a_search_does_not_build_the_fts_table_again(): void
    {
        $search = $this->polluted();
        $this->trace($this->storage($search));

        $this->assertSame(['alpha'], $this->found($search, 'dogs'));
        $this->assertCount(5, $this->found($search, 'title'));
        $search->getStats(self::INDEX);
        $search->search(self::INDEX, 'birds', ['fuzzy' => true]);
        $search->multiSearch([self::INDEX], 'cats');

        $this->assertSame(0, $this->rebuilds());
        $this->assertNull($this->builtBy($this->pdo($search)));
        $this->assertSame(5, $this->ftsMatches($this->pdo($search), 'title'));
    }

    public function test_a_write_that_matches_nothing_does_not_build_the_fts_table_again(): void
    {
        $search = $this->polluted();
        $this->trace($this->storage($search));

        $this->assertSame(0, $search->deleteByIdPrefix(self::INDEX, 'nothing-has-this-prefix'));
        $search->indexBatch(self::INDEX, []);

        $this->assertSame(0, $this->rebuilds());
        $this->assertNull($this->builtBy($this->pdo($search)));
    }

    public function test_a_rebuild_that_fails_refuses_the_write_and_changes_nothing(): void
    {
        $search = $this->polluted(true);
        StemmerFactory::register('english', new class () implements StemmerInterface {
            public function stem(string $word): string
            {
                throw new \RuntimeException('the stemmer is broken');
            }

            public function getLanguage(): string
            {
                return 'english';
            }
        });

        try {
            // A delete makes no stems, so nothing but the rebuild can fail here
            $search->delete(self::INDEX, 'beta');
            $this->fail('The write went on after its rebuild failed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('the stemmer is broken', $e->getMessage());
        }

        $this->assertNull($this->builtBy($this->pdo($search)), 'The index is not marked as built');
        $this->assertSame(5, $this->ftsMatches($this->pdo($search), 'title'), 'The FTS table is the one it was');
        $this->assertSame('beta', $this->pdo($search)->query("SELECT id FROM " . self::INDEX . " WHERE id = 'beta'")->fetchColumn(), 'The document is still there');
        $this->assertFalse($this->pdo($search)->inTransaction(), 'No transaction is left open');

        // Once the stemmer works the same write heals the index and goes through
        StemmerFactory::reset();
        $search->delete(self::INDEX, 'beta');
        $this->assertHealed($search);
        $this->assertSame(['alpha'], $this->found($search, 'runs'));
        $this->assertSame([], $this->found($search, 'cats'));
    }

    public function test_a_rebuild_that_fails_with_a_database_error_throws_a_storage_exception_and_is_undone(): void
    {
        $path = $this->getTestDbPath();
        $search = $this->polluted(false, $path);
        $pdo = $this->raw($search);
        // The FTS table cannot be made with a column twice, and by then the old one has been dropped
        $pdo->exec('UPDATE ' . self::INDEX . "_meta SET value = '[\"content\",\"content\"]' WHERE key = 'fts_columns'");
        $storage = new SqliteStorage();
        $storage->connect(['path' => $path, 'external_content' => true]);

        try {
            $storage->delete(self::INDEX, 'beta');
            $this->fail('The write went on after its rebuild failed');
        } catch (StorageException $e) {
            $this->assertStringContainsString('full-text index', $e->getMessage());
        }

        $this->assertNull($this->builtBy($pdo));
        $this->assertSame('beta', $pdo->query('SELECT id FROM ' . self::INDEX . " WHERE id = 'beta'")->fetchColumn());
        $this->assertSame(5, $this->ftsMatches($pdo, 'title'), 'The FTS table is back as it was');
        $storage->disconnect();
    }

    public function test_a_second_connection_follows_a_rebuild_and_notices_a_new_pollution(): void
    {
        $path = $this->getTestDbPath();
        $a = $this->polluted(false, $path);
        $storageA = $this->storage($a);
        $b = new SqliteStorage();
        $b->connect(['path' => $path, 'external_content' => true]);
        $this->trace($storageA);
        $this->trace($b);

        // The first connection heals; the second then finds the key and leaves the table alone
        $a->delete(self::INDEX, 'beta');
        $this->assertSame(1, $this->rebuilds());
        $b->insert(self::INDEX, $this->doc('zeta', 'lions roaring'));
        $b->delete(self::INDEX, 'zeta');
        $this->assertSame(0, $this->rebuilds());
        $this->assertHealed($a);

        // Another process (a 2.5.x one, or a 2.6.0 one that created the index again) leaves the
        // table as it was before: the connection that had read the key reads it again
        $other = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pollute($other);
        $this->assertSame(4, $this->ftsMatches($other, 'title'), 'Four documents are left, and the field names are words of them');
        $b->insert(self::INDEX, $this->doc('eta', 'owls hooting'));
        $this->assertSame(1, $this->rebuilds(), 'The connection noticed that the database changed');
        $this->assertSame(0, $this->ftsMatches($other, 'title'));
        $this->assertSame('2.6.1', $this->builtBy($other));
        $a->delete(self::INDEX, 'eta');
        $this->assertSame(0, $this->ftsMatches($other, 'title'));
        $other->exec('INSERT INTO ' . self::INDEX . '_fts(' . self::INDEX . "_fts) VALUES('integrity-check')");
        $b->disconnect();
    }

    public function test_a_write_that_cannot_get_the_write_lock_fails_and_leaves_the_index_as_it_was(): void
    {
        $path = $this->getTestDbPath();
        $a = $this->polluted(false, $path);
        $b = new SqliteStorage();
        $b->connect(['path' => $path, 'external_content' => true]);
        $holder = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $holder->exec('BEGIN IMMEDIATE');

        $property = new \ReflectionProperty(SqliteStorage::class, 'connection');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $property->getValue($b)->setAttribute(\PDO::ATTR_TIMEOUT, 0);

        try {
            $b->delete(self::INDEX, 'beta');
            $this->fail('A write went on without the write lock');
        } catch (StorageException $e) {
            $this->assertStringContainsString('locked', $e->getMessage());
        } finally {
            $holder->exec('ROLLBACK');
        }

        $this->assertNull($this->builtBy($holder));
        $this->assertSame(5, $this->ftsMatches($holder, 'title'));
        $b->delete(self::INDEX, 'beta');
        $this->assertSame('2.6.1', $this->builtBy($holder));
        $this->assertSame(0, $this->ftsMatches($holder, 'title'));
        $b->disconnect();
    }

    public function test_clear_empties_a_polluted_index_and_records_it(): void
    {
        $search = $this->polluted();
        $this->trace($this->storage($search));

        $search->clear(self::INDEX);
        $this->assertSame(0, $this->rebuilds(), 'An index with no documents has nothing to build again');
        $this->assertSame('2.6.1', $this->builtBy($this->pdo($search)));
        $this->assertSame(0, $this->ftsMatches($this->pdo($search), 'title'));

        $search->indexBatch(self::INDEX, [$this->doc('one', 'cats purring'), $this->doc('two', 'dogs barking')]);
        $search->delete(self::INDEX, 'one');
        $this->assertSame(0, $this->rebuilds());
        $this->assertHealed($search);
        $this->assertSame(['two'], $this->found($search, 'dogs'));
    }

    public function test_an_index_made_or_rebuilt_now_records_it(): void
    {
        $search = $this->openSearch('external');
        $this->createIndex($search, 'external', ['stemming' => false]);
        $this->assertSame('2.6.1', $this->builtBy($this->pdo($search)));

        $search->indexBatch(self::INDEX, [$this->doc('one', 'cats purring')]);
        $this->raw($search)->exec('DELETE FROM ' . self::INDEX . "_meta WHERE key = 'fts_built_by'");
        $search->rebuildFts(self::INDEX);
        $this->assertSame('2.6.1', $this->builtBy($this->pdo($search)));

        $this->raw($search)->exec('DELETE FROM ' . self::INDEX . "_meta WHERE key = 'fts_built_by'");
        $search->rebuildFts(self::INDEX, ['stemming' => true]);
        $this->assertSame('2.6.1', $this->builtBy($this->pdo($search)));

        // Dropped and made again
        $search->dropIndex(self::INDEX);
        $search->createIndex(self::INDEX, ['stemming' => false]);
        $this->assertSame('2.6.1', $this->builtBy($this->pdo($search)));
    }

    public function test_creating_an_existing_index_again_does_not_claim_it_was_built_now(): void
    {
        $search = $this->polluted();
        $this->storage($search)->createIndex(self::INDEX, ['external_content' => true]);

        $this->assertNull($this->builtBy($this->pdo($search)), 'The FTS table is the one 2.5.x left');
        $this->assertSame(5, $this->ftsMatches($this->pdo($search), 'title'));
    }

    public function test_an_fts_table_made_again_over_documents_is_not_taken_for_a_sound_one(): void
    {
        // The FTS table was lost and createIndex() made an empty one over stored documents
        $search = $this->openSearch('external');
        $this->createIndex($search, 'external', ['stemming' => false]);
        $search->indexBatch(self::INDEX, [$this->doc('one', 'cats purring'), $this->doc('two', 'dogs barking')]);
        $pdo = $this->raw($search);
        $pdo->exec('DROP TABLE ' . self::INDEX . '_fts');
        $pdo->exec('DELETE FROM ' . self::INDEX . "_meta WHERE key = 'fts_built_by'");
        $this->storage($search)->createIndex(self::INDEX, ['external_content' => true, 'stemming' => false]);
        $this->assertNull($this->builtBy($pdo));

        $search->delete(self::INDEX, 'one');

        $this->assertSame('2.6.1', $this->builtBy($pdo));
        $this->assertSame(['two'], $this->found($search, 'dogs'), 'The documents that were stored are searchable again');
    }

    public function test_an_fts_table_made_again_over_documents_loses_the_mark_it_had(): void
    {
        // Nothing removes the mark by hand here: the table is lost and made again with the mark in place
        $search = $this->openSearch('external');
        $this->createIndex($search, 'external', ['stemming' => false]);
        $search->indexBatch(self::INDEX, [$this->doc('one', 'cats purring'), $this->doc('two', 'dogs barking')]);
        $pdo = $this->raw($search);
        $this->assertSame('2.6.1', $this->builtBy($pdo));
        $pdo->exec('DROP TABLE ' . self::INDEX . '_fts');
        $this->storage($search)->createIndex(self::INDEX, ['external_content' => true, 'stemming' => false]);
        $this->assertSame(0, $this->ftsMatches($pdo, 'dogs'), 'The table made again is empty');
        $this->assertNull($this->builtBy($pdo), 'An empty table over stored documents is not a built one');

        $search->index(self::INDEX, $this->doc('three', 'birds singing'));

        $this->assertSame('2.6.1', $this->builtBy($pdo));
        $this->assertSame(['two'], $this->found($search, 'dogs'), 'The documents that were stored are searchable again');
        $this->assertSame(['three'], $this->found($search, 'birds'));
        $this->assertIntegrity($search);
    }

    public function test_an_fts_table_made_again_over_no_documents_is_marked(): void
    {
        $search = $this->openSearch('external');
        $this->createIndex($search, 'external', ['stemming' => false]);
        $pdo = $this->raw($search);
        $pdo->exec('DROP TABLE ' . self::INDEX . '_fts');
        $pdo->exec('DELETE FROM ' . self::INDEX . "_meta WHERE key = 'fts_built_by'");

        $this->storage($search)->createIndex(self::INDEX, ['external_content' => true, 'stemming' => false]);

        $this->assertSame('2.6.1', $this->builtBy($pdo));
    }

    public function test_an_index_with_no_meta_is_not_built_again_on_a_guess(): void
    {
        $search = $this->polluted();
        $this->raw($search)->exec('DROP TABLE ' . self::INDEX . '_meta');
        $this->trace($this->storage($search));

        $search->index(self::INDEX, $this->doc('zeta', 'lions roaring'));

        $this->assertSame(0, $this->rebuilds(), 'Without its meta the index does not say how it is stored');
    }

    public function test_migrating_a_legacy_index_records_the_rebuild(): void
    {
        $search = $this->openSearch('legacy');
        $this->createIndex($search, 'legacy', ['stemming' => false]);
        $search->indexBatch(self::INDEX, [$this->doc('one', 'cats purring'), $this->doc('two', 'dogs barking')]);
        $this->assertSame('2.6.1', $this->builtBy($this->pdo($search)));
        $this->raw($search)->exec('DELETE FROM ' . self::INDEX . "_meta WHERE key = 'fts_built_by'");

        $this->storage($search)->migrateToExternalContent(self::INDEX);

        $this->assertSame('2.6.1', $this->builtBy($this->pdo($search)));
        $this->assertSame(['two'], $this->found($search, 'dogs'));
        $search->delete(self::INDEX, 'one');
        $this->assertSame(0, $this->ftsMatches($this->pdo($search), 'title'));
        $this->assertIntegrity($search);
    }

    public function ownContentModes(): array
    {
        return ['own-content, one column' => ['legacy'], 'own-content, a column per field' => ['multi']];
    }

    /** @dataProvider ownContentModes */
    public function test_an_own_content_index_is_only_marked(string $mode): void
    {
        $search = $this->openSearch($mode);
        $this->createIndex($search, $mode, ['stemming' => false, 'fields' => ['title', 'content']]);
        $search->indexBatch(self::INDEX, [$this->doc('one', 'cats purring'), $this->doc('two', 'dogs barking')]);
        // An index made by 2.6.0 or earlier
        $this->raw($search)->exec('DELETE FROM ' . self::INDEX . "_meta WHERE key = 'fts_built_by'");
        $this->trace($this->storage($search));

        $search->delete(self::INDEX, 'one');

        $this->assertSame(0, $this->rebuilds(), 'FTS5 reads the columns of its own table in a rebuild, so it was never polluted');
        $this->assertSame('2.6.1', $this->builtBy($this->pdo($search)));
        $this->assertSame(['two'], $this->found($search, 'dogs'));
        $this->assertSame([], $this->found($search, 'cats'));
        $this->assertIntegrity($search);
    }

    public function test_other_meta_keys_are_left_alone(): void
    {
        // YetiSearch Pro marks the tables it builds with a key of its own
        $search = $this->polluted();
        $pdo = $this->raw($search);
        $pdo->exec('INSERT INTO ' . self::INDEX . "_meta (key, value) VALUES ('yetisearch_pro_fts_built', '1')");

        $search->delete(self::INDEX, 'beta');

        $this->assertSame('1', $pdo->query('SELECT value FROM ' . self::INDEX . "_meta WHERE key = 'yetisearch_pro_fts_built'")->fetchColumn());
        $this->assertSame('2.6.1', $this->builtBy($pdo));
        $pdo->exec('DELETE FROM ' . self::INDEX . "_meta WHERE key = 'yetisearch_pro_fts_built'");
        $this->assertHealed($search);
    }

    /** @return string[] The writes, each done on a polluted index inside the caller's transaction */
    public function callerWrites(): array
    {
        return [
            'insert' => [function (YetiSearch $s, self $t) {
                $s->index(self::INDEX, $t->doc('zeta', 'lions roaring'));
            }, ['alpha', 'beta', 'delta', 'epsilon', 'gamma', 'zeta']],
            'batch' => [function (YetiSearch $s, self $t) {
                $s->indexBatch(self::INDEX, [$t->doc('zeta', 'lions roaring'), $t->doc('beta', 'mice squeaking')]);
            }, ['alpha', 'beta', 'delta', 'epsilon', 'gamma', 'zeta']],
            'delete' => [function (YetiSearch $s) {
                $s->delete(self::INDEX, 'beta');
            }, ['alpha', 'delta', 'epsilon', 'gamma']],
            'prefix delete' => [function (YetiSearch $s) {
                $s->deleteByIdPrefix(self::INDEX, 'ep');
            }, ['alpha', 'beta', 'delta', 'gamma']],
        ];
    }

    private function callerWork(\PDO $pdo): int
    {
        return (int)$pdo->query('SELECT COUNT(*) FROM caller_work')->fetchColumn();
    }

    /** @dataProvider callerWrites */
    public function test_a_write_inside_the_callers_transaction_joins_it(callable $write, array $remaining): void
    {
        $search = $this->polluted();
        $pdo = $this->pdo($search);
        $pdo->exec('CREATE TABLE caller_work (note TEXT)');
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO caller_work VALUES ('mine')");

        $write($search, $this);

        $this->assertTrue($pdo->inTransaction(), 'The write leaves the transaction to its owner');
        $pdo->commit();
        $this->assertSame(1, $this->callerWork($pdo), 'The work of the caller is kept');
        $this->assertHealed($search);
        $this->assertSame($remaining, $this->storedIds($search));
        $this->assertSame(['gamma'], $this->found($search, 'birds'));
    }

    /** @dataProvider callerWrites */
    public function test_a_heal_the_callers_transaction_rolled_back_is_not_taken_for_done(callable $write): void
    {
        $search = $this->polluted();
        $pdo = $this->pdo($search);
        $pdo->exec('CREATE TABLE caller_work (note TEXT)');
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO caller_work VALUES ('mine')");
        try {
            $write($search, $this);
        } catch (StorageException $e) {
            // A write that is refused in the transaction rolls its heal back with the caller's work, just the same
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $this->assertNull($this->builtBy($pdo), 'The mark went with the transaction');
        $this->assertSame(5, $this->ftsMatches($pdo, 'title'), 'So did the rebuild');
        $this->assertSame(0, $this->callerWork($pdo));

        // The index is as 2.5.x left it, and an ordinary write has to heal it again
        $search->delete(self::INDEX, 'beta');

        $this->assertHealed($search);
        $this->assertSame(['alpha', 'delta', 'epsilon', 'gamma'], $this->storedIds($search));
        $this->assertSame(['gamma'], $this->found($search, 'birds'));
    }

    public function test_a_write_that_fails_inside_the_callers_transaction_undoes_only_itself(): void
    {
        $search = $this->openSearch('external');
        $this->createIndex($search, 'external', ['stemming' => true]);
        $search->indexBatch(self::INDEX, [$this->doc('alpha', 'dogs running in the park')]);
        $pdo = $this->pdo($search);
        $pdo->exec('CREATE TABLE caller_work (note TEXT)');
        StemmerFactory::register('english', new class () implements StemmerInterface {
            public function stem(string $word): string
            {
                throw new \RuntimeException('the stemmer is broken');
            }

            public function getLanguage(): string
            {
                return 'english';
            }
        });
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO caller_work VALUES ('mine')");

        try {
            $search->index(self::INDEX, $this->doc('zeta', 'lions roaring'));
            $this->fail('The write went on with a broken stemmer');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('the stemmer is broken', $e->getMessage());
        }

        $this->assertTrue($pdo->inTransaction(), 'The transaction of the caller is still open');
        $pdo->commit();
        $this->assertSame(1, $this->callerWork($pdo), 'The work of the caller is kept');
        $this->assertSame(['alpha'], $this->storedIds($search), 'The document of the failed write is not stored');
        StemmerFactory::reset();
        $this->assertSame(['alpha'], $this->found($search, 'dogs'));
        $this->assertIntegrity($search);
    }
}
