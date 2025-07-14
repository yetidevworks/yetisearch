<?php

namespace YetiSearch;

use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Analyzers\StandardAnalyzer;
use YetiSearch\Index\Indexer;
use YetiSearch\Search\SearchEngine;
use YetiSearch\Models\SearchQuery;
use Psr\Log\LoggerInterface;

class YetiSearch
{
    private array $config;
    private ?SqliteStorage $storage = null;
    private ?StandardAnalyzer $analyzer = null;
    private array $indexers = [];
    private array $searchEngines = [];
    private ?LoggerInterface $logger = null;
    
    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge([
            'storage' => [
                'path' => 'yetisearch.db'
            ],
            'analyzer' => [
                'min_word_length' => 2,
                'max_word_length' => 50,
                'remove_numbers' => false,
                'lowercase' => true,
                'strip_html' => true,
                'strip_punctuation' => true,
                'expand_contractions' => true,
                'custom_stop_words' => [],
                'disable_stop_words' => false
            ],
            'indexer' => [
                'batch_size' => 100,
                'auto_flush' => true,
                'chunk_size' => 1000,
                'chunk_overlap' => 100
            ],
            'search' => [
                'min_score' => 0.0,
                'highlight_tag' => '<mark>',
                'highlight_tag_close' => '</mark>',
                'snippet_length' => 150,
                'max_results' => 1000,
                'enable_fuzzy' => true,
                'enable_suggestions' => true,
                'cache_ttl' => 300
            ]
        ], $config);
        
        $this->logger = $logger;
    }
    
    public function createIndex(string $name, array $options = []): Indexer
    {
        $storage = $this->getStorage();
        $analyzer = $this->getAnalyzer();
        
        $indexer = new Indexer(
            $storage,
            $analyzer,
            $name,
            array_merge($this->config['indexer'], $options),
            $this->logger
        );
        
        $this->indexers[$name] = $indexer;
        
        return $indexer;
    }
    
    public function getIndexer(string $name): ?Indexer
    {
        if (!isset($this->indexers[$name])) {
            if ($this->getStorage()->indexExists($name)) {
                return $this->createIndex($name);
            }
            return null;
        }
        
        return $this->indexers[$name];
    }
    
    public function createSearchEngine(string $indexName, array $options = []): SearchEngine
    {
        $storage = $this->getStorage();
        $analyzer = $this->getAnalyzer();
        
        // Get field weights from indexer config if available
        $searchConfig = array_merge($this->config['search'], $options);
        if (!empty($this->config['indexer']['fields'])) {
            $fieldWeights = [];
            foreach ($this->config['indexer']['fields'] as $field => $fieldConfig) {
                if (isset($fieldConfig['boost'])) {
                    $fieldWeights[$field] = $fieldConfig['boost'];
                }
            }
            if (!empty($fieldWeights)) {
                $searchConfig['field_weights'] = $fieldWeights;
            }
        }
        
        $searchEngine = new SearchEngine(
            $storage,
            $analyzer,
            $indexName,
            $searchConfig,
            $this->logger
        );
        
        $this->searchEngines[$indexName] = $searchEngine;
        
        return $searchEngine;
    }
    
    public function getSearchEngine(string $indexName): ?SearchEngine
    {
        if (!isset($this->searchEngines[$indexName])) {
            if ($this->getStorage()->indexExists($indexName)) {
                return $this->createSearchEngine($indexName);
            }
            return null;
        }
        
        return $this->searchEngines[$indexName];
    }
    
    public function index(string $indexName, array $documentData): void
    {
        $indexer = $this->getIndexer($indexName);
        if (!$indexer) {
            $indexer = $this->createIndex($indexName);
        }
        
        $indexer->insert($documentData);
    }
    
    public function indexBatch(string $indexName, array $documents): void
    {
        $indexer = $this->getIndexer($indexName);
        if (!$indexer) {
            $indexer = $this->createIndex($indexName);
        }
        
        $indexer->insert($documents);
    }
    
    public function search(string $indexName, string $query, array $options = []): array
    {
        // Extract fuzzy algorithm options to pass to search engine
        $fuzzyOptions = [];
        $fuzzyConfigKeys = [
            'fuzzy_algorithm',
            'fuzzy_score_penalty',
            'levenshtein_threshold',
            'jaro_winkler_threshold',
            'jaro_winkler_prefix_scale',
            'trigram_threshold',
            'trigram_size',
            'min_term_frequency',
            'max_fuzzy_variations',
            'max_indexed_terms',
            'indexed_terms_cache_ttl'
        ];
        
        foreach ($fuzzyConfigKeys as $key) {
            if (isset($options[$key])) {
                $fuzzyOptions[$key] = $options[$key];
            }
        }
        
        // Get or create search engine with fuzzy options
        $searchEngine = $this->getSearchEngine($indexName);
        if (!$searchEngine) {
            return [
                'results' => [],
                'total' => 0,
                'count' => 0,
                'search_time' => 0
            ];
        }
        
        // Update search engine config with runtime options
        if (!empty($fuzzyOptions)) {
            $searchEngine->updateConfig($fuzzyOptions);
        }
        
        $searchQuery = new SearchQuery($query);
        
        if (isset($options['limit'])) {
            $searchQuery->limit($options['limit']);
        }
        
        if (isset($options['offset'])) {
            $searchQuery->offset($options['offset']);
        }
        
        if (isset($options['filters'])) {
            foreach ($options['filters'] as $filter) {
                $searchQuery->filter(
                    $filter['field'],
                    $filter['value'],
                    $filter['operator'] ?? '='
                );
            }
        }
        
        if (isset($options['sort'])) {
            foreach ($options['sort'] as $field => $direction) {
                $searchQuery->sortBy($field, $direction);
            }
        }
        
        if (isset($options['language'])) {
            $searchQuery->language($options['language']);
        }
        
        if (isset($options['fuzzy'])) {
            $searchQuery->fuzzy($options['fuzzy'], $options['fuzziness'] ?? 0.8);
        }
        
        if (isset($options['highlight'])) {
            $searchQuery->highlight($options['highlight'], $options['highlight_length'] ?? 150);
        }
        
        if (isset($options['fields'])) {
            $searchQuery->inFields($options['fields']);
        }
        
        if (isset($options['boost'])) {
            foreach ($options['boost'] as $field => $weight) {
                $searchQuery->boost($field, $weight);
            }
        }
        
        if (isset($options['facets'])) {
            foreach ($options['facets'] as $field => $facetOptions) {
                $searchQuery->facet($field, $facetOptions);
            }
        }
        
        // Pass original options to search engine for processing
        $engineOptions = [
            'unique_by_route' => $options['unique_by_route'] ?? false
        ];
        
        $results = $searchEngine->search($searchQuery, $engineOptions);
        
        return $results->toArray();
    }
    
    public function count(string $indexName, string $query, array $options = []): int
    {
        $searchEngine = $this->getSearchEngine($indexName);
        if (!$searchEngine) {
            return 0;
        }
        
        $searchQuery = new SearchQuery($query);
        
        if (isset($options['filters'])) {
            foreach ($options['filters'] as $filter) {
                $searchQuery->filter(
                    $filter['field'],
                    $filter['value'],
                    $filter['operator'] ?? '='
                );
            }
        }
        
        if (isset($options['language'])) {
            $searchQuery->language($options['language']);
        }
        
        if (isset($options['fields'])) {
            $searchQuery->inFields($options['fields']);
        }
        
        return $searchEngine->count($searchQuery);
    }
    
    public function searchMultiple(array $indexNames, string $query, array $options = []): array
    {
        $storage = $this->getStorage();
        
        // Support pattern matching for index names
        if (count($indexNames) === 1 && strpos($indexNames[0], '*') !== false) {
            $pattern = $indexNames[0];
            $allIndices = $this->listIndices();
            $indexNames = [];
            
            // Convert pattern to regex
            $regex = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/';
            
            foreach ($allIndices as $indexInfo) {
                if (preg_match($regex, $indexInfo['name'])) {
                    $indexNames[] = $indexInfo['name'];
                }
            }
        }
        
        // Build query array for storage layer
        $searchQuery = [
            'query' => $query,
            'filters' => $options['filters'] ?? [],
            'limit' => $options['limit'] ?? 20,
            'offset' => $options['offset'] ?? 0,
            'language' => $options['language'] ?? null,
            'fuzzy' => $options['fuzzy'] ?? false,
            'fields' => $options['fields'] ?? null,
            'highlight' => $options['highlight'] ?? false,
            'facets' => $options['facets'] ?? [],
            'aggregations' => $options['aggregations'] ?? []
        ];
        
        // Search across multiple indices
        $results = $storage->searchMultiple($indexNames, $searchQuery);
        
        // Process results similar to single search
        $processedResults = [
            'results' => [],
            'total' => $results['total'],
            'search_time' => $results['search_time'],
            'indices_searched' => $results['indices_searched'],
            'facets' => [],
            'aggregations' => []
        ];
        
        // Process each result
        foreach ($results['results'] as $result) {
            $processedResults['results'][] = [
                'id' => $result['id'],
                'content' => $result['document'] ?? [], // Use 'document' key from search results
                'metadata' => $result['metadata'] ?? [],
                'score' => $result['score'] ?? $result['rank'] ?? $result['_score'] ?? 0,
                'language' => $result['language'] ?? null,
                'type' => $result['type'] ?? null,
                'timestamp' => $result['timestamp'] ?? null,
                '_index' => $result['_index']
            ];
        }
        
        return $processedResults;
    }
    
    public function delete(string $indexName, string $documentId): void
    {
        $indexer = $this->getIndexer($indexName);
        if ($indexer) {
            $indexer->delete($documentId);
        }
    }
    
    public function update(string $indexName, array $documentData): void
    {
        $indexer = $this->getIndexer($indexName);
        if ($indexer) {
            $indexer->update($documentData);
        }
    }
    
    public function clear(string $indexName): void
    {
        $indexer = $this->getIndexer($indexName);
        if ($indexer) {
            $indexer->clear();
        }
    }
    
    public function optimize(string $indexName): void
    {
        $indexer = $this->getIndexer($indexName);
        if ($indexer) {
            $indexer->optimize();
        }
    }
    
    public function getStats(string $indexName): array
    {
        $indexer = $this->getIndexer($indexName);
        if ($indexer) {
            return $indexer->getStats();
        }
        
        return [];
    }
    
    public function suggest(string $indexName, string $term, array $options = []): array
    {
        $searchEngine = $this->getSearchEngine($indexName);
        if ($searchEngine) {
            return $searchEngine->suggest($term, $options);
        }
        
        return [];
    }
    
    public function listIndices(): array
    {
        $storage = $this->getStorage();
        return $storage->listIndices();
    }
    
    private function getStorage(): SqliteStorage
    {
        if ($this->storage === null) {
            $this->storage = new SqliteStorage();
            // Pass both storage and search config to storage layer
            $storageConfig = array_merge(
                $this->config['storage'],
                ['search' => $this->config['search']]
            );
            $this->storage->connect($storageConfig);
        }
        
        return $this->storage;
    }
    
    private function getAnalyzer(): StandardAnalyzer
    {
        if ($this->analyzer === null) {
            $this->analyzer = new StandardAnalyzer($this->config['analyzer']);
        }
        
        return $this->analyzer;
    }
    
    public function getAnalyzerInstance(): StandardAnalyzer
    {
        return $this->getAnalyzer();
    }
    
    public function __destruct()
    {
        foreach ($this->indexers as $indexer) {
            $indexer->flush();
        }
        
        if ($this->storage !== null) {
            $this->storage->disconnect();
        }
    }
}