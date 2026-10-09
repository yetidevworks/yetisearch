<?php

namespace YetiSearch\Tests\Integration;

use YetiSearch\Stemmer\StemmerFactory;
use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Tests\TestCase;
use YetiSearch\YetiSearch;

/**
 * Helpers for tests of stemming, which run in each way an index can be stored:
 * external content (the default), an FTS table that keeps its own copy, and the
 * same with one FTS column per field.
 */
abstract class StemmingTestCase extends TestCase
{
    protected const INDEX = 'stemmed';

    protected function tearDown(): void
    {
        StemmerFactory::reset();
        parent::tearDown();
    }

    public function schemaModes(): array
    {
        return [
            'external content' => ['external'],
            'own-content, one column' => ['legacy'],
            'own-content, a column per field' => ['multi'],
        ];
    }

    /**
     * @param array $config Merged over the test defaults, e.g. ['search' => ['prefix_last_token' => true]]
     */
    protected function openSearch(string $mode, array $config = []): YetiSearch
    {
        $base = [
            'storage' => ['external_content' => $mode === 'external'],
            'search' => ['min_score' => 0.0],
        ];

        return $this->createSearchInstance(array_replace_recursive($base, $config));
    }

    /**
     * Create an index the way $mode stores it. Stemming is on unless the options say otherwise.
     */
    protected function createIndex(YetiSearch $search, string $mode, array $options = [], string $name = self::INDEX): void
    {
        if ($mode === 'legacy') {
            $options += ['multi_column_fts' => false];
        }
        $search->createIndex($name, $options + ['stemming' => true]);
        $this->createdIndexes[] = $name;
    }

    protected function storage(YetiSearch $search): SqliteStorage
    {
        $method = new \ReflectionMethod($search, 'getStorage');
        $method->setAccessible(true);

        return $method->invoke($search);
    }

    protected function pdo(YetiSearch $search): \PDO
    {
        $property = new \ReflectionProperty(SqliteStorage::class, 'connection');
        $property->setAccessible(true);

        return $property->getValue($this->storage($search));
    }

    /** @return string[] */
    protected function ids(array $results): array
    {
        $ids = array_column($results['results'], 'id');
        sort($ids);

        return $ids;
    }

    /** @return string[] Result ids in the order they came back */
    protected function orderedIds(array $results): array
    {
        return array_column($results['results'], 'id');
    }

    /** @return string[] */
    protected function columns(YetiSearch $search, string $table): array
    {
        $names = [];
        foreach ($this->pdo($search)->query("PRAGMA table_info({$table})") as $column) {
            $names[] = $column['name'];
        }

        return $names;
    }

    protected function assertIntegrity(YetiSearch $search, string $index = self::INDEX): void
    {
        $this->pdo($search)->exec("INSERT INTO {$index}_fts({$index}_fts) VALUES('integrity-check')");
        $this->addToAssertionCount(1);
    }

    /**
     * Every term the FTS table holds, which is what leftover stems would show in.
     *
     * @return string[]
     */
    protected function vocabulary(YetiSearch $search, string $index = self::INDEX): array
    {
        $pdo = $this->pdo($search);
        $pdo->exec("DROP TABLE IF EXISTS temp.stemming_test_terms");
        $pdo->exec("CREATE VIRTUAL TABLE temp.stemming_test_terms USING fts5vocab(main, '{$index}_fts', 'row')");
        $terms = $pdo->query('SELECT term FROM temp.stemming_test_terms ORDER BY term')->fetchAll(\PDO::FETCH_COLUMN);
        $pdo->exec("DROP TABLE temp.stemming_test_terms");

        return $terms;
    }

    /**
     * Documents that match none of the words the tests look for, so a word that
     * is in a few documents is rare enough to count for something in the ranking.
     */
    protected function addFillers(YetiSearch $search, int $count = 12, string $index = self::INDEX): void
    {
        $documents = [];
        for ($i = 1; $i <= $count; $i++) {
            $documents[] = [
                'id' => "filler{$i}",
                'content' => ['title' => "Filler {$i}", 'content' => "lorem ipsum dolor sit amet number{$i}"],
            ];
        }
        $search->indexBatch($index, $documents);
    }
}
