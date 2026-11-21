<?php

namespace YetiSearch\Tests\Integration\Semantic;

use YetiSearch\Tests\Fixtures\FakeEmbeddingProvider;
use YetiSearch\Tests\TestCase;

class EmbedPendingProviderTest extends TestCase
{
    public function test_a_provider_that_returns_too_few_vectors_is_a_provider_error(): void
    {
        $provider = new class extends FakeEmbeddingProvider {
            public function embed(array $texts, string $purpose = self::PURPOSE_DOCUMENT): array
            {
                $vectors = parent::embed($texts, $purpose);
                array_pop($vectors);

                return $vectors;
            }
        };

        $search = $this->createSearchInstance();
        $this->createTestIndex('emb');
        $search->indexBatch('emb', [
            ['id' => '1', 'content' => ['title' => 'alpha car', 'content' => 'one']],
            ['id' => '2', 'content' => ['title' => 'beta pizza', 'content' => 'two']],
        ]);
        $search->setEmbeddingProvider($provider);

        $run = $search->embedPending('emb');

        $this->assertSame(0, $run['embedded']);
        $this->assertSame(2, $run['pending']);
        $this->assertStringContainsString('1 vectors for 2 texts', (string)$run['error']);
    }
}
