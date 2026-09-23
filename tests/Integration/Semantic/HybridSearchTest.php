<?php

namespace YetiSearch\Tests\Integration\Semantic;

use YetiSearch\Exceptions\YetiSearchException;
use YetiSearch\Tests\Fixtures\FakeEmbeddingProvider;
use YetiSearch\Tests\TestCase;
use YetiSearch\YetiSearch;

class HybridSearchTest extends TestCase
{
    private const INDEX = 'semantic_test';

    private function docs(): array
    {
        return [
            ['id' => 'cars', 'type' => 'article', 'content' => [
                'title' => 'Choosing your first car',
                'content' => 'A sedan or a small truck: what to look for when you buy a vehicle.',
            ]],
            ['id' => 'pizza', 'type' => 'article', 'content' => [
                'title' => 'Weeknight pizza',
                'content' => 'A quick recipe for dinner, with a simple dough and a tomato sauce.',
            ]],
            ['id' => 'login', 'type' => 'faq', 'content' => [
                'title' => 'Locked out?',
                'content' => 'Reset your password from the signin page to get back into your account.',
            ]],
            ['id' => 'weather', 'type' => 'article', 'content' => [
                'title' => 'Reading a forecast',
                'content' => 'How to tell whether rain or a storm is coming.',
            ]],
            ['id' => 'trucks', 'type' => 'faq', 'content' => [
                'title' => 'Truck maintenance',
                'content' => 'Keep your vehicle running: oil, tyres and brakes.',
            ]],
        ];
    }

    private function build(?FakeEmbeddingProvider $provider, array $config = []): YetiSearch
    {
        $search = $this->createSearchInstance(array_merge_recursive($config, [
            'search' => ['enable_fuzzy' => false, 'enable_suggestions' => false],
        ]));
        $this->createTestIndex(self::INDEX);
        $search->indexBatch(self::INDEX, $this->docs());
        if ($provider !== null) {
            $search->setEmbeddingProvider($provider);
        }

        return $search;
    }

    private function ids(array $response): array
    {
        return array_map(function ($r) {
            return $r['id'];
        }, $response['results']);
    }

    public function testWithoutAProviderSearchIsUnchanged(): void
    {
        $search = $this->build(null);

        $response = $search->search(self::INDEX, 'automobile');

        $this->assertSame([], $response['results']);
        $this->assertFalse($response['semantic']);
        $this->assertFalse($search->isSemanticEnabled());
    }

    public function testEmbedPendingEmbedsOnlyWhatChanged(): void
    {
        $provider = new FakeEmbeddingProvider();
        $search = $this->build($provider);

        $first = $search->embedPending(self::INDEX, 3);
        $this->assertSame(3, $first['embedded']);
        $this->assertSame(2, $first['pending']);
        $this->assertSame(5, $first['total']);
        $this->assertNull($first['error']);

        $second = $search->embedPending(self::INDEX);
        $this->assertSame(2, $second['embedded']);
        $this->assertSame(0, $second['pending']);

        $third = $search->embedPending(self::INDEX);
        $this->assertSame(0, $third['embedded']);
        $this->assertSame(5, $provider->documentTextsEmbedded());

        // Same text again: nothing to do. New text: one document.
        $search->update(self::INDEX, ['id' => 'weather', 'content' => ['title' => 'Reading a forecast', 'content' => 'How to tell whether rain or a storm is coming.']]);
        $this->assertSame(0, $search->embeddingStats(self::INDEX)['pending']);
        $search->update(self::INDEX, ['id' => 'weather', 'content' => ['title' => 'Reading a forecast', 'content' => 'Sunny spells and the odd storm.']]);
        $this->assertSame(1, $search->embeddingStats(self::INDEX)['pending']);
        $this->assertSame(1, $search->embedPending(self::INDEX)['embedded']);
    }

    public function testMeaningFindsDocumentsWithNoSharedKeyword(): void
    {
        $search = $this->build(new FakeEmbeddingProvider());
        $search->embedPending(self::INDEX);

        $response = $search->search(self::INDEX, 'automobile');

        $this->assertTrue($response['semantic']);
        $ids = $this->ids($response);
        $this->assertContains('cars', $ids);
        $this->assertContains('trucks', $ids);
        // Unrelated documents stay below min_similarity.
        $this->assertNotContains('pizza', $ids);
        $this->assertNotContains('weather', $ids);
        $this->assertSame(2, $response['total']);
    }

    public function testKeywordAndMeaningAgreeingRankFirst(): void
    {
        $search = $this->build(new FakeEmbeddingProvider());
        $search->embedPending(self::INDEX);

        // "sedan" is a keyword only in "cars"; both vehicle documents match
        // it by meaning.
        $response = $search->search(self::INDEX, 'sedan');

        $this->assertSame(['cars', 'trucks'], $this->ids($response));
        $this->assertGreaterThan($response['results'][1]['score'], $response['results'][0]['score']);
        $this->assertSame(2, $response['total']);
    }

    public function testFiltersApplyToTheVectorSide(): void
    {
        $search = $this->build(new FakeEmbeddingProvider());
        $search->embedPending(self::INDEX);

        $response = $search->search(self::INDEX, 'automobile', [
            'filters' => [['field' => 'type', 'value' => 'faq']],
        ]);

        $this->assertSame(['trucks'], $this->ids($response));
    }

    public function testProviderFailureFallsBackToKeywords(): void
    {
        $provider = new FakeEmbeddingProvider();
        $search = $this->build($provider);
        $search->embedPending(self::INDEX);
        $provider->fail = true;

        $response = $search->search(self::INDEX, 'pizza');

        $this->assertFalse($response['semantic']);
        $this->assertSame(['pizza'], $this->ids($response));
    }

    public function testEmbedPendingReportsAProviderError(): void
    {
        $provider = new FakeEmbeddingProvider();
        $provider->fail = true;
        $search = $this->build($provider);

        $result = $search->embedPending(self::INDEX);

        $this->assertSame(0, $result['embedded']);
        $this->assertSame(5, $result['pending']);
        $this->assertSame('Fake provider is down', $result['error']);
    }

    public function testQueryEmbeddingsAreCached(): void
    {
        $provider = new FakeEmbeddingProvider();
        $search = $this->build($provider);
        $search->embedPending(self::INDEX);

        $search->search(self::INDEX, 'Automobile', ['bypass_cache' => true]);
        $search->search(self::INDEX, '  automobile ', ['bypass_cache' => true, 'limit' => 5]);

        $this->assertSame(1, $provider->queryCalls());
    }

    public function testSemanticCanBeTurnedOffPerQuery(): void
    {
        $search = $this->build(new FakeEmbeddingProvider());
        $search->embedPending(self::INDEX);

        $response = $search->search(self::INDEX, 'automobile', ['semantic' => false]);

        $this->assertFalse($response['semantic']);
        $this->assertSame([], $response['results']);
    }

    public function testChangingTheModelFallsBackUntilReembedded(): void
    {
        $search = $this->build(new FakeEmbeddingProvider('model-a'));
        $search->embedPending(self::INDEX);

        $search->setEmbeddingProvider(new FakeEmbeddingProvider('model-b'));
        $response = $search->search(self::INDEX, 'automobile');
        $this->assertFalse($response['semantic']);
        $this->assertSame(5, $search->embeddingStats(self::INDEX)['pending']);

        $search->embedPending(self::INDEX);
        $this->assertTrue($search->search(self::INDEX, 'automobile')['semantic']);
    }

    public function testDeletedDocumentsLeaveTheResultsAndArePruned(): void
    {
        $search = $this->build(new FakeEmbeddingProvider());
        $search->embedPending(self::INDEX);

        $search->delete(self::INDEX, 'trucks');
        $this->assertSame(['cars'], $this->ids($search->search(self::INDEX, 'automobile', ['bypass_cache' => true])));

        $this->assertSame(1, $search->embedPending(self::INDEX)['pruned']);
    }

    public function testChunkedDocumentsEmbedTheirChunks(): void
    {
        $provider = new FakeEmbeddingProvider();
        $search = $this->createSearchInstance(['indexer' => ['chunk_size' => 120, 'chunk_overlap' => 0]]);
        $this->createTestIndex(self::INDEX);
        $search->setEmbeddingProvider($provider);
        $search->index(self::INDEX, ['id' => 'guide', 'content' => [
            'title' => 'Big guide',
            'route' => '/guide',
            'content' => str_repeat('Pick a guitar and learn some chords for your first song. ', 3)
                . str_repeat('Then cook a pasta dinner from a simple recipe to celebrate. ', 3),
        ]]);

        $stats = $search->embeddingStats(self::INDEX);
        $this->assertGreaterThan(1, $stats['total']);
        $search->embedPending(self::INDEX);

        $response = $search->search(self::INDEX, 'meal', ['unique_by_route' => true]);
        $this->assertTrue($response['semantic']);
        $this->assertCount(1, $response['results']);
        $this->assertStringStartsWith('guide#chunk', $response['results'][0]['id']);
    }

    public function testDropIndexRemovesVectors(): void
    {
        $search = $this->build(new FakeEmbeddingProvider());
        $search->embedPending(self::INDEX);

        $search->dropIndex(self::INDEX);
        $search->indexBatch(self::INDEX, $this->docs());

        $this->assertSame(5, $search->embeddingStats(self::INDEX)['pending']);
    }

    public function testExternalContentSchema(): void
    {
        $search = $this->createSearchInstance(['storage' => ['external_content' => true]]);
        $this->createTestIndex(self::INDEX);
        $search->indexBatch(self::INDEX, $this->docs());
        $search->setEmbeddingProvider(new FakeEmbeddingProvider());
        $search->embedPending(self::INDEX);

        $ids = $this->ids($search->search(self::INDEX, 'automobile'));
        sort($ids);
        $this->assertSame(['cars', 'trucks'], $ids);
    }

    public function testProviderFromConfig(): void
    {
        $search = $this->createSearchInstance(['semantic' => [
            'provider' => new FakeEmbeddingProvider(),
            'min_similarity' => 1.5,
        ]]);
        $this->createTestIndex(self::INDEX);
        $search->indexBatch(self::INDEX, $this->docs());
        $search->embedPending(self::INDEX);

        $this->assertTrue($search->isSemanticEnabled());
        // Options from config apply: nothing can be 1.5 similar.
        $this->assertSame([], $this->ids($search->search(self::INDEX, 'automobile')));
    }

    public function testEmbeddingCallsNeedAProvider(): void
    {
        $search = $this->build(null);

        $this->expectException(YetiSearchException::class);
        $search->embedPending(self::INDEX);
    }
}
