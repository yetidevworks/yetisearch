<?php

namespace YetiSearch\Index;

use YetiSearch\Contracts\IndexerInterface;
use YetiSearch\Contracts\StorageInterface;
use YetiSearch\Contracts\AnalyzerInterface;
use YetiSearch\Exceptions\IndexException;
use YetiSearch\Exceptions\InvalidArgumentException;
use YetiSearch\Exceptions\StorageException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Indexer implements IndexerInterface
{
    /** The fields an indexer indexes when its config names none */
    private const DEFAULT_FIELDS = [
        'title' => ['boost' => 3.0, 'store' => true],
        'content' => ['boost' => 1.0, 'store' => true],
        'excerpt' => ['boost' => 2.0, 'store' => true],
        'tags' => ['boost' => 2.5, 'store' => true],
        'category' => ['boost' => 2.0, 'store' => true],
        'author' => ['boost' => 1.5, 'store' => true],
        'url' => ['boost' => 1.0, 'store' => true, 'index' => false],
        'route' => ['boost' => 1.0, 'store' => true, 'index' => false],
    ];

    private StorageInterface $storage;
    private AnalyzerInterface $analyzer;
    private LoggerInterface $logger;
    private string $indexName;
    private array $config;
    /** @var array[] Documents waiting for a flush, each one with its chunks as a group of storage documents */
    private array $batchQueue = [];
    private int $batchSize = 100;

    public function __construct(
        StorageInterface $storage,
        AnalyzerInterface $analyzer,
        string $indexName,
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->storage = $storage;
        $this->analyzer = $analyzer;
        $this->indexName = $indexName;
        $this->config = array_merge([
            'batch_size' => 100,
            'auto_flush' => true,
            'fields' => self::DEFAULT_FIELDS,
            'chunk_size' => 1000,
            'chunk_overlap' => 100
        ], $config);

        $this->config['fields'] = $this->normalizeFieldsConfig($this->config['fields']);

        $this->batchSize = (int)$this->config['batch_size'];
        if ($this->batchSize < 1) {
            throw new InvalidArgumentException(
                "Indexer option 'batch_size' must be at least 1, {$this->batchSize} given"
            );
        }
        $this->logger = $logger ?? new NullLogger();

        $this->ensureIndexExists();
    }

    /**
     * The boost of each field this indexer indexes, by which search ranks its documents (see
     * boosts())
     *
     * @return array<string, float>
     */
    public function getFieldBoosts(): array
    {
        return self::boosts($this->config['fields']);
    }

    /**
     * The boost of each field indexed with a `fields` option (null for the default fields): a
     * field listed by name alone, or without a boost, has 1.0. None when every field has the
     * same boost, as then there is nothing to weigh.
     *
     * @param array|null $fields
     * @return array<string, float>
     */
    public static function boosts(?array $fields): array
    {
        $boosts = [];
        foreach ($fields ?? self::DEFAULT_FIELDS as $name => $field) {
            if (is_int($name) && is_string($field)) {
                $boosts[$field] = 1.0;
            } elseif (is_string($name) && is_array($field) && ($field['index'] ?? true)) {
                $boosts[$name] = (float)($field['boost'] ?? 1.0);
            }
        }

        return count(array_unique($boosts)) > 1 ? $boosts : [];
    }

    /**
     * Normalize the "fields" option into its canonical associative form.
     *
     * The documented form maps a field name to its config:
     *
     *     ['title' => ['boost' => 3.0], 'sku' => ['boost' => 1.0]]
     *
     * A flat list of field names is accepted as shorthand and expanded to the
     * default boost:
     *
     *     ['title', 'sku']
     *
     * The two forms may be mixed. Previously the flat form was accepted without
     * complaint and then read as if it were associative, so the integer list
     * keys became the FTS column names and the index was silently built wrong.
     *
     * @param mixed $fields
     * @return array<string, array<string, mixed>>
     * @throws InvalidArgumentException If the option is not one of the forms above
     */
    private function normalizeFieldsConfig($fields): array
    {
        if (!is_array($fields)) {
            throw new InvalidArgumentException(
                "Indexer option 'fields' must be an array, " . gettype($fields) . ' given'
            );
        }

        $normalized = [];

        foreach ($fields as $key => $value) {
            if (is_int($key)) {
                // Shorthand: a flat list of field names.
                if (!is_string($value)) {
                    throw new InvalidArgumentException(
                        "Indexer option 'fields' contains a non-string field name at position {$key}: "
                        . gettype($value) . ' given. Use a list of field names, or a map of field name '
                        . 'to config such as [\'title\' => [\'boost\' => 3.0]].'
                    );
                }

                $name = $this->validateFieldName($value);
                $normalized[$name] = ['boost' => 1.0];
                continue;
            }

            $name = $this->validateFieldName((string)$key);

            if (!is_array($value)) {
                throw new InvalidArgumentException(
                    "Indexer option 'fields' entry '{$name}' must be an array of settings, "
                    . gettype($value) . ' given. Use [\'' . $name . '\' => [\'boost\' => 1.0]], '
                    . 'or list the field name on its own to take the defaults.'
                );
            }

            $normalized[$name] = $value;
        }

        return $normalized;
    }

    /**
     * Field names become FTS5 column names, so they must be plain SQL identifiers.
     *
     * @throws InvalidArgumentException If the name is not a usable identifier
     */
    private function validateFieldName(string $name): string
    {
        $name = trim($name);

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new InvalidArgumentException(
                "Invalid field name '{$name}' in indexer option 'fields': "
                . 'must match /^[a-zA-Z_][a-zA-Z0-9_]*$/'
            );
        }

        return $name;
    }

    public function insert($documents): void
    {
        // Handle both single document and array of documents
        $isSingle = isset($documents['id']) || (isset($documents['content']) && !is_numeric(key($documents)));
        $documents = $isSingle ? [$documents] : $documents;

        $this->logger->debug('Inserting document(s)', ['count' => count($documents)]);

        // Each processed document is a group: its chunks and then the document itself. A group
        // is written in one storage call, so a document and its chunks are stored together or
        // not at all, and nothing is written until every document has been processed.
        $processed = [];
        $errors = [];

        foreach ($documents as $document) {
            if (!is_array($document)) {
                $errors[] = 'Invalid document type - must be array';
                continue;
            }

            try {
                $processed[] = $this->processDocument($document);
            } catch (StorageException $e) {
                // A document that cannot be stored stops the whole call before anything is written
                $this->logger->error('Document cannot be stored', [
                    'id' => $document['id'] ?? null,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            } catch (\Exception $e) {
                $errors[] = [
                    'id' => $document['id'] ?? null,
                    'error' => $e->getMessage()
                ];
                $this->logger->error('Failed to process document', [
                    'id' => $document['id'] ?? null,
                    'error' => $e->getMessage()
                ]);
            }
        }

        if (!empty($processed)) {
            if ($this->config['auto_flush']) {
                // Immediate insert using batch for efficiency, a document and its chunks always in the same batch
                foreach (array_chunk($processed, $this->batchSize) as $groups) {
                    $this->storage->insertBatch($this->indexName, array_merge(...$groups));
                }
            } else {
                // Add to queue for later flush
                $this->batchQueue = array_merge($this->batchQueue, $processed);

                if (count($this->batchQueue) >= $this->batchSize) {
                    $this->flush();
                }
            }
        }

        if (!empty($errors)) {
            $this->logger->warning('Some documents failed to process', ['errors' => $errors]);
            if ($isSingle) {
                throw new IndexException("Failed to index document: " . $errors[0]['error']);
            }
        }

        $this->logger->info('Documents processed', [
            'total' => count($documents),
            'success' => count($processed),
            'failed' => count($errors)
        ]);
    }

    // Add indexBatch method for backward compatibility
    public function indexBatch(array $documents): void
    {
        $this->insert($documents);
    }

    public function update(array $document): void
    {
        if (!isset($document['id'])) {
            throw new IndexException('Document must have an id for update');
        }
        $id = $document['id'];
        $this->logger->debug('Updating document', ['id' => $id]);

        try {
            $group = $this->processDocument($document);
            if (count($group) === 1) {
                $this->storage->update($this->indexName, $id, $group[0]);
            } else {
                // The chunks and the document go in one call, so they are stored together or not at all
                $this->storage->insertBatch($this->indexName, $group);
            }
            $this->dropQueued($id);

            $this->logger->info('Document updated successfully', ['id' => $id]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update document', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new IndexException("Failed to update document: " . $e->getMessage(), 0, $e);
        }
    }

    public function delete(string $id): void
    {
        $this->logger->debug('Deleting document', ['id' => $id]);

        try {
            $this->storage->delete($this->indexName, $id);
            $this->dropQueued($id);
            $this->logger->info('Document deleted successfully', ['id' => $id]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete document', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new IndexException("Failed to delete document: " . $e->getMessage(), 0, $e);
        }
    }

    public function clear(): void
    {
        $this->logger->debug('Clearing index', ['index' => $this->indexName]);

        try {
            $this->storage->dropIndex($this->indexName);
            $this->ensureIndexExists();
            $this->batchQueue = [];

            $this->logger->info('Index cleared successfully', ['index' => $this->indexName]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to clear index', [
                'index' => $this->indexName,
                'error' => $e->getMessage()
            ]);
            throw new IndexException("Failed to clear index: " . $e->getMessage(), 0, $e);
        }
    }

    public function rebuild(array $documents): void
    {
        $this->logger->debug('Rebuilding index', [
            'index' => $this->indexName,
            'documents' => count($documents)
        ]);

        $this->clear();
        $this->insert($documents);
        $this->optimize();

        $this->logger->info('Index rebuilt successfully', [
            'index' => $this->indexName,
            'documents' => count($documents)
        ]);
    }

    public function optimize(): void
    {
        $this->logger->debug('Optimizing index', ['index' => $this->indexName]);

        try {
            $this->flush();
            $this->storage->optimize($this->indexName);

            $this->logger->info('Index optimized successfully', ['index' => $this->indexName]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to optimize index', [
                'index' => $this->indexName,
                'error' => $e->getMessage()
            ]);
            throw new IndexException("Failed to optimize index: " . $e->getMessage(), 0, $e);
        }
    }

    public function getStats(): array
    {
        return $this->storage->getIndexStats($this->indexName);
    }

    public function flush(): void
    {
        if (empty($this->batchQueue)) {
            return;
        }

        // Use batch insert for better performance
        $this->storage->insertBatch($this->indexName, array_merge(...$this->batchQueue));

        $this->batchQueue = [];
    }

    /**
     * Nothing else flushes a queue left behind when auto_flush is off, so its documents would be
     * lost. A destructor must not throw, so a failure is logged.
     */
    public function __destruct()
    {
        try {
            $this->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to flush queued documents', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Forget the queued version of a document and its chunks, so that a write made straight to the
     * storage is not overwritten, or a deleted document brought back, by the next flush.
     */
    private function dropQueued($id): void
    {
        $this->batchQueue = array_values(array_filter($this->batchQueue, function (array $group) use ($id) {
            return (string)end($group)['id'] !== (string)$id;
        }));
    }

    /**
     * Turn a document into the storage documents that are written for it: its chunks, if it has
     * any, and then the document itself. Nothing is written here. The document, its metadata and
     * every chunk are checked first, so one that cannot be stored is refused before any of them is.
     *
     * @return array[] The chunks, then the document
     * @throws StorageException If the content or metadata of the document or of a chunk cannot be encoded
     */
    private function processDocument(array $document): array
    {
        $content = $document['content'] ?? [];
        $metadata = $document['metadata'] ?? [];
        $language = $document['language'] ?? null;
        $id = $document['id'] ?? bin2hex(random_bytes(8));
        $type = $document['type'] ?? 'default';
        $timestamp = $document['timestamp'] ?? time();

        // Meaning-only: embedded and ranked by meaning, never by keywords.
        // Kept in the metadata so the storage and every chunk see it.
        if (!empty($document['meaning_only'])) {
            $metadata['_meaning_only'] = true;
        }

        $processedContent = [];

        foreach ($this->config['fields'] as $fieldName => $fieldConfig) {
            if (!isset($content[$fieldName])) {
                continue;
            }

            if ($fieldConfig['store'] ?? true) {
                $processedContent[$fieldName] = $content[$fieldName];
            }
        }

        // Pre-chunked documents are used as they are; other content is chunked when it is long
        // enough to make more than one chunk (a lone chunk would only repeat the document).
        $preChunked = !empty($document['chunks']) && is_array($document['chunks']);
        $chunks = $preChunked ? $document['chunks'] : $this->chunkContent($processedContent);
        if (!$preChunked && count($chunks) < 2) {
            $chunks = [];
        }
        if ($chunks) {
            $metadata['chunks'] = count($chunks);
            $metadata['chunked'] = true;
            if ($preChunked) {
                $metadata['pre_chunked'] = true;
            }
        }

        $chunkDocs = [];
        foreach ($chunks as $index => $chunkData) {
            // A chunk is a string, merged with the parent content, or an array with content and
            // optional metadata. Content that is an array (pre-processed by a plugin) is used as is.
            $chunkMetadata = is_array($chunkData) ? ($chunkData['metadata'] ?? []) : [];
            $chunkContent = is_array($chunkData) ? ($chunkData['content'] ?? '') : $chunkData;
            if (!is_array($chunkContent)) {
                $chunkContent = array_merge($processedContent, ['content' => $chunkContent]);
            }

            $chunkDocs[] = [
                'id' => $id . '#chunk' . $index,
                'parent_id' => $id,
                'content' => $chunkContent,
                'metadata' => array_merge($metadata, $chunkMetadata, [
                    'chunk_index' => $index,
                    'is_chunk' => true,
                    'parent_route' => $chunkContent['route'] ?? $processedContent['route'] ?? ''
                ]),
                'language' => $language,
                'type' => $type,
                'timestamp' => $timestamp
            ];
        }

        $group = $chunkDocs;
        $group[] = [
            'id' => $id,
            'content' => $processedContent,
            'metadata' => $metadata,
            'language' => $language,
            'type' => $type,
            'timestamp' => $timestamp,
            'indexed_at' => time()
        ];

        foreach ($group as &$storageDocument) {
            // The document and each of its chunks carry its geo data
            foreach (['geo', 'geo_bounds'] as $key) {
                if (isset($document[$key])) {
                    $storageDocument[$key] = $document[$key];
                }
            }
            $this->assertStorable($storageDocument);
        }
        unset($storageDocument);

        return $group;
    }

    /**
     * Check that the content and the metadata of a document encode as the storage encodes them
     * (invalid UTF-8 is replaced, so it is not a reason to refuse), at the depth the storage
     * allows, which is one less than json_decode()'s default so that it can be read back.
     *
     * @throws StorageException If either cannot be encoded: NAN, INF, a resource, nesting too deep, a
     *                          serializer that throws
     */
    private function assertStorable(array $document): void
    {
        foreach (['content', 'metadata'] as $what) {
            if (self::alwaysEncodes($document[$what] ?? [])) {
                continue;
            }
            try {
                $json = json_encode($document[$what] ?? [], JSON_INVALID_UTF8_SUBSTITUTE, 511);
            } catch (\Throwable $e) {
                // A JsonSerializable that throws is a document that cannot be stored, not a
                // processing error to skip the document for
                throw new StorageException(
                    "Failed to encode the {$what} of document '{$document['id']}': " . $e->getMessage(),
                    0,
                    $e
                );
            }
            if ($json === false) {
                throw new StorageException(
                    "Failed to encode the {$what} of document '{$document['id']}': " . json_last_error_msg()
                );
            }
        }
    }

    /**
     * Whether a value is one json_encode() cannot fail on, without encoding it: strings (invalid
     * UTF-8 is replaced), integers, booleans, null, finite floats, and arrays of them up to a depth
     * of 8, far under the limit of 511. Objects, resources, INF, NAN and deeper arrays are encoded
     * to find out.
     *
     * @param mixed $value
     */
    private static function alwaysEncodes($value, int $depth = 0): bool
    {
        if (is_float($value)) {
            return is_finite($value);
        }
        if (!is_array($value)) {
            return $value === null || is_scalar($value);
        }
        if ($depth >= 8) {
            return false;
        }
        foreach ($value as $item) {
            if (!self::alwaysEncodes($item, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Split the main content into chunks of about chunk_size characters, on sentences where
     * there are any, then on lines, then on words, and at last on characters. Content that fits
     * in one chunk is not split.
     *
     * @return string[]
     */
    private function chunkContent(array $content): array
    {
        $mainContent = $content['content'] ?? '';
        $chunkSize = max(1, (int)$this->config['chunk_size']);
        if (!is_string($mainContent) || mb_strlen($mainContent, 'UTF-8') <= $chunkSize) {
            return [];
        }

        // An overlap as long as the chunk would repeat the whole of it in the next one
        $overlap = min(max(0, (int)$this->config['chunk_overlap']), $chunkSize - 1);
        $chunks = [];
        $currentChunk = '';
        $currentSize = 0;

        $sentences = preg_split('/(?<=[.!?])\s+/', $mainContent, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($sentences as $sentence) {
            foreach ($this->splitToFit($sentence, $chunkSize) as $piece) {
                $pieceSize = mb_strlen($piece, 'UTF-8');

                if ($currentSize + $pieceSize > $chunkSize && $currentChunk !== '') {
                    $chunks[] = trim($currentChunk);

                    // The overlap leaves room for the piece, so a chunk stays near chunk_size
                    $room = min($overlap, $chunkSize - $pieceSize - 1);
                    $currentChunk = $this->getOverlapText($currentChunk, $room) . ' ' . $piece;
                    $currentSize = mb_strlen($currentChunk, 'UTF-8');
                } else {
                    $currentChunk .= ' ' . $piece;
                    $currentSize += $pieceSize + 1;
                }
            }
        }

        if (trim($currentChunk) !== '') {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * Cut a text that is longer than $size characters into parts of at most $size: on line
     * breaks first, then on whitespace, then every $size characters.
     *
     * @return string[]
     */
    private function splitToFit(string $text, int $size, array $patterns = ['/\R+/', '/\s+/']): array
    {
        if (mb_strlen($text, 'UTF-8') <= $size) {
            return [$text];
        }
        if (!$patterns) {
            return mb_str_split($text, $size, 'UTF-8');
        }

        $pattern = array_shift($patterns);
        $parts = [];
        foreach (preg_split($pattern, $text, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            $parts[] = $this->splitToFit($part, $size, $patterns);
        }

        return array_merge([], ...$parts);
    }

    private function getOverlapText(string $text, int $overlapSize): string
    {
        if ($overlapSize <= 0) {
            return '';
        }
        if (mb_strlen($text, 'UTF-8') <= $overlapSize) {
            return $text;
        }

        $words = explode(' ', $text);
        $overlapWords = [];
        $currentSize = 0;

        for ($i = count($words) - 1; $i >= 0 && $currentSize < $overlapSize; $i--) {
            array_unshift($overlapWords, $words[$i]);
            $currentSize += mb_strlen($words[$i], 'UTF-8') + 1;
        }

        return implode(' ', $overlapWords);
    }

    private function ensureIndexExists(): void
    {
        if (!$this->storage->indexExists($this->indexName)) {
            // Pass field names from config if available
            $storageOptions = $this->config;
            if (isset($this->config['fields']) && is_array($this->config['fields'])) {
                // Extract only indexed field names for FTS columns
                $fieldNames = [];
                foreach ($this->config['fields'] as $fieldName => $fieldConfig) {
                    // Only include fields that should be indexed (default is true)
                    if ($fieldConfig['index'] ?? true) {
                        $fieldNames[] = $fieldName;
                    }
                }
                // Only set fields if we have indexed fields other than just 'content'
                if (!empty($fieldNames) && $fieldNames !== ['content']) {
                    $storageOptions['fields'] = $fieldNames;
                }
            }
            $this->storage->createIndex($this->indexName, $storageOptions);
        } else {
            // Ensure all required tables exist, including spatial table
            $this->storage->ensureSpatialTableExists($this->indexName);
        }
    }
}
