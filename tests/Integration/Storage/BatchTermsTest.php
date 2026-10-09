<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Tests\Integration\StemmingTestCase;

/**
 * With the Levenshtein terms index on, a document written again through insertBatch() loses the
 * fuzzy terms of its earlier version, as it does through insert().
 */
class BatchTermsTest extends StemmingTestCase
{
    private function open(string $mode): \YetiSearch\YetiSearch
    {
        $search = $this->openSearch($mode, [
            'storage' => ['search' => ['enable_fuzzy' => true, 'fuzzy_algorithm' => 'levenshtein']],
            'indexer' => ['chunk_size' => 100, 'chunk_overlap' => 0],
        ]);
        $this->createIndex($search, $mode, ['stemming' => false]);

        return $search;
    }

    private function doc(string $id, string $content): array
    {
        return ['id' => $id, 'content' => ['title' => ucfirst($id), 'content' => $content]];
    }

    /** @return string[] */
    private function termsOf(\YetiSearch\YetiSearch $search, string $id): array
    {
        $stmt = $this->pdo($search)->prepare('SELECT DISTINCT term FROM ' . self::INDEX . '_terms WHERE document_id = ? ORDER BY term');
        $stmt->execute([$id]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @dataProvider schemaModes */
    public function test_a_batch_that_writes_a_document_again_drops_its_old_terms(string $mode): void
    {
        $search = $this->open($mode);
        $storage = $this->storage($search);
        $storage->insertBatch(self::INDEX, [$this->doc('a', 'zebra'), $this->doc('b', 'walrus')]);
        $this->assertContains('zebra', $this->termsOf($search, 'a'));

        $storage->insertBatch(self::INDEX, [$this->doc('a', 'giraffe')]);

        $this->assertNotContains('zebra', $this->termsOf($search, 'a'));
        $this->assertContains('giraffe', $this->termsOf($search, 'a'));
        $this->assertContains('walrus', $this->termsOf($search, 'b'));
    }

    /** @dataProvider schemaModes */
    public function test_a_batch_with_more_ids_than_one_delete_takes_drops_every_old_term(string $mode): void
    {
        $search = $this->open($mode);
        $storage = $this->storage($search);
        $first = $second = [];
        for ($i = 0; $i < 1100; $i++) {
            $first[] = $this->doc("d{$i}", "oldword{$i}");
            $second[] = $this->doc("d{$i}", "newword{$i}");
        }
        $storage->insertBatch(self::INDEX, $first);
        $storage->insertBatch(self::INDEX, $second);

        $pdo = $this->pdo($search);
        $this->assertSame('0', (string)$pdo->query("SELECT COUNT(*) FROM " . self::INDEX . "_terms WHERE term LIKE 'oldword%'")->fetchColumn());
        $this->assertSame('1100', (string)$pdo->query("SELECT COUNT(*) FROM " . self::INDEX . "_terms WHERE term LIKE 'newword%'")->fetchColumn());
    }

    /** @dataProvider schemaModes */
    public function test_updating_a_chunked_document_keeps_the_fuzzy_correction_of_another_one(string $mode): void
    {
        $search = $this->open($mode);
        $search->index(self::INDEX, $this->doc('parent', 'zebra'));
        $search->index(self::INDEX, $this->doc('other', 'zebras'));

        // A parent with a chunk is written with insertBatch()
        $search->update(self::INDEX, [
            'id' => 'parent',
            'content' => ['title' => 'Parent', 'content' => 'giraffe'],
            'chunks' => [['content' => ['content' => 'walrus']]],
        ]);

        $this->assertNotContains('zebra', $this->termsOf($search, 'parent'));
        $this->assertSame(['other'], $this->ids($search->search(self::INDEX, 'zebra', ['fuzzy' => true])));
    }
}
