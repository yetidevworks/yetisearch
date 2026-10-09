<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Tests\TestCase;

/**
 * field_weights are bm25() weights, which count the columns of the FTS table in order.
 * An own-content table has an id column before the fields.
 */
class Bm25FieldWeightsTest extends TestCase
{
    private ?SqliteStorage $storage = null;

    protected function tearDown(): void
    {
        if ($this->storage !== null) {
            $this->storage->disconnect();
            $this->storage = null;
        }
        parent::tearDown();
    }

    private function storage(bool $stemming = false): SqliteStorage
    {
        $this->storage = new SqliteStorage();
        $this->storage->connect(['path' => $this->getTestDbPath()]);
        $this->storage->createIndex('docs', [
            'external_content' => false,
            'multi_column_fts' => true,
            'fields' => ['title', 'body'],
            'stemming' => $stemming,
        ]);
        // The word is once in the title of one document, and twice in the body of the other,
        // so which one ranks first depends on the weights alone
        $this->storage->insert('docs', ['id' => 'in-title', 'content' => ['title' => 'zebra crossing', 'body' => 'a quiet street at night']]);
        $this->storage->insert('docs', ['id' => 'in-body', 'content' => ['title' => 'a quiet street', 'body' => 'zebra zebra at night']]);
        for ($i = 1; $i <= 6; $i++) {
            $this->storage->insert('docs', ['id' => "other$i", 'content' => ['title' => "Other $i", 'body' => "nothing to see here number$i"]]);
        }

        return $this->storage;
    }

    /** @return string[] */
    private function order(SqliteStorage $storage, array $weights): array
    {
        $query = ['query' => 'zebra', 'limit' => 10];
        if ($weights) {
            $query['field_weights'] = $weights;
        }

        return array_column($storage->search('docs', $query), 'id');
    }

    /** @dataProvider stemmingOptions */
    public function test_a_title_weight_ranks_the_title_match_first(bool $stemming): void
    {
        $storage = $this->storage($stemming);

        $this->assertSame(['in-body', 'in-title'], $this->order($storage, []), 'Without weights the word that is twice in the body wins');
        $this->assertSame(['in-title', 'in-body'], $this->order($storage, ['title' => 50.0, 'body' => 1.0]));
    }

    /** @dataProvider stemmingOptions */
    public function test_a_body_weight_ranks_the_body_match_first(bool $stemming): void
    {
        $storage = $this->storage($stemming);

        $this->assertSame(['in-body', 'in-title'], $this->order($storage, ['title' => 1.0, 'body' => 50.0]));
        // Weights that favour the title more than the body's two occurrences are worth
        $this->assertSame(['in-title', 'in-body'], $this->order($storage, ['title' => 50.0]));
    }

    public function stemmingOptions(): array
    {
        return ['own-content' => [false], 'own-content that stems' => [true]];
    }
}
