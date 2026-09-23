<?php

namespace YetiSearch\Tests\Unit\Semantic;

use PHPUnit\Framework\TestCase;
use YetiSearch\Contracts\EmbeddingProviderInterface;
use YetiSearch\Exceptions\EmbeddingException;
use YetiSearch\Semantic\OpenAICompatibleEmbeddingProvider;
use YetiSearch\Semantic\SemanticSearch;
use YetiSearch\Semantic\VectorMath;

class SemanticUnitTest extends TestCase
{
    public function testFuseRewardsAgreementAndScalesToOneHundred(): void
    {
        $fused = SemanticSearch::fuse(['a', 'b', 'c'], ['a' => 0.8, 'd' => 0.8], 0.5, 60);

        $this->assertSame(['a', 'b', 'd', 'c'], array_map('strval', array_keys($fused)));
        $this->assertEqualsWithDelta(100.0, $fused['a'], 0.0001);
    }

    public function testFuseWeightZeroIsKeywordOrder(): void
    {
        $fused = SemanticSearch::fuse(['a', 'b'], ['b' => 0.9, 'c' => 0.5], 0.0, 60);

        $this->assertSame(['a', 'b', 'c'], array_map('strval', array_keys($fused)));
        $this->assertSame(0.0, $fused['c']);
    }

    public function testFuseKeepsNumericIds(): void
    {
        $fused = SemanticSearch::fuse(['10', '2'], ['2' => 0.7], 0.5, 60);

        $this->assertSame(['2', '10'], array_map('strval', array_keys($fused)));
    }

    public function testFuseKeepsTheSimilarityGapVisible(): void
    {
        $fused = SemanticSearch::fuse([], ['export' => 0.55, 'billing' => 0.25], 1.0, 60);

        $this->assertEqualsWithDelta(100.0, $fused['export'], 0.0001);
        $this->assertEqualsWithDelta(0.25 / 0.55 * 61 / 62 * 100, $fused['billing'], 0.0001);
    }

    public function testVectorsRoundTripNormalized(): void
    {
        $v = VectorMath::normalize([3.0, 4.0]);
        $this->assertEqualsWithDelta([0.6, 0.8], $v, 1e-9);

        $stored = unpack('g*', VectorMath::pack($v));
        $this->assertEqualsWithDelta(1.0, VectorMath::dotOneIndexed($v, $stored), 1e-6);
        $this->assertEqualsWithDelta($v, VectorMath::unpack(VectorMath::pack($v)), 1e-6);
    }

    public function testNormalizeQuery(): void
    {
        $this->assertSame('reset my password', SemanticSearch::normalizeQuery("  Reset  MY\tPassword "));
    }

    private function provider(array $config, array $responses, array &$requests): OpenAICompatibleEmbeddingProvider
    {
        return new class ($config, $responses, $requests) extends OpenAICompatibleEmbeddingProvider {
            private array $responses;
            private array $requests;

            public function __construct(array $config, array $responses, array &$requests)
            {
                parent::__construct(array_merge(['max_retries' => 0], $config));
                $this->responses = $responses;
                $this->requests = &$requests;
            }

            protected function post(string $url, array $headers, string $body, float $timeout): array
            {
                $this->requests[] = compact('url', 'headers', 'timeout') + ['body' => json_decode($body, true)];

                return array_shift($this->responses);
            }
        };
    }

    public function testProviderSendsTheOpenAiRequestAndOrdersByIndex(): void
    {
        $requests = [];
        $provider = $this->provider(
            ['api_key' => 'sk-test', 'dimensions' => 2],
            [[200, json_encode(['data' => [
                ['index' => 1, 'embedding' => [0.0, 1.0]],
                ['index' => 0, 'embedding' => [1.0, 0.0]],
            ]])]],
            $requests
        );

        $vectors = $provider->embed(['first', 'second']);

        $this->assertSame([[1.0, 0.0], [0.0, 1.0]], $vectors);
        $this->assertSame('https://api.openai.com/v1/embeddings', $requests[0]['url']);
        $this->assertContains('Authorization: Bearer sk-test', $requests[0]['headers']);
        $this->assertSame(['model' => 'text-embedding-3-small', 'input' => ['first', 'second'], 'dimensions' => 2], $requests[0]['body']);
        $this->assertSame('text-embedding-3-small:2', $provider->modelId());
        $this->assertSame(2, $provider->dimensions());
    }

    public function testProviderSendsEveryInputAsAString(): void
    {
        $requests = [];
        $provider = $this->provider(
            ['api_key' => 'sk-test'],
            [[200, json_encode(['data' => [
                ['index' => 0, 'embedding' => [1.0, 0.0]],
                ['index' => 1, 'embedding' => [0.0, 1.0]],
            ]])]],
            $requests
        );

        $provider->embed([1234567, 'yes or no']);

        $this->assertSame(['1234567', 'yes or no'], $requests[0]['body']['input']);
    }

    public function testProviderAppliesQueryPrefixInputTypeAndQueryTimeout(): void
    {
        $requests = [];
        $provider = $this->provider(
            ['base_url' => 'http://localhost:11434/v1/', 'model' => 'nomic-embed-text', 'query_prefix' => 'search_query: ', 'input_type' => true],
            [[200, json_encode(['data' => [['embedding' => [0.5, 0.5, 0.5]]]])]],
            $requests
        );

        $provider->embed(['hello'], EmbeddingProviderInterface::PURPOSE_QUERY);

        $this->assertSame('http://localhost:11434/v1/embeddings', $requests[0]['url']);
        $this->assertSame(['search_query: hello'], $requests[0]['body']['input']);
        $this->assertSame('query', $requests[0]['body']['input_type']);
        $this->assertSame(5.0, $requests[0]['timeout']);
        $this->assertArrayNotHasKey('dimensions', $requests[0]['body']);
        $this->assertSame(3, $provider->dimensions());
        $this->assertStringStartsWith('nomic-embed-text:p', $provider->modelId());
    }

    public function testProviderBatches(): void
    {
        $requests = [];
        $one = [200, json_encode(['data' => [['embedding' => [1.0]], ['embedding' => [1.0]]]])];
        $provider = $this->provider(['batch_size' => 2], [$one, [200, json_encode(['data' => [['embedding' => [1.0]]]])]], $requests);

        $this->assertCount(3, $provider->embed(['a', 'b', 'c']));
        $this->assertCount(2, $requests);
    }

    public function testProviderReportsHttpErrors(): void
    {
        $requests = [];
        $provider = $this->provider([], [[401, json_encode(['error' => ['message' => 'Incorrect API key']])]], $requests);

        $this->expectException(EmbeddingException::class);
        $this->expectExceptionMessage('HTTP 401: Incorrect API key');
        $provider->embed(['a']);
    }

    public function testProviderRejectsAShortResponse(): void
    {
        $requests = [];
        $provider = $this->provider([], [[200, json_encode(['data' => [['embedding' => [1.0]]]])]], $requests);

        $this->expectException(EmbeddingException::class);
        $provider->embed(['a', 'b']);
    }
}
