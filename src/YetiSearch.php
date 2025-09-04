<?php

namespace YetiSearch;

use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Analyzers\StandardAnalyzer;
use YetiSearch\Index\Indexer;
use YetiSearch\Search\SearchEngine;
use YetiSearch\Models\SearchQuery;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Geo\GeoBounds;
use YetiSearch\Cache\CacheManager;
use Psr\Log\LoggerInterface;

class YetiSearch
{
    private array $config;
    private ?SqliteStorage $storage = null;
    private ?StandardAnalyzer $analyzer = null;
    private array $indexers = [];
    private array $searchEngines = [];
    private ?LoggerInterface $logger = null;
    private ?CacheManager $cacheManager = null;
    
    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        // Defaults
        $defaults = [
            'storage' => [
                'path' => 'yetisearch.db',
                'external_content' => true
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
                'fuzzy_last_token_only' => false,
                'enable_suggestions' => true,
                'multi_column_fts' => true,  // Default to multi-column FTS for better performance
                'cache_ttl' => 300,
                'trigram_size' => 3,
                'trigram_threshold' => 0.5
            ],
            'cache' => [
                'enabled' => false,  // Disabled by default for backward compatibility
                'ttl' => 300,  // 5 minutes default
                'max_size' => 1000,  // Max cached queries
                'table_name' => '_query_cache'
            ]
        ];
        // Deep-merge user config over defaults so nested arrays keep defaults
        $this->config = self::deepMergeArrays($defaults, $config);
        
        $this->logger = $logger;
    }

    private static function deepMergeArrays(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                // If both sides are arrays, merge recursively for associative keys.
                // For numeric-indexed arrays, override entirely to avoid duplication.
                $isAssoc = static function(array $arr): bool {
                    foreach (array_keys($arr) as $k) { if (!is_int($k)) return true; }
                    return false;
                };
                if ($isAssoc($base[$key]) || $isAssoc($value)) {
                    $base[$key] = self::deepMergeArrays($base[$key], $value);
                } else {
                    $base[$key] = $value; // Replace numeric arrays
                }
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
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
    
    public function getIndex(string $name): ?Indexer
    {
        if (isset($this->indexers[$name])) {
            return $this->indexers[$name];
        }
        
        $storage = $this->getStorage();
        if (!$storage->indexExists($name)) {
            return null;
        }
        
        return $this->createIndex($name);
    }
    
    // Alias for backward compatibility
    public function getIndexer(string $name): ?Indexer
    {
        return $this->getIndex($name);
    }
    
    public function dropIndex(string $name): void
    {
        $storage = $this->getStorage();
        $storage->dropIndex($name);
        
        unset($this->indexers[$name]);
        unset($this->searchEngines[$name]);
        
        // Also invalidate cache for this index
        if ($this->cacheManager) {
            $this->cacheManager->invalidateIndex($name);
        }
    }
    
    public function search(string $name, string $query, array $options = []): array
    {
        $engine = $this->getSearchEngine($name);
        
        // Create a SearchQuery object
        $searchQuery = new SearchQuery($query);
        
        // Apply options
        if (isset($options['limit'])) {
            $searchQuery->limit($options['limit']);
        }
        if (isset($options['offset'])) {
            $searchQuery->offset($options['offset']);
        }
        if (isset($options['fields'])) {
            $searchQuery->fields($options['fields']);
        }
        if (isset($options['filters'])) {
            foreach ($options['filters'] as $filter) {
                $searchQuery->filter($filter['field'], $filter['value'], $filter['operator'] ?? '=');
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
            $searchQuery->highlight($options['highlight']);
        }
        if (isset($options['bypass_cache'])) {
            $searchQuery->setOptions(['bypass_cache' => $options['bypass_cache']]);
        }
        
        // Handle geo filters
        if (isset($options['geoFilters'])) {
            $geo = $options['geoFilters'];
            
            if (isset($geo['near'])) {
                $point = new \YetiSearch\Geo\GeoPoint($geo['near']['point']['lat'], $geo['near']['point']['lng']);
                $radius = $geo['near']['radius'] ?? 1000;
                $units = $geo['near']['units'] ?? 'm';
                
                // Convert to meters if needed
                if ($units === 'km') {
                    $radius *= 1000;
                } elseif ($units === 'mi' || $units === 'mile' || $units === 'miles') {
                    $radius *= 1609.344;
                }
                
                $searchQuery->near($point, $radius);
            }
            
            if (isset($geo['distance_sort'])) {
                $from = new \YetiSearch\Geo\GeoPoint($geo['distance_sort']['from']['lat'], $geo['distance_sort']['from']['lng']);
                $direction = $geo['distance_sort']['direction'] ?? 'asc';
                $searchQuery->sortByDistance($from, $direction);
            }
        }
        
        $results = $engine->search($searchQuery, $options);
        
        // Convert SearchResults to array format
        $documents = [];
        foreach ($results->getResults() as $result) {
            if ($result instanceof \YetiSearch\Models\SearchResult) {
                $documents[] = $result->toArray();
            } else {
                $documents[] = $result;
            }
        }
        
        return [
            'results' => $documents,
            'total' => $results->getTotalCount(),
            'count' => count($documents),  // Add count for backward compatibility
            'search_time' => $results->getSearchTime(),
            'facets' => $results->getFacets(),
            'suggestions' => $results->getSuggestion() ? [$results->getSuggestion()] : []
        ];
    }
    
    public function indexDocument(string $name, string $id, mixed $content, array $options = []): void
    {
        $indexer = $this->getIndex($name) ?? $this->createIndex($name);
        
        // Prepare document for indexer
        $document = is_array($content) ? $content : ['content' => $content];
        if (!isset($document['id'])) {
            $document['id'] = $id;
        }
        
        $indexer->insert($document);
    }
    
    public function indexBatch(string $name, array $documents): void
    {
        $indexer = $this->getIndex($name) ?? $this->createIndex($name);
        $indexer->indexBatch($documents);
    }
    
    public function deleteDocument(string $name, string $id): void
    {
        $storage = $this->getStorage();
        
        // Check if index exists before trying to delete
        if (!$storage->indexExists($name)) {
            // Silently return or you could throw a more specific exception
            return;
        }
        
        $storage->delete($name, $id);
    }
    
    public function updateDocument(string $name, string $id, mixed $content, array $options = []): void
    {
        $this->indexDocument($name, $id, $content, $options);
    }
    
    // Alias for backward compatibility - handles both signatures
    public function update(string $name, mixed $documentOrId, mixed $content = null, array $options = []): void
    {
        // Handle both old signature (id, content) and new signature (document array)
        if (is_array($documentOrId) && $content === null) {
            // New signature: document array
            $document = $documentOrId;
            if (!isset($document['id'])) {
                throw new \InvalidArgumentException('Document must have an id field');
            }
            $this->updateDocument($name, $document['id'], $document, $options);
        } else {
            // Old signature: separate id and content
            $this->updateDocument($name, (string)$documentOrId, $content, $options);
        }
    }
    
    public function getStats(string $name): array
    {
        $storage = $this->getStorage();
        return $storage->getStats($name);
    }
    
    public function optimize(string $name): void
    {
        $storage = $this->getStorage();
        $storage->optimize($name);
    }
    
    public function multiSearch(array $indices, string $query, array $options = []): array
    {
        $storage = $this->getStorage();
        
        // Prepare query array for storage
        $queryArray = array_merge([
            'query' => $query,
            'limit' => 20,
            'offset' => 0
        ], $options);
        
        return $storage->searchMultiple($indices, $queryArray);
    }
    
    public function suggest(string $name, string $term, array $options = []): array
    {
        $engine = $this->getSearchEngine($name);
        return $engine->suggest($term, $options);
    }
    
    public function countDocuments(string $name): int
    {
        $storage = $this->getStorage();
        $stats = $storage->getStats($name);
        return $stats['document_count'] ?? 0;
    }
    
    // Alias for backward compatibility
    public function count(string $name): int
    {
        return $this->countDocuments($name);
    }
    
    // Simplified index method for backward compatibility
    public function index(string $indexName, mixed $documentOrId, mixed $content = null, array $options = []): void
    {
        // Handle both old signature (id, content) and new signature (document array)
        if (is_array($documentOrId) && $content === null) {
            // New signature: document array
            $document = $documentOrId;
            if (!isset($document['id'])) {
                throw new \InvalidArgumentException('Document must have an id field');
            }
            $this->indexDocument($indexName, $document['id'], $document, $options);
        } else {
            // Old signature: separate id and content
            $this->indexDocument($indexName, (string)$documentOrId, $content, $options);
        }
    }
    
    // Delete method for backward compatibility
    public function delete(string $indexName, string $id): void
    {
        $this->deleteDocument($indexName, $id);
    }
    
    // Clear index method
    public function clear(string $indexName): void
    {
        $storage = $this->getStorage();
        $storage->clear($indexName);
    }
    
    // Search multiple indices
    public function searchMultiple(array $indices, string $query, array $options = []): array
    {
        return $this->multiSearch($indices, $query, $options);
    }
    
    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        if (!$this->cacheManager) {
            $this->initializeCacheManager();
        }
        
        return $this->cacheManager ? $this->cacheManager->getStats() : [];
    }
    
    /**
     * Clear all caches
     */
    public function clearCache(): void
    {
        if (!$this->cacheManager) {
            $this->initializeCacheManager();
        }
        
        if ($this->cacheManager) {
            $this->cacheManager->clearAll();
        }
    }
    
    /**
     * Get cache information
     */
    public function getCacheInfo(): array
    {
        if (!$this->cacheManager) {
            $this->initializeCacheManager();
        }
        
        return $this->cacheManager ? $this->cacheManager->getCacheInfo() : [];
    }
    
    /**
     * Warm up cache with popular queries
     */
    public function warmUpCache(string $indexName, array $popularQueries): array
    {
        if (!$this->cacheManager) {
            $this->initializeCacheManager();
        }
        
        return $this->cacheManager ? $this->cacheManager->warmUp($indexName, $popularQueries) : [];
    }
    
    private function initializeCacheManager(): void
    {
        if (!$this->cacheManager && $this->storage) {
            $this->cacheManager = new CacheManager($this->storage);
        }
    }
    
    public function getSearchEngine(string $name): SearchEngine
    {
        if (!isset($this->searchEngines[$name])) {
            $storage = $this->getStorage();
            $analyzer = $this->getAnalyzer();
            
            $this->searchEngines[$name] = new SearchEngine(
                $storage,
                $analyzer,
                $name,
                array_merge($this->config['search'], $this->config['storage']),
                $this->logger
            );
        }
        
        return $this->searchEngines[$name];
    }
    
    public function query(string $index): SearchQuery
    {
        $searchQuery = new SearchQuery();
        $fuzzyOptions = [];
        
        $options = func_get_args();
        array_shift($options); // Remove index
        $options = $options[0] ?? [];
        
        if (isset($options['fields'])) {
            $searchQuery->fields($options['fields']);
        }
        
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
        
        // Fuzzy config: accept boolean or nested array with aliases
        if (array_key_exists('fuzzy', $options)) {
            $f = $options['fuzzy'];
            if (is_bool($f)) {
                $searchQuery->fuzzy($f, $options['fuzziness'] ?? 0.8);
            } elseif (is_array($f)) {
                // Enabled flag
                $enabled = (bool)($f['enabled'] ?? true);
                $searchQuery->fuzzy($enabled, $f['fuzziness'] ?? ($options['fuzziness'] ?? 0.8));
                // Map nested keys to engine config
                $map = [
                    'algorithm' => 'fuzzy_algorithm',
                    'last_token_only' => 'fuzzy_last_token_only',
                    'prefix_last_token' => 'prefix_last_token',
                    'penalty' => 'fuzzy_score_penalty',
                ];
                foreach ($map as $k => $to) {
                    if (array_key_exists($k, $f)) { $fuzzyOptions[$to] = $f[$k]; }
                }
                if (isset($f['jaro_winkler'])) {
                    $jw = $f['jaro_winkler'];
                    if (isset($jw['threshold'])) { $fuzzyOptions['jaro_winkler_threshold'] = $jw['threshold']; }
                    if (isset($jw['prefix_scale'])) { $fuzzyOptions['jaro_winkler_prefix_scale'] = $jw['prefix_scale']; }
                }
                if (isset($f['levenshtein']['threshold'])) {
                    $fuzzyOptions['levenshtein_threshold'] = $f['levenshtein']['threshold'];
                }
                if (isset($f['trigram'])) {
                    $tg = $f['trigram'];
                    if (isset($tg['size'])) { $fuzzyOptions['trigram_size'] = $tg['size']; }
                    if (isset($tg['threshold'])) { $fuzzyOptions['trigram_threshold'] = $tg['threshold']; }
                }
            }
        }
        
        if (isset($options['highlight'])) {
            $searchQuery->highlight($options['highlight']);
        }
        
        if (isset($options['snippet_length'])) {
            $searchQuery->snippetLength($options['snippet_length']);
        }
        
        if (isset($options['field_weights'])) {
            foreach ($options['field_weights'] as $field => $weight) {
                $searchQuery->weight($field, $weight);
            }
        }
        
        // Boost alias
        if (isset($options['boost'])) {
            foreach ($options['boost'] as $field => $weight) {
                $searchQuery->weight($field, $weight);
            }
        }
        
        // Geo filtering
        if (isset($options['geo_filters'])) {
            $geo = $options['geo_filters'];
            
            if (isset($geo['bounds'])) {
                $bounds = new GeoBounds(
                    $geo['bounds']['min_lat'],
                    $geo['bounds']['min_lng'],
                    $geo['bounds']['max_lat'],
                    $geo['bounds']['max_lng']
                );
                $searchQuery->withinBounds(
                    $bounds->getNorth(),
                    $bounds->getSouth(),
                    $bounds->getEast(),
                    $bounds->getWest()
                );
            }
            
            if (isset($geo['near'])) {
                $point = new GeoPoint($geo['near']['lat'], $geo['near']['lng']);
                $radius = $geo['near']['radius'] ?? 1000;
                $searchQuery->nearPoint($point, $radius);
            }
        }
        
        $searchQuery->setOptions($fuzzyOptions);
        
        return $searchQuery;
    }
    
    public function execute(SearchQuery $query, string $index): array
    {
        $engine = $this->getSearchEngine($index);
        $results = $engine->search($query);
        return $results->toArray();
    }
    
    private function getStorage(): SqliteStorage
    {
        if ($this->storage === null) {
            $this->storage = new SqliteStorage();
            $this->storage->connect($this->config['storage']);
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
    
    public function listIndices(): array
    {
        $storage = $this->getStorage();
        return $storage->listIndices();
    }
    
    public function close(): void
    {
        if ($this->storage !== null) {
            $this->storage->disconnect();
            $this->storage = null;
        }
        
        $this->indexers = [];
        $this->searchEngines = [];
        $this->cacheManager = null;
    }
}