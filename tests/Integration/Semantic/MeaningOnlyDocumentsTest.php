<?php

namespace YetiSearch\Tests\Integration\Semantic;

use YetiSearch\Tests\Fixtures\FakeEmbeddingProvider;
use YetiSearch\Tests\TestCase;
use YetiSearch\YetiSearch;

/**
 * Documents marked 'meaning_only' are embedded and ranked by meaning, and
 * never found by keywords.
 */
class MeaningOnlyDocumentsTest extends TestCase
{
    private const INDEX = 'meaning_only_test';

    public function schemas(): array
    {
        return ['legacy' => [false], 'external content' => [true]];
    }

    private function build(bool $external, array $semantic = []): YetiSearch
    {
        $search = $this->createSearchInstance([
            'storage' => ['external_content' => $external],
            'search' => ['enable_fuzzy' => false, 'enable_suggestions' => false, 'cache_ttl' => 0],
            'semantic' => $semantic,
        ]);
        $this->createTestIndex(self::INDEX);
        $search->indexBatch(self::INDEX, [
            ['id' => 'cars', 'content' => ['title' => 'Choosing your first car', 'content' => 'A sedan: what to look for.']],
            ['id' => 'pizza', 'content' => ['title' => 'Weeknight pizza', 'content' => 'A quick recipe for dinner.']],
            // Says what the thing is, in words nobody should find it by.
            ['id' => 'cars#card', 'type' => 'card', 'meaning_only' => true, 'content' => [
                'title' => 'Shorebreak',
                'content' => 'Shorebreak product: a vehicle.',
            ]],
        ]);

        return $search;
    }

    private function ids(array $response): array
    {
        return array_map(function ($r) {
            return $r['id'];
        }, $response['results']);
    }

    /**
     * @dataProvider schemas
     */
    public function testKeywordsNeverFindAMeaningOnlyDocument(bool $external): void
    {
        $search = $this->build($external);

        $this->assertSame([], $this->ids($search->search(self::INDEX, 'shorebreak')));
        $this->assertSame([], $this->ids($search->search(self::INDEX, 'product')));
        $this->assertSame(['pizza'], $this->ids($search->search(self::INDEX, 'pizza')));
        // It is stored all the same, flag and all.
        $this->assertSame(3, $search->count(self::INDEX));
    }

    /**
     * @dataProvider schemas
     */
    public function testMeaningRanksIt(bool $external): void
    {
        $search = $this->build($external);
        $search->setEmbeddingProvider(new FakeEmbeddingProvider());
        $run = $search->embedPending(self::INDEX);
        $this->assertSame(3, $run['total']);

        $ids = $this->ids($search->search(self::INDEX, 'automobile'));
        $this->assertContains('cars#card', $ids);
        $this->assertContains('cars', $ids);

        // Its words still find nothing, with meaning on or off.
        $this->assertSame([], $this->ids($search->search(self::INDEX, 'shorebreak', ['semantic' => false])));
        $this->assertNotContains('cars#card', $this->ids($search->search(self::INDEX, 'sedan', ['semantic' => false])));
    }

    /**
     * @dataProvider schemas
     */
    public function testTheFlagCanBeTurnedOnAndOff(bool $external): void
    {
        $search = $this->build($external);

        $search->index(self::INDEX, ['id' => 'cars#card', 'type' => 'card', 'content' => ['title' => 'Shorebreak', 'content' => 'Shorebreak product.']]);
        $this->assertSame(['cars#card'], $this->ids($search->search(self::INDEX, 'shorebreak')));

        $search->index(self::INDEX, ['id' => 'cars#card', 'type' => 'card', 'meaning_only' => true, 'content' => ['title' => 'Shorebreak', 'content' => 'Shorebreak product.']]);
        $this->assertSame([], $this->ids($search->search(self::INDEX, 'shorebreak')));

        // The same through a batch, both ways.
        $search->indexBatch(self::INDEX, [['id' => 'cars#card', 'type' => 'card', 'content' => ['title' => 'Shorebreak', 'content' => 'Shorebreak.']]]);
        $this->assertSame(['cars#card'], $this->ids($search->search(self::INDEX, 'shorebreak')));
        $search->indexBatch(self::INDEX, [['id' => 'cars#card', 'type' => 'card', 'meaning_only' => true, 'content' => ['title' => 'Shorebreak', 'content' => 'Shorebreak.']]]);
        $this->assertSame([], $this->ids($search->search(self::INDEX, 'shorebreak')));

        // Deleting it leaves the full-text index consistent.
        $search->delete(self::INDEX, 'cars#card');
        $this->assertSame(['pizza'], $this->ids($search->search(self::INDEX, 'pizza')));
        $this->assertSame(2, $search->count(self::INDEX));
    }

    /**
     * @dataProvider schemas
     */
    public function testRebuildsLeaveItOut(bool $external): void
    {
        $search = $this->build($external);

        $search->rebuildFts(self::INDEX);
        $this->assertSame([], $this->ids($search->search(self::INDEX, 'shorebreak')));
        $this->assertSame(['cars'], $this->ids($search->search(self::INDEX, 'sedan')));

        // deleteByIdPrefix() rebuilds external-content FTS in one pass.
        $search->deleteByIdPrefix(self::INDEX, 'pizza');
        $this->assertSame([], $this->ids($search->search(self::INDEX, 'shorebreak')));
        $this->assertSame(['cars'], $this->ids($search->search(self::INDEX, 'sedan')));
    }

    public function testChunksOfAMeaningOnlyDocumentAreMeaningOnly(): void
    {
        $search = $this->createSearchInstance(['search' => ['enable_fuzzy' => false, 'enable_suggestions' => false, 'cache_ttl' => 0]]);
        $this->createTestIndex(self::INDEX);
        $search->index(self::INDEX, [
            'id' => 'long',
            'meaning_only' => true,
            'content' => ['title' => 'Long', 'content' => 'Zanzibar stories.'],
            'chunks' => ['Zanzibar one.', 'Zanzibar two.'],
        ]);

        $this->assertSame([], $this->ids($search->search(self::INDEX, 'zanzibar')));
        $this->assertSame(3, $search->count(self::INDEX));
    }

    public function testCardsWorkWithoutATwoSidedSearch(): void
    {
        // KahunaCart's product index as the library can now run it: each
        // product and its card share a route, cards count for meaning only,
        // and the noise gate asks its question of the cards alone.
        $search = $this->createSearchInstance([
            'search' => ['enable_fuzzy' => false, 'enable_suggestions' => false, 'cache_ttl' => 0],
            'semantic' => ['gate_filters' => [['field' => 'type', 'value' => 'card']], 'calibration' => 'off'],
        ]);
        $this->createTestIndex(self::INDEX);
        $products = [
            1 => ['Roadster', 'A fast sedan for weekend drives.'],
            2 => ['Margherita kit', 'Everything for pizza night.'],
            3 => ['Password vault', 'Keeps your login safe.'],
            4 => ['Rain shell', 'A jacket for any storm.'],
            5 => ['Travel guitar', 'Small enough to play a song anywhere.'],
            6 => ['Pickup', 'A truck for the farm.'],
            7 => ['Pasta press', 'Fresh pasta for every meal.'],
            8 => ['Signin key', 'Credentials on a keyring.'],
            9 => ['Forecast clock', 'Shows the weather.'],
            10 => ['Music stand', 'Holds your chords.'],
        ];
        $documents = [];
        foreach ($products as $id => [$title, $description]) {
            $documents[] = ['id' => (string)$id, 'type' => 'product', 'content' => ['title' => $title, 'content' => $description, 'route' => "/p/{$id}"]];
            $documents[] = ['id' => "{$id}#card", 'type' => 'card', 'meaning_only' => true, 'content' => [
                'title' => "Product: {$title}",
                'content' => $description,
                'route' => "/p/{$id}",
            ]];
        }
        $search->indexBatch(self::INDEX, $documents);
        $search->setEmbeddingProvider(new FakeEmbeddingProvider());
        $search->embedPending(self::INDEX);

        // "product" is only in the cards: keywords find nothing for it.
        $this->assertSame([], $this->ids($search->search(self::INDEX, 'product', ['semantic' => false])));

        // Meaning finds the vehicles, one result per product.
        $response = $search->search(self::INDEX, 'automobile', ['unique_by_route' => true]);
        $this->assertTrue($response['semantic']);
        $routes = array_map(function ($r) {
            return $r['document']['route'];
        }, $response['results']);
        sort($routes);
        $this->assertSame(['/p/1', '/p/6'], $routes);
    }
}
