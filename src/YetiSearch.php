<?php

namespace YetiSearch;

use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Analyzers\StandardAnalyzer;
use YetiSearch\Index\Indexer;
use YetiSearch\Search\SearchEngine;
use YetiSearch\Stemmer\StemmerFactory;
use YetiSearch\Utils\Fts5Escaper;
use YetiSearch\Utils\StemQuery;
use YetiSearch\Models\SearchQuery;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Geo\GeoBounds;
use YetiSearch\Geo\GeoUtils;
use YetiSearch\Cache\CacheManager;
use YetiSearch\Contracts\ProvidesStemming;
use YetiSearch\Contracts\EmbeddingProviderInterface;
use YetiSearch\Exceptions\InvalidArgumentException;
use YetiSearch\Exceptions\YetiSearchException;
use YetiSearch\Semantic\NoiseCalibration;
use YetiSearch\Semantic\SemanticSearch;
use Psr\Log\LoggerInterface;

class YetiSearch
{
    /**
     * The nested 'fuzzy' search options and the search engine settings they set
     */
    private const FUZZY_SETTINGS = [
        'algorithm' => 'fuzzy_algorithm',
        'last_token_only' => 'fuzzy_last_token_only',
        'prefix_last_token' => 'prefix_last_token',
        'penalty' => 'fuzzy_score_penalty',
        'jaro_winkler.threshold' => 'jaro_winkler_threshold',
        'jaro_winkler.prefix_scale' => 'jaro_winkler_prefix_scale',
        'levenshtein.threshold' => 'levenshtein_threshold',
        'trigram.size' => 'trigram_size',
        'trigram.threshold' => 'trigram_threshold',
    ];

    private array $config;
    private ?SqliteStorage $storage = null;
    private ?StandardAnalyzer $analyzer = null;
    private array $indexers = [];
    private array $searchEngines = [];
    private ?LoggerInterface $logger = null;
    private ?CacheManager $cacheManager = null;
    private ?EmbeddingProviderInterface $embeddingProvider = null;

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
                'stop_words' => [],
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
                'trigram_threshold' => 0.5,
                'stem_weight' => 0.5  // Weight of a match on a word's stem, on an index created with stemming
            ],
            'cache' => [
                'enabled' => false,  // Disabled by default for backward compatibility
                'ttl' => 300,  // 5 minutes default
                'max_size' => 1000,  // Max cached queries
                'table_name' => '_query_cache'
            ],
            // Semantic (hybrid) search. Off until a provider is set, here as
            // 'provider' or later with setEmbeddingProvider(). The other keys
            // are documented in SemanticSearch::defaults().
            'semantic' => array_merge(['provider' => null], SemanticSearch::defaults()),
        ];
        // Deep-merge user config over defaults so nested arrays keep defaults
        $this->config = self::deepMergeArrays($defaults, $config);

        $this->logger = $logger;

        $provider = $this->config['semantic']['provider'] ?? null;
        if ($provider !== null) {
            if (!$provider instanceof EmbeddingProviderInterface) {
                throw new \InvalidArgumentException(
                    "Config 'semantic.provider' must implement " . EmbeddingProviderInterface::class
                );
            }
            $this->embeddingProvider = $provider;
        }
    }

    /**
     * Turn on semantic search with this provider, or off with null. Options
     * given here override the 'semantic' config.
     */
    public function setEmbeddingProvider(?EmbeddingProviderInterface $provider, array $options = []): self
    {
        $this->embeddingProvider = $provider;
        if (!empty($options)) {
            $this->config['semantic'] = array_merge($this->config['semantic'], $options);
        }

        foreach ($this->searchEngines as $engine) {
            $engine->setSemanticSearch($this->createSemanticSearch());
        }

        return $this;
    }

    public function getEmbeddingProvider(): ?EmbeddingProviderInterface
    {
        return $this->embeddingProvider;
    }

    public function isSemanticEnabled(): bool
    {
        return $this->embeddingProvider !== null;
    }

    /**
     * Embed up to $limit documents of an index that have no vector yet, or
     * whose text changed since they got one. Call it after indexing, and
     * again while 'pending' is above zero. A provider error stops the run
     * and is returned in 'error'; what was embedded before it is kept.
     *
     * With semantic.calibration 'auto', the run that leaves nothing pending
     * also measures the index's noise gate again when the stored measurement
     * no longer describes it ('calibrated' is then true). See calibrate().
     *
     * @return array{embedded:int, pending:int, total:int, pruned:int, error:?string,
     *                calibrated:bool, calibration_error:?string}
     */
    public function embedPending(string $index, int $limit = 200): array
    {
        $result = $this->requireSemantic()->embedPending($index, $limit);
        if ($result['embedded'] > 0 || $result['pruned'] > 0 || $result['calibrated']) {
            $this->clearEngineResults($index);
        }

        return $result;
    }

    /**
     * Measure an index's semantic noise gate now and store it: a fixed set of
     * nonsense probes is embedded as queries are, and min_margin is set just
     * above the margins they reach (the mean plus two standard deviations).
     * The probes' vectors are cached per model and query frame, so only the
     * first calibration with a model calls the provider.
     *
     * Null, with nothing stored, when fewer than ten of the gate's documents
     * hold a vector. Throws when the provider fails.
     *
     * @param array $filters The documents to measure over; empty for the
     *        configured semantic.gate_filters. The gate only uses a
     *        calibration measured with the filters it is configured with.
     */
    public function calibrate(string $index, array $filters = []): ?NoiseCalibration
    {
        $calibration = $this->requireSemantic()->calibrate($index, empty($filters) ? null : $filters);
        $this->clearEngineResults($index);

        return $calibration;
    }

    /**
     * The noise calibration stored for an index, whatever model it was
     * measured with, or null. semanticGate() says whether the gate uses it.
     * Needs no provider.
     */
    public function calibration(string $index): ?NoiseCalibration
    {
        $store = $this->config['semantic']['calibration_store'] ?? null;
        $store = $store instanceof \YetiSearch\Semantic\CalibrationStore ? $store : $this->getStorage();
        $data = $store->loadCalibration($index);

        return is_array($data) ? NoiseCalibration::fromArray($data) : null;
    }

    /**
     * The min_margin the noise gate uses on an index and where it comes from
     * ('calibrated' or 'configured'), with the configured value and the
     * stored calibration.
     *
     * @return array{min_margin:float, source:string, configured:float, calibration:?NoiseCalibration}
     */
    public function semanticGate(string $index): array
    {
        return $this->requireSemantic()->gate($index);
    }

    /**
     * How much of an index is embedded with the current provider's model.
     *
     * @return array{model:string, total:int, embedded:int, pending:int}
     */
    public function embeddingStats(string $index): array
    {
        return $this->requireSemantic()->stats($index);
    }

    /**
     * Forget every stored vector of an index, so the next embedPending()
     * starts over. Needs no provider.
     */
    public function clearEmbeddings(string $index): void
    {
        // Drops the index's calibration with the vectors it measured.
        $this->getStorage()->clearVectors($index);
        $store = $this->config['semantic']['calibration_store'] ?? null;
        if ($store instanceof \YetiSearch\Semantic\CalibrationStore) {
            $store->deleteCalibration($index);
        }
        $this->clearEngineResults($index);
    }

    private function clearEngineResults(string $index): void
    {
        if (isset($this->searchEngines[$index])) {
            $this->searchEngines[$index]->clearResultCache();
        }
    }

    private function createSemanticSearch(): ?SemanticSearch
    {
        if ($this->embeddingProvider === null) {
            return null;
        }

        $options = $this->config['semantic'];
        unset($options['provider']);

        $semantic = new SemanticSearch($this->embeddingProvider, $this->getStorage(), $options, $this->logger);
        // Calibration leaves out probes that keywords find: they are not noise here.
        $semantic->setKeywordMatcher(function (string $index, string $text): bool {
            $found = $this->search($index, $text, [
                'limit' => 1,
                'semantic' => false,
                'fuzzy' => false,
                'bypass_cache' => true,
            ]);

            return !empty($found['results']);
        });

        return $semantic;
    }

    private function requireSemantic(): SemanticSearch
    {
        $semantic = $this->createSemanticSearch();
        if ($semantic === null) {
            throw new YetiSearchException('Semantic search needs an embedding provider; call setEmbeddingProvider() first');
        }

        return $semantic;
    }

    private static function deepMergeArrays(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                // If both sides are arrays, merge recursively for associative keys.
                // For numeric-indexed arrays, override entirely to avoid duplication.
                $isAssoc = static function (array $arr): bool {
                    foreach (array_keys($arr) as $k) {
                        if (!is_int($k)) {
                            return true;
                        }
                    }
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

        // A search engine made before ranks by the boosts of this indexer's fields from now on
        if (isset($this->searchEngines[$name]) && !array_key_exists('field_weights', $this->config['search'])) {
            $this->searchEngines[$name]->updateConfig(['field_weights' => $indexer->getFieldBoosts()]);
        }

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
        $store = $this->config['semantic']['calibration_store'] ?? null;
        if ($store instanceof \YetiSearch\Semantic\CalibrationStore) {
            $store->deleteCalibration($name);
        }

        unset($this->indexers[$name]);
        unset($this->searchEngines[$name]);

        // The storage's query cache holds results of the dropped index too
        $this->cacheManager()->invalidateIndex($name);
    }

    public function search(string $name, string $query, array $options = []): array
    {
        $engine = $this->getSearchEngine($name);

        $searchQuery = new SearchQuery($query);
        $settings = $this->applySearchOptions($searchQuery, $options);
        if (isset($options['geoFilters']) && is_array($options['geoFilters'])) {
            $this->applyGeoFilters($searchQuery, $options['geoFilters']);
        }

        $results = $engine->search($searchQuery, array_merge($options, $settings));

        // Convert SearchResults to array format
        $documents = [];
        foreach ($results->getResults() as $result) {
            if ($result instanceof \YetiSearch\Models\SearchResult) {
                $documents[] = $result->toArray();
            } else {
                $documents[] = $result;
            }
        }

        $suggestion = $results->getSuggestion();
        return [
            'results' => $documents,
            'total' => $results->getTotalCount(),
            'count' => count($documents),  // Add count for backward compatibility
            'search_time' => $results->getSearchTime(),
            'facets' => $results->getFacets(),
            'aggregations' => $results->getAggregations(),
            'suggestion' => $suggestion,  // Singular for "did you mean?" feature
            'suggestions' => $suggestion ? [$suggestion] : [],  // Plural for compatibility
            // True when meaning took part in the ranking; false for keyword
            // search, including a fallback after the provider failed.
            'semantic' => (bool)($results->getMetadata()['semantic'] ?? false),
        ];
    }

    /**
     * Puts the options search() and query() share on a query, and returns the
     * search engine settings among them: the nested 'fuzzy' options under their
     * setting names, and the field weights ('boost' is another name for
     * 'field_weights'). The highlight length is 'snippet_length' or
     * 'highlight_length', else the search.snippet_length config.
     */
    private function applySearchOptions(SearchQuery $searchQuery, array $options): array
    {
        $settings = [];

        if (isset($options['limit'])) {
            $searchQuery->limit((int)$options['limit']);
        }
        if (isset($options['offset'])) {
            $searchQuery->offset((int)$options['offset']);
        }
        if (isset($options['fields'])) {
            $searchQuery->fields($options['fields']);
        }
        foreach ($options['filters'] ?? [] as $filter) {
            $searchQuery->filter($filter['field'], $filter['value'], $filter['operator'] ?? '=');
        }
        foreach ($options['sort'] ?? [] as $field => $direction) {
            $searchQuery->sortBy($field, $direction);
        }
        if (isset($options['language'])) {
            $searchQuery->language($options['language']);
        }

        // Fuzzy: a boolean, or an array with 'enabled', 'fuzziness' and engine settings
        $fuzzy = $options['fuzzy'] ?? null;
        if (is_array($fuzzy)) {
            $fuzziness = (float)($fuzzy['fuzziness'] ?? $options['fuzziness'] ?? 0.8);
            $searchQuery->fuzzy((bool)($fuzzy['enabled'] ?? true), $fuzziness);
            foreach (self::FUZZY_SETTINGS as $path => $setting) {
                $value = $fuzzy;
                foreach (explode('.', $path) as $key) {
                    $value = is_array($value) ? ($value[$key] ?? null) : null;
                }
                if ($value !== null) {
                    $settings[$setting] = $value;
                }
            }
        } elseif ($fuzzy !== null) {
            $searchQuery->fuzzy((bool)$fuzzy, (float)($options['fuzziness'] ?? 0.8));
        }

        if (isset($options['highlight'])) {
            $searchQuery->highlight((bool)$options['highlight']);
        }
        $length = $options['snippet_length']
            ?? $options['highlight_length']
            ?? $this->config['search']['snippet_length']
            ?? null;
        if ($length !== null) {
            $searchQuery->snippetLength((int)$length);
        }

        $weights = $options['field_weights'] ?? $options['boost'] ?? null;
        if (is_array($weights) && !empty($weights)) {
            foreach ($weights as $field => $weight) {
                $searchQuery->weight($field, (float)$weight);
            }
            $settings['field_weights'] = $weights;
        }

        if (isset($options['bypass_cache'])) {
            $searchQuery->setOptions(['bypass_cache' => $options['bypass_cache']]);
        }

        foreach (is_array($options['facets'] ?? null) ? $options['facets'] : [] as $facet => $facetOptions) {
            // ['brand', 'category'] asks for those facets with their defaults
            if (is_int($facet) && is_string($facetOptions)) {
                $searchQuery->facet($facetOptions);
            } elseif (is_string($facet)) {
                $searchQuery->facet($facet, is_array($facetOptions) ? $facetOptions : []);
            }
        }

        // ['avg_price' => ['type' => 'avg', 'field' => 'price']]
        foreach (is_array($options['aggregations'] ?? null) ? $options['aggregations'] : [] as $name => $aggregation) {
            if (is_string($name) && is_array($aggregation) && isset($aggregation['type'])) {
                $searchQuery->aggregate($name, (string)$aggregation['type'], $aggregation);
            }
        }

        return $settings;
    }

    /**
     * Puts the 'geoFilters' search option on a query. Points and bounds are
     * GeoPoint and GeoBounds objects or arrays, so the geo filters of a
     * SearchQuery can be passed as they are.
     */
    private function applyGeoFilters(SearchQuery $searchQuery, array $geo): void
    {
        // Units go to the query as they are, and the storage converts once. A
        // per-near 'units' wins over the filter's top-level 'units'.
        $units = $geo['near']['units'] ?? ($geo['units'] ?? null);

        if (isset($geo['near'])) {
            $searchQuery->near($this->geoPoint($geo['near']['point'] ?? null), (float)($geo['near']['radius'] ?? 1000));
        }

        if (isset($geo['within'])) {
            // ['bounds' => ...] or the bounds themselves
            $bounds = is_array($geo['within']) ? ($geo['within']['bounds'] ?? $geo['within']) : $geo['within'];
            $searchQuery->within($bounds instanceof GeoBounds ? $bounds : GeoBounds::fromArray($bounds));
        }

        // k-nearest takes its point from nearest['from'] or else from distance_sort
        $nearestFrom = is_array($geo['nearest'] ?? null) ? ($geo['nearest']['from'] ?? null) : null;
        $sortFrom = $geo['distance_sort']['from'] ?? $nearestFrom;
        if ($sortFrom !== null) {
            $searchQuery->sortByDistance($this->geoPoint($sortFrom), $geo['distance_sort']['direction'] ?? 'asc');
        }

        if (isset($geo['nearest'])) {
            $k = is_array($geo['nearest']) ? ($geo['nearest']['k'] ?? 10) : $geo['nearest'];
            $searchQuery->nearest((int)$k);
        }

        if (isset($geo['max_distance'])) {
            $searchQuery->maxDistance((float)$geo['max_distance']);
        }

        if ($units !== null) {
            $searchQuery->geoUnits((string)$units);
        }
    }

    /**
     * @param GeoPoint|array|string|null $point
     */
    private function geoPoint($point): GeoPoint
    {
        $geoPoint = GeoUtils::parsePoint($point);
        if ($geoPoint === null) {
            throw new InvalidArgumentException('A geo point needs a GeoPoint or an array with lat and lng');
        }

        return $geoPoint;
    }

    public function indexDocument(string $name, string $id, $content, array $options = []): void
    {
        $indexer = $this->getIndex($name) ?? $this->createIndex($name);

        // A string is the text of the document's 'content' field
        $document = is_array($content) ? $content : ['content' => ['content' => (string)$content]];
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

    public function updateDocument(string $name, string $id, $content, array $options = []): void
    {
        $this->indexDocument($name, $id, $content, $options);
    }

    // Alias for backward compatibility - handles both signatures
    public function update(string $name, $documentOrId, $content = null, array $options = []): void
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

        // Unlike search(), this path does not go through SearchEngine, so the
        // raw query text would reach FTS5 MATCH unescaped. Process it here the
        // same way the engine would: tokenize with the analyzer, drop stop
        // words, escape each term.
        $analyzer = $this->getAnalyzer();
        $language = $options['language'] ?? null;
        $tokens = $analyzer->tokenize($query);
        $tokens = $analyzer->removeStopWords($tokens, $language);
        $escapedTokens = Fts5Escaper::escapeTokens($tokens);

        // A termless but non-blank query ("...", ":") must not fall through to
        // storage's match-all branch. An explicitly blank query is left alone:
        // that is how a caller says "no text query" for geo-only or
        // facet-only searches.
        if (trim($query) !== '' && empty($escapedTokens)) {
            if ($this->logger !== null) {
                $this->logger->debug('Query holds no searchable term', ['query' => $query]);
            }

            return [
                'results' => [],
                'total' => 0,
                'search_time' => 0.0,
                'indices_searched' => [],
            ];
        }

        // Prepare query array for storage
        $queryArray = array_merge([
            'limit' => 20,
            'offset' => 0
        ], $options);
        // The dedicated $query argument is authoritative. Do not allow an
        // options entry to bypass analysis and FTS5 escaping. Any of the words
        // matches, as in search(): the documents with all of them, as a phrase
        // or near each other, rank first.
        $components = $escapedTokens;
        if (count($escapedTokens) > 1) {
            $phrase = '"' . implode(' ', Fts5Escaper::escapeTokens($tokens, true)) . '"';
            array_unshift($components, $phrase, 'NEAR(' . implode(' ', $escapedTokens) . ', 10)');
        }
        $queryArray['query'] = implode(' OR ', array_unique($components));
        // Likewise the stem query: it is built here, never taken from the options
        unset($queryArray['stem_query']);

        // Indexes that stem also match the words by their stems, in the language of
        // the query, else English; the others use the query as typed. It is only made
        // when one of the indexes stems, so a plain index never runs a stemmer.
        $stemQuery = null;
        $stemLanguage = $language ?: 'english';
        if ($escapedTokens && StemmerFactory::isSupported($stemLanguage) && $this->anyIndexStems($storage, $indices)) {
            $stemQuery = StemQuery::alternatives($analyzer, $queryArray['query'], $tokens, $stemLanguage)['query'];
        }
        if ($stemQuery !== null) {
            $queryArray['stem_query'] = $stemQuery;
            $queryArray['stem_weight'] = (float)($options['stem_weight'] ?? $this->config['search']['stem_weight'] ?? 0.5);
        }

        return $storage->searchMultiple($indices, $queryArray);
    }

    /**
     * Whether any of the indexes keeps stems. A storage that cannot say has none that do.
     *
     * @param string[] $indices
     */
    private function anyIndexStems($storage, array $indices): bool
    {
        if (!$storage instanceof ProvidesStemming) {
            return false;
        }
        foreach ($indices as $index) {
            if ($storage->stemmingFor($index) !== null) {
                return true;
            }
        }

        return false;
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
    public function index(string $indexName, $documentOrId, $content = null, array $options = []): void
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

    /**
     * Delete all documents whose ID starts with the given prefix.
     * Useful for cleaning up chunks when a parent document is updated.
     *
     * @param string $indexName The index name
     * @param string $prefix The ID prefix to match (e.g., "page123#chunk" to delete all chunks)
     * @param bool $rebuildFts Kept for compatibility and no longer needed: the FTS entries of the
     *                         deleted documents are always removed one by one, so the vocabulary is
     *                         in step whether it is true or false.
     * @return int Number of documents deleted
     */
    public function deleteByIdPrefix(string $indexName, string $prefix, bool $rebuildFts = true): int
    {
        $storage = $this->getStorage();

        // Check if index exists before trying to delete
        if (!$storage->indexExists($indexName)) {
            return 0;
        }

        return $storage->deleteByIdPrefix($indexName, $prefix, $rebuildFts);
    }

    /**
     * Rebuild the FTS index for the given index.
     * Useful after batch updates to external content FTS tables to ensure
     * the FTS vocabulary is in sync with the content table.
     *
     * An index that stems has its stems made again, with the stemmers
     * registered now. The options switch the index: 'stemming' turns stemming
     * on or off for an existing index without reading its documents again, and
     * 'language' sets the language it stems in. Without them the index keeps
     * the settings it has.
     *
     * @param string $indexName The index name
     * @param array{stemming?: bool, language?: ?string} $options
     */
    public function rebuildFts(string $indexName, array $options = []): void
    {
        $storage = $this->getStorage();

        if (!$storage->indexExists($indexName)) {
            return;
        }

        $storage->rebuildFts($indexName, $options);
        $this->clearEngineResults($indexName);
    }

    /**
     * The language an index stems in: its canonical name ('english', 'french'; a language with no
     * stemmer is named as it was given, in lower case), or null when the index does not stem or does
     * not exist. An index that stems with no language of its own stems in English.
     */
    public function stemmingFor(string $index): ?string
    {
        $storage = $this->getStorage();
        if (!$storage instanceof ProvidesStemming) {
            return null;
        }

        return $storage->stemmingFor($index);
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
        return $this->cacheManager()->getStats();
    }

    /**
     * Clear all caches: the storage's query cache and the results each
     * search engine holds in memory.
     */
    public function clearCache(): void
    {
        $this->cacheManager()->clearAll();

        foreach ($this->searchEngines as $engine) {
            $engine->clearResultCache();
        }
    }

    /**
     * Get cache information
     */
    public function getCacheInfo(): array
    {
        return $this->cacheManager()->getCacheInfo();
    }

    /**
     * Warm up the caches by running searches ahead of time. Each entry is a
     * query string, or an array of search() options with the query under 'query'.
     */
    public function warmUpCache(string $indexName, array $popularQueries): array
    {
        $start = microtime(true);

        foreach ($popularQueries as $entry) {
            $options = is_array($entry) ? $entry : ['query' => $entry];
            $query = (string)($options['query'] ?? '');
            unset($options['query']);
            $this->search($indexName, $query, $options);
        }

        return [
            'queries_warmed' => count($popularQueries),
            'time_taken' => microtime(true) - $start,
        ];
    }

    /**
     * Generate "did you mean" suggestions for a query
     */
    public function generateSuggestions(string $name, string $query, int $maxSuggestions = 3): array
    {
        $engine = $this->getSearchEngine($name);
        return $engine->generateSuggestions($query, $maxSuggestions);
    }

    /**
     * The cache manager, given the storage's query cache when it has one
     */
    private function cacheManager(): CacheManager
    {
        if ($this->cacheManager === null) {
            $storage = $this->getStorage();
            $this->cacheManager = new CacheManager($storage);
            $queryCache = $storage->getQueryCache();
            if ($queryCache !== null) {
                $this->cacheManager->setQueryCache($queryCache);
            }
        }

        return $this->cacheManager;
    }

    public function getSearchEngine(string $name): SearchEngine
    {
        if (!isset($this->searchEngines[$name])) {
            $storage = $this->getStorage();
            $analyzer = $this->getAnalyzer();

            // The fields are weighed by the boosts they are indexed with, unless the search config
            // gives its own field_weights (an empty list ranks by text relevance alone)
            $config = array_merge($this->config['search'], $this->config['storage']);
            if (!array_key_exists('field_weights', $this->config['search'])) {
                $config['field_weights'] = isset($this->indexers[$name])
                    ? $this->indexers[$name]->getFieldBoosts()
                    : Indexer::boosts($this->config['indexer']['fields'] ?? null);
            }
            $this->searchEngines[$name] = new SearchEngine($storage, $analyzer, $name, $config, $this->logger);
            $this->searchEngines[$name]->setSemanticSearch($this->createSemanticSearch());
        }

        return $this->searchEngines[$name];
    }

    /**
     * A SearchQuery for execute(), built from the options given as a second
     * argument: those search() takes except geoFilters, with 'fuzzy' also as an
     * array of engine settings, and 'geo_filters' with 'bounds' (min_lat,
     * min_lng, max_lat, max_lng) and 'near' (lat, lng, radius).
     */
    public function query(string $index): SearchQuery
    {
        // The options stay out of the signature, so a class that extends this
        // one and declares query(string $index) still matches it
        $options = func_get_args()[1] ?? [];

        $searchQuery = new SearchQuery();
        // The engine settings among the options travel on the query to execute()
        $searchQuery->setOptions($this->applySearchOptions($searchQuery, $options));

        $geo = $options['geo_filters'] ?? [];
        if (isset($geo['bounds'])) {
            $bounds = $geo['bounds'];
            $searchQuery->withinBounds($bounds['max_lat'], $bounds['min_lat'], $bounds['max_lng'], $bounds['min_lng']);
        }
        if (isset($geo['near'])) {
            $point = new GeoPoint($geo['near']['lat'], $geo['near']['lng']);
            $searchQuery->nearPoint($point, $geo['near']['radius'] ?? 1000);
        }

        return $searchQuery;
    }

    /**
     * Run a SearchQuery, with the engine settings it carries in its options
     */
    public function execute(SearchQuery $query, string $index): array
    {
        return $this->getSearchEngine($index)->search($query, $query->getOptions())->toArray();
    }

    private function getStorage(): SqliteStorage
    {
        if ($this->storage === null) {
            $this->storage = new SqliteStorage($this->logger);
            $storageConfig = $this->config['storage'];
            if (!isset($storageConfig['cache']) && isset($this->config['cache']) && is_array($this->config['cache'])) {
                $storageConfig['cache'] = $this->config['cache'];
            }
            $this->storage->connect($storageConfig);
            // The storage stems an index's text with the analyzer the searches use
            $this->storage->setAnalyzer($this->getAnalyzer());
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
