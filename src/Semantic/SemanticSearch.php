<?php

namespace YetiSearch\Semantic;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use YetiSearch\Contracts\EmbeddingProviderInterface;
use YetiSearch\Exceptions\EmbeddingException;
use YetiSearch\Storage\SqliteStorage;

/**
 * Embeds an index's documents and ranks them against a query by meaning.
 *
 * Embedding is a separate step from indexing, so a slow or failing provider
 * never holds up or breaks an index run: index as usual, then call
 * embedPending() until nothing is left. Only documents whose text changed
 * since their last embedding are sent to the provider.
 */
class SemanticSearch
{
    private EmbeddingProviderInterface $provider;
    private SqliteStorage $storage;
    private array $config;
    private LoggerInterface $logger;

    public function __construct(
        EmbeddingProviderInterface $provider,
        SqliteStorage $storage,
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->provider = $provider;
        $this->storage = $storage;
        $this->config = array_merge(self::defaults(), $config);
        $this->logger = $logger ?? new NullLogger();
    }

    public static function defaults(): array
    {
        return [
            // Share of the final ranking that comes from meaning rather than
            // keywords: 0 is keyword search, 1 is semantic search alone.
            'weight' => 0.5,
            // Reciprocal rank fusion constant. Higher flattens the difference
            // between neighbouring ranks.
            'rrf_k' => 60,
            // How many results each side contributes before fusion.
            'candidates' => 100,
            // Documents less similar than this never enter the results on
            // meaning alone. Where related text lands depends on the model:
            // around 0.3 to 0.6 for OpenAI's text-embedding-3, higher for
            // nomic-embed-text.
            'min_similarity' => 0.25,
            // How far the best match must stand above the median document
            // before meaning adds any results. A query that means nothing in
            // particular ("asdf", a typo storm) is about as similar to every
            // document; a real one stands out. Measured with
            // text-embedding-3-small: 0.23 to 0.47 for real queries, 0.12 to
            // 0.14 for noise. Applies from 10 candidate documents up, where a
            // median means something.
            'min_margin' => 0.15,
            // Documents must be at least this share as similar as the best
            // match, which trims the long tail of loosely related ones.
            'relative_similarity' => 0.6,
            // Document fields that make up the embedded text.
            'fields' => ['title', 'content'],
            // Characters of text per document. Keeps every document inside
            // the model's input limit.
            'max_chars' => 6000,
            // Documents per provider call in embedPending().
            'batch_size' => 32,
            // Query embeddings kept in the database, so a repeated search
            // costs no provider call.
            'query_cache_size' => 5000,
        ];
    }

    public function getProvider(): EmbeddingProviderInterface
    {
        return $this->provider;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Embed up to $limit documents that have no vector, or whose text or
     * model changed since they got one.
     *
     * @return array{embedded:int, pending:int, total:int, pruned:int, error:?string}
     *         pending is what is still left after this call.
     */
    public function embedPending(string $index, int $limit = 200): array
    {
        $pruned = $this->storage->pruneVectors($index);
        $scan = $this->scan($index, max(0, $limit));
        $model = $this->provider->modelId();

        $embedded = 0;
        $error = null;
        foreach (array_chunk($scan['queue'], max(1, (int)$this->config['batch_size'])) as $batch) {
            try {
                $vectors = $this->provider->embed(
                    array_column($batch, 'text'),
                    EmbeddingProviderInterface::PURPOSE_DOCUMENT
                );
            } catch (\Throwable $e) {
                // Keep what earlier batches stored; the rest stays pending.
                $error = $e->getMessage();
                $this->logger->warning('Embedding failed; documents stay pending', [
                    'index' => $index,
                    'error' => $error,
                ]);
                break;
            }

            $rows = [];
            foreach ($batch as $i => $doc) {
                $rows[] = [
                    'id' => $doc['id'],
                    'hash' => $doc['hash'],
                    'model' => $model,
                    'vector' => VectorMath::normalize($vectors[$i]),
                ];
            }
            $this->storage->upsertVectors($index, $rows);
            $embedded += count($rows);
        }

        return [
            'embedded' => $embedded,
            'pending' => $scan['pending'] - $embedded,
            'total' => $scan['total'],
            'pruned' => $pruned,
            'error' => $error,
        ];
    }

    /**
     * @return array{model:string, total:int, embedded:int, pending:int}
     */
    public function stats(string $index): array
    {
        $scan = $this->scan($index, 0);

        return [
            'model' => $this->provider->modelId(),
            'total' => $scan['total'],
            'embedded' => $scan['total'] - $scan['pending'],
            'pending' => $scan['pending'],
        ];
    }

    public function clear(string $index): void
    {
        $this->storage->clearVectors($index);
    }

    public function hasVectors(string $index): bool
    {
        return $this->storage->hasVectors($index, $this->provider->modelId());
    }

    /**
     * The query as a normalized vector, from the cache when it was embedded
     * before.
     *
     * @return float[]
     * @throws EmbeddingException
     */
    public function embedQuery(string $query): array
    {
        $text = self::normalizeQuery($query);
        $key = sha1($this->provider->modelId() . "\0" . $text);

        $cached = $this->storage->getCachedQueryEmbedding($key);
        if ($cached !== null) {
            return $cached;
        }

        $vectors = $this->provider->embed([$text], EmbeddingProviderInterface::PURPOSE_QUERY);
        if (empty($vectors[0])) {
            throw new EmbeddingException('The provider returned no vector for the query');
        }
        $vector = VectorMath::normalize($vectors[0]);
        $this->storage->cacheQueryEmbedding($key, $vector, (int)$this->config['query_cache_size']);

        return $vector;
    }

    /**
     * @param float[] $queryVector
     * @return array<string, float> Document id => similarity, best first
     */
    public function nearest(string $index, array $queryVector, array $filters, ?string $language, int $k): array
    {
        $stats = null;
        $nearest = $this->storage->nearestVectors(
            $index,
            $queryVector,
            $this->provider->modelId(),
            $filters,
            $language,
            $k,
            (float)$this->config['min_similarity'],
            $stats
        );
        if (empty($nearest)) {
            return [];
        }

        $best = reset($nearest);
        if (($stats['count'] ?? 0) >= 10 && $best - (float)($stats['median'] ?? 0.0) < (float)$this->config['min_margin']) {
            return [];
        }

        $cutoff = $best * (float)$this->config['relative_similarity'];

        return array_filter($nearest, function ($similarity) use ($cutoff) {
            return $similarity >= $cutoff;
        });
    }

    /**
     * Document rows in the form a keyword search returns them.
     *
     * @param string[] $ids
     * @return array<string, array>
     */
    public function getSearchRows(string $index, array $ids): array
    {
        return $this->storage->getSearchRows($index, $ids);
    }

    /**
     * Merge a keyword ranking and a vector ranking with reciprocal rank
     * fusion. A document scores weight/(k + rank) from each list it is in,
     * so agreement between the two lifts it and BM25 scores never have to be
     * compared with similarities.
     *
     * The vector share is also scaled by the document's similarity relative
     * to the best match. Plain rank fusion would score the second-closest
     * document almost as high as the closest however far apart they are
     * (0.55 and 0.25 would show as 100 and 98); this keeps that gap visible
     * without changing the order within the vector list.
     *
     * @param string[] $keywordIds Best first
     * @param array<string, float> $vectorScores Id => similarity, best first
     * @return array<string, float> Id => score, best first, scaled so a
     *         document ranked first in both lists scores 100
     */
    public static function fuse(array $keywordIds, array $vectorScores, float $weight, int $k = 60): array
    {
        $weight = max(0.0, min(1.0, $weight));
        $k = max(1, $k);

        $scores = [];
        foreach (array_values($keywordIds) as $rank => $id) {
            $scores[$id] = (1.0 - $weight) / ($k + $rank + 1);
        }
        $best = empty($vectorScores) ? 0.0 : max($vectorScores);
        $rank = 0;
        foreach ($vectorScores as $id => $similarity) {
            $relative = $best > 0.0 ? max(0.0, $similarity) / $best : 1.0;
            $scores[$id] = ($scores[$id] ?? 0.0) + $weight / ($k + ++$rank) * $relative;
        }

        $best = 1.0 / ($k + 1);
        foreach ($scores as $id => $score) {
            $scores[$id] = $score / $best * 100.0;
        }

        // Stable for ties: array order (keyword results first) decides.
        $order = array_flip(array_keys($scores));
        uksort($scores, function ($a, $b) use ($scores, $order) {
            return ($scores[$b] <=> $scores[$a]) ?: ($order[$a] <=> $order[$b]);
        });

        return $scores;
    }

    /**
     * The text a document is embedded as: its configured fields, as plain
     * text, cut to max_chars.
     */
    public function documentText(array $content): string
    {
        $parts = [];
        foreach ((array)$this->config['fields'] as $field) {
            $value = $content[$field] ?? null;
            if (is_array($value)) {
                $value = implode(', ', array_filter($value, 'is_scalar'));
            }
            if (!is_scalar($value)) {
                continue;
            }
            $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim((string)preg_replace('/\s+/u', ' ', $text));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        $text = implode("\n\n", $parts);
        $max = (int)$this->config['max_chars'];
        if ($max > 0 && mb_strlen($text, 'UTF-8') > $max) {
            $text = mb_substr($text, 0, $max, 'UTF-8');
        }

        return $text;
    }

    public static function normalizeQuery(string $query): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', mb_strtolower($query, 'UTF-8')));
    }

    /**
     * Walk the index once, counting documents that need a vector and queuing
     * up to $limit of them.
     *
     * @return array{total:int, pending:int, queue:array<int, array{id:string, hash:string, text:string}>}
     */
    private function scan(string $index, int $limit): array
    {
        $model = $this->provider->modelId();
        $total = 0;
        $pending = 0;
        $queue = [];

        foreach ($this->storage->iterateDocumentsForEmbedding($index) as $doc) {
            // A chunked document's own row repeats the text its chunks carry;
            // the chunks are what get embedded. Callers that pre-chunk can
            // mark a short document chunked with zero chunks, and that one
            // has only its own row to embed.
            $meta = $doc['metadata'];
            if (!empty($meta['chunked']) && empty($meta['is_chunk']) && (int)($meta['chunks'] ?? 1) > 0) {
                continue;
            }

            $text = $this->documentText($doc['content']);
            if ($text === '') {
                continue;
            }

            $total++;
            $hash = sha1($text);
            if ($doc['hash'] === $hash && $doc['model'] === $model) {
                continue;
            }

            $pending++;
            if (count($queue) < $limit) {
                $queue[] = ['id' => $doc['id'], 'hash' => $hash, 'text' => $text];
            }
        }

        return ['total' => $total, 'pending' => $pending, 'queue' => $queue];
    }
}
