<?php

declare(strict_types=1);

namespace YetiSearch\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use YetiSearch\Analyzers\StandardAnalyzer;
use YetiSearch\Exceptions\InvalidArgumentException;
use YetiSearch\Index\Indexer;
use YetiSearch\Storage\SqliteStorage;

/**
 * The write queue (auto_flush off), the flush when an indexer is destroyed, and how long text
 * is split into chunks. The storage is a mock that records what it is asked to write.
 */
class IndexerQueueAndChunkingTest extends TestCase
{
    /** @var array[] Every document handed to insertBatch(), in order */
    private array $written = [];

    /** @var string[] The ids handed to delete() */
    private array $deleted = [];

    /** @var string[] The ids handed to update() */
    private array $updated = [];

    private function indexer(array $config = [], ?LoggerInterface $logger = null, ?SqliteStorage $storage = null): Indexer
    {
        $storage = $storage ?? $this->storage();

        return new Indexer($storage, new StandardAnalyzer(), 'idx', $config, $logger);
    }

    private function storage(): SqliteStorage
    {
        $storage = $this->createMock(SqliteStorage::class);
        $storage->method('indexExists')->willReturn(true);
        $storage->method('insertBatch')->willReturnCallback(function (string $index, array $documents): void {
            foreach ($documents as $document) {
                $this->written[] = $document;
            }
        });
        $storage->method('delete')->willReturnCallback(function (string $index, string $id): bool {
            $this->deleted[] = $id;
            return true;
        });
        $storage->method('update')->willReturnCallback(function (string $index, string $id): bool {
            $this->updated[] = $id;
            return true;
        });

        return $storage;
    }

    /** @return string[] */
    private function writtenIds(): array
    {
        return array_column($this->written, 'id');
    }

    /** @return string[] The text of each chunk written */
    private function chunks(string $text, array $config): array
    {
        $indexer = $this->indexer($config);
        $indexer->insert(['id' => 'd', 'content' => ['title' => 'T', 'content' => $text]]);

        $chunks = [];
        foreach ($this->written as $document) {
            if (!empty($document['metadata']['is_chunk'])) {
                $chunks[] = $document['content']['content'];
            }
        }

        return $chunks;
    }

    public function testAnUpdateWinsOverAQueuedVersionOfTheDocument(): void
    {
        $indexer = $this->indexer(['auto_flush' => false]);
        $indexer->insert(['id' => '1', 'content' => ['title' => 'alpha', 'content' => 'first version']]);

        $indexer->update(['id' => '1', 'content' => ['title' => 'beta', 'content' => 'second version']]);
        $indexer->flush();

        $this->assertSame(['1'], $this->updated);
        $this->assertSame([], $this->written, 'The queued, older version was written after the update');
    }

    public function testADeleteRemovesTheQueuedDocumentAndItsChunks(): void
    {
        $indexer = $this->indexer(['auto_flush' => false, 'chunk_size' => 50, 'chunk_overlap' => 0]);
        $indexer->insert(['id' => 'long', 'content' => ['title' => 'Long', 'content' => str_repeat('A sentence here. ', 10)]]);
        $indexer->insert(['id' => 'keep', 'content' => ['title' => 'Keep', 'content' => 'short']]);

        $indexer->delete('long');
        $indexer->flush();

        $this->assertSame(['long'], $this->deleted);
        $this->assertSame(['keep'], $this->writtenIds(), 'The deleted document or a chunk of it came back on flush');
    }

    public function testAnUpdateDropsTheQueuedChunksToo(): void
    {
        $indexer = $this->indexer(['auto_flush' => false, 'chunk_size' => 50, 'chunk_overlap' => 0]);
        $indexer->insert(['id' => 'long', 'content' => ['title' => 'Long', 'content' => str_repeat('A sentence here. ', 10)]]);

        $indexer->update(['id' => 'long', 'content' => ['title' => 'Long', 'content' => str_repeat('Another one here. ', 10)]]);
        $indexer->flush();

        // The update itself writes the new document and its chunks, once
        $this->assertSame(count(array_unique($this->writtenIds())), count($this->writtenIds()));
        foreach ($this->written as $document) {
            $this->assertStringNotContainsString('sentence', json_encode($document['content']));
        }
    }

    public function testQueuedDocumentsAreFlushedWhenTheIndexerIsDestroyed(): void
    {
        $indexer = $this->indexer(['auto_flush' => false]);
        $indexer->insert([
            ['id' => '1', 'content' => ['title' => 'alpha']],
            ['id' => '2', 'content' => ['title' => 'beta']],
        ]);
        $this->assertSame([], $this->written);

        unset($indexer);

        $this->assertSame(['1', '2'], $this->writtenIds());
    }

    public function testAFailingFlushWhenTheIndexerIsDestroyedIsLoggedNotThrown(): void
    {
        $storage = $this->createMock(SqliteStorage::class);
        $storage->method('indexExists')->willReturn(true);
        $storage->method('insertBatch')->willThrowException(new \RuntimeException('disk full'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->anything(), $this->callback(function (array $context) {
            return $context['error'] === 'disk full';
        }));

        $indexer = $this->indexer(['auto_flush' => false], $logger, $storage);
        $indexer->insert(['id' => '1', 'content' => ['title' => 'alpha']]);

        unset($indexer);
    }

    /** @return array<string, array{int}> */
    public function badBatchSizes(): array
    {
        return ['zero' => [0], 'negative' => [-5]];
    }

    /** @dataProvider badBatchSizes */
    public function testABatchSizeBelowOneIsRefused(int $size): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('batch_size');

        $this->indexer(['batch_size' => $size]);
    }

    public function testTextWithoutSentenceEndingsIsSplitOnWords(): void
    {
        $chunks = $this->chunks(str_repeat('word ', 2000), ['chunk_size' => 100, 'chunk_overlap' => 10]);

        $this->assertGreaterThan(50, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(110, mb_strlen($chunk));
        }
    }

    public function testTextWithoutPunctuationIsSplitOnLines(): void
    {
        $chunks = $this->chunks(str_repeat("Heading line\nAnother line of text here\n", 40), ['chunk_size' => 100, 'chunk_overlap' => 0]);

        $this->assertGreaterThan(10, count($chunks));
        $this->assertLessThanOrEqual(100, max(array_map('mb_strlen', $chunks)));
    }

    public function testTextWithoutAnySeparatorIsSplitByCharactersWithoutBreakingThem(): void
    {
        $chunks = $this->chunks(str_repeat("\u{3053}\u{308C}\u{306F}\u{6587}\u{7AE0}\u{3067}\u{3059}\u{3002}", 100), [
            'chunk_size' => 100,
            'chunk_overlap' => 0,
        ]);

        $this->assertSame(8, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'));
            $this->assertLessThanOrEqual(100, mb_strlen($chunk));
        }
        $this->assertSame(800, mb_strlen(implode('', $chunks)));
    }

    public function testNoTextIsLostWhenChunking(): void
    {
        $text = implode(' ', array_map(function ($i) {
            return "Sentence number {$i} is here.";
        }, range(1, 30)));

        $joined = implode(' ', $this->chunks($text, ['chunk_size' => 100, 'chunk_overlap' => 20]));

        for ($i = 1; $i <= 30; $i++) {
            $this->assertStringContainsString("Sentence number {$i} is here.", $joined);
        }
    }

    /** @return array<string, array{int}> */
    public function overlaps(): array
    {
        return ['as long as the chunk' => [100], 'longer than the chunk' => [500]];
    }

    /** @dataProvider overlaps */
    public function testAnOverlapAsLongAsTheChunkDoesNotGrowTheChunks(int $overlap): void
    {
        $text = implode(' ', array_map(function ($i) {
            return "Sentence number {$i} is here.";
        }, range(1, 30)));

        $chunks = $this->chunks($text, ['chunk_size' => 100, 'chunk_overlap' => $overlap]);

        $this->assertGreaterThan(10, count($chunks));
        $this->assertLessThanOrEqual(110, max(array_map('mb_strlen', $chunks)));
    }

    public function testASingleChunkIsNotStored(): void
    {
        // One character over the size, but only because of the trailing space
        $chunks = $this->chunks(str_repeat('a', 100) . ' ', ['chunk_size' => 100, 'chunk_overlap' => 0]);

        $this->assertSame([], $chunks);
        $this->assertSame(['d'], $this->writtenIds());
        $this->assertArrayNotHasKey('chunked', $this->written[0]['metadata']);
    }

    public function testTextThatFitsIsNotChunked(): void
    {
        $this->assertSame([], $this->chunks('Short text.', ['chunk_size' => 100]));
    }
}
