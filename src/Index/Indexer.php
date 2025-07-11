<?php

namespace YetiSearch\Index;

use YetiSearch\Contracts\IndexerInterface;
use YetiSearch\Contracts\StorageInterface;
use YetiSearch\Contracts\AnalyzerInterface;
use YetiSearch\Exceptions\IndexException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Indexer implements IndexerInterface
{
    private StorageInterface $storage;
    private AnalyzerInterface $analyzer;
    private LoggerInterface $logger;
    private string $indexName;
    private array $config;
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
            'fields' => [
                'title' => ['boost' => 3.0, 'store' => true],
                'content' => ['boost' => 1.0, 'store' => true],
                'excerpt' => ['boost' => 2.0, 'store' => true],
                'tags' => ['boost' => 2.5, 'store' => true],
                'category' => ['boost' => 2.0, 'store' => true],
                'author' => ['boost' => 1.5, 'store' => true],
                'url' => ['boost' => 1.0, 'store' => true, 'index' => false],
                'route' => ['boost' => 1.0, 'store' => true, 'index' => false]
            ],
            'chunk_size' => 1000,
            'chunk_overlap' => 100
        ], $config);
        
        $this->batchSize = $this->config['batch_size'];
        $this->logger = $logger ?? new NullLogger();
        
        $this->ensureIndexExists();
    }
    
    public function index(array $document): void
    {
        $id = $document['id'] ?? uniqid();
        $this->logger->debug('Indexing document', ['id' => $id]);
        
        try {
            $processedDocument = $this->processDocument($document);
            
            if ($this->config['auto_flush']) {
                $this->storage->insert($this->indexName, $processedDocument);
            } else {
                $this->batchQueue[] = $processedDocument;
                
                if (count($this->batchQueue) >= $this->batchSize) {
                    $this->flush();
                }
            }
            
            $this->logger->info('Document indexed successfully', ['id' => $id]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to index document', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new IndexException("Failed to index document: " . $e->getMessage(), 0, $e);
        }
    }
    
    public function indexBatch(array $documents): void
    {
        $this->logger->debug('Indexing batch', ['count' => count($documents)]);
        
        $processed = [];
        $errors = [];
        
        foreach ($documents as $document) {
            if (!is_array($document)) {
                $errors[] = 'Invalid document type - must be array';
                continue;
            }
            
            $id = $document['id'] ?? uniqid();
            
            try {
                $processed[] = $this->processDocument($document);
            } catch (\Exception $e) {
                $errors[] = [
                    'id' => $id,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        if (!empty($processed)) {
            foreach (array_chunk($processed, $this->batchSize) as $chunk) {
                foreach ($chunk as $doc) {
                    $this->storage->insert($this->indexName, $doc);
                }
            }
        }
        
        if (!empty($errors)) {
            $this->logger->warning('Some documents failed to index', ['errors' => $errors]);
        }
        
        $this->logger->info('Batch indexed', [
            'total' => count($documents),
            'success' => count($processed),
            'failed' => count($errors)
        ]);
    }
    
    public function update(array $document): void
    {
        if (!isset($document['id'])) {
            throw new IndexException('Document must have an id for update');
        }
        $id = $document['id'];
        $this->logger->debug('Updating document', ['id' => $id]);
        
        try {
            $processedDocument = $this->processDocument($document);
            $this->storage->update($this->indexName, $id, $processedDocument);
            
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
        $this->indexBatch($documents);
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
        
        foreach ($this->batchQueue as $document) {
            $this->storage->insert($this->indexName, $document);
        }
        
        $this->batchQueue = [];
    }
    
    private function processDocument(array $document): array
    {
        $content = $document['content'] ?? [];
        $metadata = $document['metadata'] ?? [];
        $language = $document['language'] ?? null;
        $id = $document['id'] ?? uniqid();
        $type = $document['type'] ?? 'default';
        $timestamp = $document['timestamp'] ?? time();
        
        $processedContent = [];
        $searchableText = [];
        
        foreach ($this->config['fields'] as $fieldName => $fieldConfig) {
            if (!isset($content[$fieldName])) {
                continue;
            }
            
            $fieldValue = $content[$fieldName];
            
            if ($fieldConfig['store'] ?? true) {
                $processedContent[$fieldName] = $fieldValue;
            }
            
            if ($fieldConfig['index'] ?? true) {
                if (is_string($fieldValue)) {
                    $analyzed = $this->analyzer->analyze($fieldValue, $language);
                    $boost = $fieldConfig['boost'] ?? 1.0;
                    
                    for ($i = 0; $i < $boost; $i++) {
                        $searchableText = array_merge($searchableText, $analyzed);
                    }
                }
            }
        }
        
        if ($this->shouldChunkContent($processedContent)) {
            $chunks = $this->chunkContent($processedContent);
            $metadata['chunks'] = count($chunks);
            $metadata['chunked'] = true;
            
            foreach ($chunks as $index => $chunk) {
                $chunkId = $id . '#chunk' . $index;
                $chunkDoc = [
                    'id' => $chunkId,
                    'parent_id' => $id,
                    'content' => array_merge($processedContent, ['content' => $chunk]),
                    'metadata' => array_merge($metadata, [
                        'chunk_index' => $index,
                        'is_chunk' => true,
                        'parent_route' => $processedContent['route'] ?? ''
                    ]),
                    'language' => $language,
                    'type' => $type,
                    'timestamp' => $timestamp
                ];
                
                // Include geo data in chunks
                if (isset($document['geo'])) {
                    $chunkDoc['geo'] = $document['geo'];
                }
                if (isset($document['geo_bounds'])) {
                    $chunkDoc['geo_bounds'] = $document['geo_bounds'];
                }
                
                $this->storage->insert($this->indexName, $chunkDoc);
            }
        }
        
        $data = [
            'id' => $id,
            'content' => $processedContent,
            'metadata' => $metadata,
            'language' => $language,
            'type' => $type,
            'timestamp' => $timestamp,
            'indexed_at' => time()
        ];
        
        // Include geo data if present
        if (isset($document['geo'])) {
            $data['geo'] = $document['geo'];
        }
        if (isset($document['geo_bounds'])) {
            $data['geo_bounds'] = $document['geo_bounds'];
        }
        
        return $data;
    }
    
    private function shouldChunkContent(array $content): bool
    {
        $mainContent = $content['content'] ?? '';
        return is_string($mainContent) && strlen($mainContent) > $this->config['chunk_size'];
    }
    
    private function chunkContent(array $content): array
    {
        $mainContent = $content['content'] ?? '';
        if (!is_string($mainContent)) {
            return [];
        }
        
        $chunkSize = $this->config['chunk_size'];
        $overlap = $this->config['chunk_overlap'];
        $chunks = [];
        
        $sentences = preg_split('/(?<=[.!?])\s+/', $mainContent, -1, PREG_SPLIT_NO_EMPTY);
        
        $currentChunk = '';
        $currentSize = 0;
        
        foreach ($sentences as $sentence) {
            $sentenceSize = strlen($sentence);
            
            if ($currentSize + $sentenceSize > $chunkSize && !empty($currentChunk)) {
                $chunks[] = trim($currentChunk);
                
                $overlapText = $this->getOverlapText($currentChunk, $overlap);
                $currentChunk = $overlapText . ' ' . $sentence;
                $currentSize = strlen($currentChunk);
            } else {
                $currentChunk .= ' ' . $sentence;
                $currentSize += $sentenceSize + 1;
            }
        }
        
        if (!empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }
        
        return $chunks;
    }
    
    private function getOverlapText(string $text, int $overlapSize): string
    {
        if (strlen($text) <= $overlapSize) {
            return $text;
        }
        
        $words = explode(' ', $text);
        $overlapWords = [];
        $currentSize = 0;
        
        for ($i = count($words) - 1; $i >= 0 && $currentSize < $overlapSize; $i--) {
            array_unshift($overlapWords, $words[$i]);
            $currentSize += strlen($words[$i]) + 1;
        }
        
        return implode(' ', $overlapWords);
    }
    
    private function ensureIndexExists(): void
    {
        if (!$this->storage->indexExists($this->indexName)) {
            $this->storage->createIndex($this->indexName, $this->config);
        } else {
            // Ensure all required tables exist, including spatial table
            $this->storage->ensureSpatialTableExists($this->indexName);
        }
    }
}