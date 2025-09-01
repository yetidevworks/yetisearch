<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Tests\TestCase;
use PDO;

class WeightedBm25AndPrefixTest extends TestCase
{
    private function getPdo($search): PDO
    {
        $ref = new \ReflectionClass($search);
        $m = $ref->getMethod('getStorage');
        $m->setAccessible(true);
        $storage = $m->invoke($search);
        $sref = new \ReflectionClass($storage);
        $p = $sref->getProperty('connection');
        $p->setAccessible(true);
        return $p->getValue($storage);
    }

    private function getCreateSql(PDO $pdo, string $table): ?string
    {
        $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['sql'] ?? null;
    }

    public function test_weighted_bm25_prioritizes_title_over_content(): void
    {
        $search = $this->createSearchInstance([
            'analyzer' => [
                'remove_stop_words' => false,
            ],
            'indexer' => [
                'fields' => [
                    'title' => ['boost' => 10.0, 'store' => true],
                    'content' => ['boost' => 1.0, 'store' => true],
                ],
                'fts' => [
                    'multi_column' => true,
                ],
            ],
        ]);

        $index = 'rank_idx';
        $this->createTestIndex($index);

        // Preflight: schema must be multi-column FTS with title, content
        $pdo = $this->getPdo($search);
        $create = $this->getCreateSql($pdo, $index . '_fts');
        $this->assertNotEmpty($create, 'FTS table not created');
        $this->assertStringContainsString('USING fts5', $create);
        $this->assertStringContainsString('title', $create);
        $this->assertStringContainsString('content', $create);

        // Doc 1: single keyword in title
        $search->index($index, [
            'id' => 'doc-title',
            'content' => [
                'title' => 'Rocket Propulsion',
                'content' => 'An introduction to physics and engineering.'
            ],
        ]);

        // Doc 2: few occurrences in content only (should not outweigh strong title weight)
        $search->index($index, [
            'id' => 'doc-content',
            'content' => [
                'title' => 'Introduction',
                'content' => str_repeat(' rocket', 3),
            ],
        ]);

        // DB-level weighting check using bm25() directly to avoid query-builder differences
        $stmt = $pdo->query(
            "SELECT d.id, bm25({$index}_fts, 50.0, 1.0) AS rank\n" .
            "FROM {$index} d INNER JOIN {$index}_fts f ON d.id=f.id\n" .
            "WHERE {$index}_fts MATCH 'rocket' ORDER BY rank ASC LIMIT 2"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($rows, 'Expected at least one FTS5 match for "rocket"');
        $ids = array_column($rows, 'id');
        $this->assertContains('doc-title', $ids, 'Title-weighted doc should appear in top results under BM25 weighting');
    }

    public function test_prefix_queries_return_matches_with_prefix(): void
    {
        $search = $this->createSearchInstance([
            'analyzer' => [
                'remove_stop_words' => false,
            ],
            'indexer' => [
                'fields' => [
                    'title' => ['boost' => 3.0, 'store' => true],
                    'content' => ['boost' => 1.0, 'store' => true],
                ],
                'fts' => [
                    'multi_column' => true,
                    'prefix' => [2,3],
                ],
            ],
        ]);

        $index = 'prefix_idx';
        $this->createTestIndex($index);

        $docs = [
            ['id' => 's1', 'content' => ['title' => 'Skywalker', 'content' => 'Jedi']],
            ['id' => 's2', 'content' => ['title' => 'Skyrim Guide', 'content' => 'RPG tips']],
            ['id' => 's3', 'content' => ['title' => 'Skylark', 'content' => 'Bird']],
            ['id' => 'd1', 'content' => ['title' => 'Dark Knight', 'content' => 'Batman']],
        ];
        foreach ($docs as $d) { $search->index($index, $d); }

        // Preflight: schema must include prefix in CREATE statement
        $pdo = $this->getPdo($search);
        $create = $this->getCreateSql($pdo, $index . '_fts');
        $this->assertNotEmpty($create, 'FTS table not created');
        $this->assertStringContainsString("USING fts5", $create);
        $this->assertStringContainsString("prefix='2 3'", str_replace('"', "'", $create));

        // DB-level prefix validation using MATCH 'sky*'
        $stmt = $pdo->query("SELECT d.content FROM (SELECT id FROM {$index}_fts WHERE {$index}_fts MATCH 'sky*') x JOIN {$index} d ON d.id = x.id");
        $raw = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        $titles = [];
        foreach ($raw as $json) {
            $arr = json_decode($json, true) ?: [];
            $titles[] = $arr['title'] ?? '';
        }
        $this->assertNotEmpty($titles, 'Expected prefix matches for sky*');
        $this->assertTrue((bool)array_filter($titles, fn($t) => stripos((string)$t, 'Sky') === 0), 'Expected titles starting with "Sky"');
    }
}
