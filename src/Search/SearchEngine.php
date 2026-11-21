<?php

namespace YetiSearch\Search;

use YetiSearch\Contracts\SearchEngineInterface;
use YetiSearch\Contracts\StorageInterface;
use YetiSearch\Contracts\ProvidesFieldValues;
use YetiSearch\Contracts\ProvidesStemming;
use YetiSearch\Contracts\ProvidesTermLookup;
use YetiSearch\Contracts\TracksIndexChanges;
use YetiSearch\Contracts\AnalyzerInterface;
use YetiSearch\Models\SearchQuery;
use YetiSearch\Models\SearchResults;
use YetiSearch\Models\SearchResult;
use YetiSearch\Exceptions\SearchException;
use YetiSearch\Utils\Levenshtein;
use YetiSearch\Utils\JaroWinkler;
use YetiSearch\Utils\Trigram;
use YetiSearch\Utils\PhoneticMatcher;
use YetiSearch\Utils\KeyboardProximity;
use YetiSearch\Stemmer\StemmerFactory;
use YetiSearch\Utils\Fts5Escaper;
use YetiSearch\Utils\StemQuery;
use YetiSearch\Semantic\SemanticSearch;
use YetiSearch\Helpers\UTF8Helper as UTF8;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class SearchEngine implements SearchEngineInterface
{
    /** How much each algorithm counts in the consensus of a typo correction */
    /** A rare word in the index is also searched as a word one slip away in this many times as many documents */
    private const KEEP_TYPED_RATIO = 20;

    /** A rare word in the index is also searched as two words run together that stand side by side this many times as often */
    private const SPLIT_RATIO = 5;

    /** What the frequency of a completion counts for against a word one slip away, for a word cut short */
    private const PREFIX_WEIGHT = 0.5;

    private const CONSENSUS_WEIGHTS = [
        'trigram' => 0.25,      // Good for overall similarity
        'levenshtein' => 0.20,  // Good for edit distance
        'jaro_winkler' => 0.25, // Good for short strings and prefixes
        'phonetic' => 0.15,     // Good for sound-alike typos
        'keyboard' => 0.15,     // Good for fat-finger errors
    ];

    private StorageInterface $storage;
    private AnalyzerInterface $analyzer;
    private LoggerInterface $logger;
    private string $indexName;
    private array $config;
    private array $cache = [];
    /** Whether the search being run bypasses the storage's query cache because it is not cached anywhere */
    private bool $bypassStorageCache = false;
    private array $fuzzyTermMap = [];
    /** @var array|null The indexed terms fuzzy matching uses, see lexicon() */
    private ?array $lexicon = null;
    private int $lexiconTime = 0;
    /** @var array The index change token and term limits the lexicon was read with */
    private array $lexiconKey = [];
    /** @var array<string, int> Documents matching an FTS expression (see matchCount()) */
    private array $matchCounts = [];
    /** The language of the stop words of the query being corrected */
    private ?string $correctionLanguage = null;
    /** @var array<string, array<string, int>> The storage's terms by their first two characters (see termsSharingStart()) */
    private array $termLookup = [];
    private ?array $synonymsCache = null;
    /** @var mixed The synonyms setting synonymsCache was read from */
    private $synonymsSource = null;
    private ?SemanticSearch $semantic = null;
    /** The MATCH expression that also finds words by their stems, for the query last processed; null when the index does not stem */
    private ?string $stemQuery = null;
    /** @var string[] The stems of the query's terms, for highlighting */
    private array $queryStems = [];
    /** The language the index stems in, when it stems */
    private ?string $indexStemLanguage = null;
    /** @var array<string, array<string, string>> Stems of document words met while highlighting, by language */
    private array $wordStems = [];

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
            'min_score' => 0.1,
            'highlight_tag' => '<mark>',
            'highlight_tag_close' => '</mark>',
            'snippet_length' => 150,
            'max_results' => 1000,
            'enable_fuzzy' => true,
            'fuzzy_distance' => 2,
            'enable_synonyms' => true,
            'synonyms' => [],              // array or JSON file path
            'synonyms_case_sensitive' => false,
            'synonyms_max_expansions' => 3,
            'enable_suggestions' => true,
            'cache_ttl' => 300,
            'result_fields' => ['title', 'content', 'excerpt', 'url', 'author', 'tags', 'route'],
            'facet_min_count' => 1,
            // Fuzzy behavior tuning
            'fuzzy_last_token_only' => false,
            'fuzzy_correction_mode' => true,    // Enable modern typo correction by default
            'fuzzy_algorithm' => 'trigram',     // Best balance of speed/accuracy
            'correction_threshold' => 0.6,      // Lower threshold for better sensitivity
            // How many documents a term must appear in before it can be a
            // correction target. One, because a term that is in the index at
            // all is a real term somebody indexed on purpose. Two -- what this
            // used to be, as an undeclared literal repeated at five call sites
            // -- silently switched typo correction off for the terms that most
            // need it: on a catalogue, a shop or any small corpus, a product
            // name, a brand or a person's name appears in exactly one document,
            // so the whole of the vocabulary worth correcting to sat below the
            // floor. Raise it on a large, noisy corpus where a term appearing
            // once is more likely to be a typo than a word.
            'min_term_frequency' => 1,
            'trigram_size' => 3,
            'trigram_threshold' => 0.35,        // Lower threshold for better matching
            'jaro_winkler_threshold' => 0.85,   // Slightly lower for more matches
            'levenshtein_threshold' => 2,       // Keep current but optimize weighting
            'max_fuzzy_variations' => 15,       // Increase for better coverage
            // Prefix matching (requires FTS5 prefix index)
            'prefix_last_token' => false,
            // Geo scoring
            // distance_weight: 0.0..1.0, proportion of distance score in final score
            'distance_weight' => 0.0,
            // distance_decay_k: controls how fast distance score decays per km (higher = steeper)
            'distance_decay_k' => 0.005,
            // Fuzzy/synonyms shaping
            'fuzzy_total_max_variations' => 30,
            // Exact match boosting
            'exact_match_boost' => 2.0,     // Multiplier for exact phrase matches
            'exact_terms_boost' => 1.5,     // Multiplier for all exact terms present
            // Weight of a match on a word's stem against one on the word as typed, on an index that stems
            'stem_weight' => 0.5,
            'fuzzy_score_penalty' => 0.25,   // Reduced penalty for better fuzzy results
            // Two-pass search strategy (disabled by default for performance)
            'two_pass_search' => false,     // Enable two-pass search for better field weighting
            'primary_fields' => ['title', 'h1', 'name', 'label'], // Fields to search in first pass
            'primary_field_limit' => 100,   // Max results from first pass
            // Multi-column FTS (enabled by default for better performance)
            'multi_column_fts' => true      // Use separate FTS columns for native BM25 weighting
        ], $config);

        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Rank text queries by meaning as well as keywords. With none set (the
     * default), search behaves exactly as it always has.
     */
    public function setSemanticSearch(?SemanticSearch $semantic): void
    {
        $this->semantic = $semantic;
        $this->cache = [];
    }

    public function getSemanticSearch(): ?SemanticSearch
    {
        return $this->semantic;
    }

    /**
     * Forget results held in memory, after something changed what a search
     * would return without going through this engine.
     */
    public function clearResultCache(): void
    {
        $this->cache = [];
    }

    public function search(SearchQuery $query, array $options = []): SearchResults
    {
        $startTime = microtime(true);

        // Merge runtime options with config (runtime options take precedence)
        $originalConfig = $this->config;
        $originalBypassStorageCache = $this->bypassStorageCache;
        $this->config = array_merge($this->config, $options);

        // bypass_cache skips the results held here as well as the storage's
        // query cache.
        $cacheKey = !empty($query->getOptions()['bypass_cache']) ? null : $this->getCacheKey($query, $options);
        $changeToken = $cacheKey === null ? null : $this->indexChangeToken();
        // A search that cannot be told from another by its key is not cached anywhere: the
        // storage's query cache is bypassed for every query this search makes
        $this->bypassStorageCache = $cacheKey === null;

        $this->logger->debug('SearchEngine::search called', [
            'query_text' => $query->getQuery(),
            'is_fuzzy' => $query->isFuzzy(),
            'config_enable_fuzzy' => $this->config['enable_fuzzy'] ?? false,
            'config_fuzzy_algorithm' => $this->config['fuzzy_algorithm'] ?? 'not set'
        ]);

        if ($this->isCached($cacheKey, $changeToken)) {
            $this->logger->debug('Returning cached results', ['query' => $query->getQuery()]);
            // Restore original config before returning cached results
            $cachedResults = $this->cache[$cacheKey]['results'];
            $this->config = $originalConfig;
            $this->bypassStorageCache = $originalBypassStorageCache;
            return $cachedResults;
        }

        try {
            $this->logger->debug('Executing search', ['query' => $query->toArray()]);

            // Store original query for highlighting
            $originalQuery = $query->getQuery();

            $processedQuery = $this->processQuery($query);

            if ($this->isTermlessQuery($query, $processedQuery)) {
                $this->logger->debug('Query holds no searchable term', ['query' => $originalQuery]);

                $emptyResults = new SearchResults([], 0, microtime(true) - $startTime);
                $this->cacheResults($cacheKey, $emptyResults, $changeToken);

                return $emptyResults;
            }

            $storageQuery = $this->buildStorageQuery($processedQuery);

            // If deduplicating, we need to get ALL results first
            $originalLimit = $storageQuery['limit'];
            $originalOffset = $storageQuery['offset'];


            if ($options['unique_by_route'] ?? false) {
                // Temporarily override limit to get all results
                $storageQuery['limit'] = $this->config['max_results'];
                $storageQuery['offset'] = 0;
            }

            // Two-pass search strategy if enabled and field weights are configured
            $results = [];
            $totalCount = 0;

            $hybrid = null;
            if ($this->semanticApplies($query, $options)) {
                $hybrid = $this->hybridSearch(
                    $storageQuery,
                    $originalQuery,
                    $originalLimit,
                    $originalOffset,
                    (bool)($options['unique_by_route'] ?? false)
                );
            }

            if ($hybrid !== null) {
                [$results, $totalCount] = $hybrid;
            } elseif ($this->config['two_pass_search'] && !empty($this->config['field_weights'])) {
                $this->logger->debug('Executing two-pass search strategy');

                // First pass: Search only primary fields with high weights, those among the
                // fields the search is restricted to when it is
                $primaryFields = $this->config['primary_fields'];
                if (!empty($storageQuery['fields'])) {
                    $primaryFields = array_values(array_intersect($primaryFields, $storageQuery['fields']));
                }
                $primaryFieldWeights = [];
                foreach ($primaryFields as $field) {
                    if (isset($this->config['field_weights'][$field])) {
                        $primaryFieldWeights[$field] = $this->config['field_weights'][$field] * 2.0; // Double weights for primary pass
                    }
                }

                if (!empty($primaryFieldWeights)) {
                    $firstPassQuery = $storageQuery;
                    $firstPassQuery['field_weights'] = $primaryFieldWeights;
                    $firstPassQuery['fields'] = $primaryFields; // Restrict to primary fields
                    $firstPassQuery['limit'] = $this->config['primary_field_limit'];
                    $firstPassQuery['offset'] = 0;

                    try {
                        $primaryResults = $this->storageSearch($firstPassQuery);
                        $this->logger->debug('First pass results', ['count' => count($primaryResults)]);
                    } catch (\Exception $e) {
                        $this->logger->warning('First pass search failed', ['error' => $e->getMessage()]);
                        $primaryResults = [];
                    }
                } else {
                    $primaryResults = [];
                }

                // Second pass: Full search with all fields
                $secondPassQuery = $storageQuery;
                $secondPassResults = $this->storageSearch($secondPassQuery);

                // Merge results, prioritizing primary field matches
                $mergedResults = [];
                $seenIds = [];

                // Add primary results first (with boosted scores)
                foreach ($primaryResults as $result) {
                    $result['score'] = ($result['score'] ?? 0) * 1.5; // Boost primary field matches
                    $mergedResults[] = $result;
                    $seenIds[$result['id']] = true;
                }

                // Add remaining results from second pass
                foreach ($secondPassResults as $result) {
                    if (!isset($seenIds[$result['id']])) {
                        $mergedResults[] = $result;
                        $seenIds[$result['id']] = true;
                    }
                }

                // Sort merged results by score
                usort($mergedResults, function ($a, $b) {
                    return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
                });

                // Apply original limit/offset
                $results = array_slice($mergedResults, $originalOffset, $originalLimit);
                $totalCount = count($mergedResults);
            } else {
                // Single-pass search (standard mode). A first page that is not full holds every
                // match, unless a location filters the rows in PHP (field weights only re-rank
                // them, from candidates that are never fewer than the page).
                $results = $this->storageSearch($storageQuery);
                $totalCount = (int)$storageQuery['offset'] === 0
                    && count($results) < $storageQuery['limit']
                    && empty($storageQuery['geoFilters'])
                    ? count($results)
                    : $this->storage->count($this->indexName, $storageQuery);
            }

            // Pass original query for highlighting
            $searchResults = $this->processResults($results, $processedQuery, $originalQuery);

            // Apply unique_by_route if requested
            if ($options['unique_by_route'] ?? false) {
                $this->logger->debug('Applying deduplication', ['unique_by_route' => true]);
                $searchResults = $this->deduplicateByRoute($searchResults, $this->hasRequestedOrder($query));
                // Update total count after deduplication
                $totalCount = count($searchResults);

                // Now apply the original limit/offset to the deduplicated results
                $searchResults = array_slice($searchResults, $originalOffset, $originalLimit);
            } else {
                $this->logger->debug('Skipping deduplication', ['unique_by_route' => false]);
            }

            $facets = [];
            if (!empty($query->getFacets())) {
                $facets = $this->computeFacets($query, $storageQuery);
            }

            $aggregations = [];
            if (!empty($query->getAggregations())) {
                $aggregations = $this->computeAggregations($query, $storageQuery);
            }

            $searchTime = microtime(true) - $startTime;

            $finalResults = new SearchResults(
                $searchResults,
                $totalCount,
                $searchTime,
                $facets,
                $aggregations
            );

            if ($this->config['enable_suggestions'] && $finalResults->isEmpty()) {
                $suggestion = $this->generateSuggestion($query->getQuery());
                $finalResults->setSuggestion($suggestion);
            }

            if ($this->semantic !== null) {
                $finalResults->setMetadata(['semantic' => $hybrid !== null]);
            }

            $this->cacheResults($cacheKey, $finalResults, $changeToken);

            $this->logger->info('Search completed', [
                'query' => $query->getQuery(),
                'results' => count($searchResults),
                'total' => $totalCount,
                'time' => $searchTime
            ]);

            return $finalResults;
        } catch (\Exception $e) {
            $this->logger->error('Search failed', [
                'query' => $query->getQuery(),
                'error' => $e->getMessage()
            ]);

            throw new SearchException("Search failed: " . $e->getMessage(), 0, $e);
        } finally {
            // Always restore original config, even if an exception is thrown
            $this->config = $originalConfig;
            $this->bypassStorageCache = $originalBypassStorageCache;
        }
    }

    /**
     * Semantic ranking applies to a text query ranked by relevance, on an
     * index that holds vectors from the configured model. Geo searches and
     * explicit sorts keep their own ordering, and a search restricted to some
     * fields wants a word of the query in them, which a match by meaning has not.
     */
    private function semanticApplies(SearchQuery $query, array $options): bool
    {
        if ($this->semantic === null || (array_key_exists('semantic', $options) && !$options['semantic'])) {
            return false;
        }
        if (trim($query->getQuery()) === '' || $query->hasGeoFilters() || $query->getFields() !== []) {
            return false;
        }
        foreach (array_keys($query->getSort()) as $field) {
            if ($field !== '_score') {
                return false;
            }
        }

        try {
            return $this->semantic->hasVectors($this->indexName);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not check for vectors; using keyword search', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Run keyword and vector searches over the same filters and fuse their
     * rankings. Returns null when the query cannot be embedded, and the
     * caller falls back to keyword search.
     *
     * @return array{0:array, 1:int}|null Rows ready for processResults() and the total
     */
    private function hybridSearch(array $storageQuery, string $queryText, int $limit, int $offset, bool $unique): ?array
    {
        $config = $this->semantic->getConfig();
        $weight = (float)($this->config['semantic_weight'] ?? $config['weight']);
        $candidates = max((int)$config['candidates'], $offset + $limit);
        if ($unique) {
            // Several chunks of one page collapse into one result later.
            $candidates *= 4;
        }
        $candidates = max(1, min($candidates, (int)$this->config['max_results']));

        try {
            $vector = $this->semantic->embedQuery($queryText);
            $nearest = $this->semantic->nearest(
                $this->indexName,
                $vector,
                $storageQuery['filters'] ?? [],
                $storageQuery['language'] ?? null,
                $candidates
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Semantic search unavailable; using keyword search', [
                'index' => $this->indexName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $keywordQuery = $storageQuery;
        $keywordQuery['limit'] = $candidates;
        $keywordQuery['offset'] = 0;
        $keywordRows = $this->storageSearch($keywordQuery);
        $keywordTotal = $this->storage->count($this->indexName, $storageQuery);

        $rows = [];
        $keywordIds = [];
        foreach ($keywordRows as $row) {
            $id = (string)$row['id'];
            if (!isset($rows[$id])) {
                $rows[$id] = $row;
                $keywordIds[] = $id;
            }
        }

        $vectorIds = array_map('strval', array_keys($nearest));
        $vectorOnly = array_values(array_diff($vectorIds, $keywordIds));
        if (!empty($vectorOnly)) {
            foreach ($this->semantic->getSearchRows($this->indexName, $vectorOnly) as $id => $row) {
                $rows[(string)$id] = $row;
            }
        }

        $vectorScores = [];
        foreach ($nearest as $id => $similarity) {
            $vectorScores[(string)$id] = $similarity;
        }

        // One result per page: rank pages, not chunks. Otherwise the chunks of
        // one long page fill the vector ranking and push every other page
        // down it, and the route deduplication that follows adds up their
        // scores, so a page wins by length rather than by how well it matches.
        // Each page is ranked by its best chunk on each side.
        $representative = [];
        if ($unique) {
            $pageOf = function (string $id) use ($rows): string {
                $route = (string)($rows[$id]['route'] ?? '');
                return $route !== '' ? 'route:' . $route : 'id:' . $id;
            };
            $keywordPages = [];
            foreach ($keywordIds as $id) {
                $page = $pageOf($id);
                if (!isset($representative[$page])) {
                    $representative[$page] = $id;
                    $keywordPages[] = $page;
                }
            }
            $vectorPages = [];
            foreach ($vectorScores as $id => $similarity) {
                $id = (string)$id;
                if (!isset($rows[$id])) {
                    continue;
                }
                $page = $pageOf($id);
                if (!isset($vectorPages[$page])) {
                    $vectorPages[$page] = $similarity;
                    $representative[$page] = $representative[$page] ?? $id;
                }
            }
            $keywordIds = $keywordPages;
            $vectorScores = $vectorPages;
        }

        $results = [];
        foreach (SemanticSearch::fuse($keywordIds, $vectorScores, $weight, (int)$config['rrf_k']) as $key => $score) {
            $id = $unique ? $representative[(string)$key] : (string)$key;
            if (!isset($rows[$id])) {
                continue;
            }
            $row = $rows[$id];
            $row['score'] = $score;
            $results[] = $row;
        }

        // Keyword matches beyond the candidate window still count, plus the
        // documents only meaning found.
        $total = $keywordTotal + count($vectorOnly);

        if (!$unique) {
            $results = array_slice($results, $offset, $limit);
        }

        return [$results, $total];
    }

    public function suggest(string $term, array $options = []): array
    {
        $this->logger->debug('Generating suggestions', ['term' => $term]);

        $limit = (int)($options['limit'] ?? 10);
        $perVariant = (int)($options['per_variant'] ?? 5);
        $boostTitle = (float)($options['title_boost'] ?? 100.0);
        $boostPrefix = (float)($options['prefix_boost'] ?? 25.0);

        $fuzzyTerms = $this->generateFuzzyVariations($term);

        // Aggregate by suggestion text
        $agg = [];
        $seen = [];

        foreach ($fuzzyTerms as $variant) {
            $query = new SearchQuery($variant);
            $query->limit($perVariant);

            try {
                $results = $this->search($query);

                foreach ($results as $res) {
                    $title = $res->get('title', '');
                    if (empty($title)) {
                        continue;
                    }

                    // De-dup per variant-result pair
                    $key = UTF8::strtolower($title);
                    if (isset($seen[$variant][$key])) {
                        continue;
                    }
                    $seen[$variant][$key] = true;

                    $score = (float)$res->getScore();

                    // Prefer titles that contain or start with the (cleaned) variant
                    $titleLower = UTF8::strtolower($title);
                    $variantLower = UTF8::strtolower($variant);
                    if (UTF8::stripos($titleLower, $variantLower) !== false) {
                        $score += $boostTitle;
                    }
                    if (UTF8::stripos($titleLower, $variantLower) === 0) {
                        $score += $boostPrefix;
                    }

                    if (!isset($agg[$key])) {
                        $agg[$key] = [
                            'text' => $title,
                            'score' => $score,
                            'count' => 1,
                        ];
                    } else {
                        $agg[$key]['score'] = max($agg[$key]['score'], $score);
                        $agg[$key]['count'] += 1;
                    }
                }
            } catch (\Exception $e) {
                // Continue on errors per variant
                $this->logger->debug('Suggest variant failed', ['variant' => $variant, 'error' => $e->getMessage()]);
                continue;
            }
        }

        // Rank by frequency first, then score
        $suggestions = array_values($agg);
        usort($suggestions, function ($a, $b) {
            if ($a['count'] === $b['count']) {
                return $b['score'] <=> $a['score'];
            }
            return $b['count'] <=> $a['count'];
        });

        return array_slice($suggestions, 0, $limit);
    }

    public function count(SearchQuery $query): int
    {
        $processedQuery = $this->processQuery($query);

        if ($this->isTermlessQuery($query, $processedQuery)) {
            return 0;
        }

        $storageQuery = $this->buildStorageQuery($processedQuery);

        return $this->storage->count($this->indexName, $storageQuery);
    }

    public function getStats(): array
    {
        return $this->storage->getIndexStats($this->indexName);
    }

    /**
     * Update search engine configuration at runtime
     *
     * @param array $config Configuration options to merge
     */
    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);

        // Results and terms read with the old settings no longer hold
        $this->clearResultCache();
        $this->lexicon = null;
    }

    /**
     * True when the caller supplied query text that analysis reduced to nothing.
     *
     * An explicitly blank query means "no text query": geo-only and facet-only
     * searches are expressed that way, and storage answers them with a match-all
     * over whatever filters came with them. Text the user actually typed is a
     * different thing. If none of it survives as a searchable term — punctuation,
     * stray operators, stop words — the honest answer is no matches. Falling
     * through to the match-all branch instead answers a search box full of
     * punctuation with the entire index.
     */
    private function isTermlessQuery(SearchQuery $original, SearchQuery $processed): bool
    {
        return trim($original->getQuery()) !== '' && trim($processed->getQuery()) === '';
    }

    private function processQuery(SearchQuery $query): SearchQuery
    {
        $queryText = $query->getQuery();

        // An index that stems answers a query in the query's language, else its own
        $this->stemQuery = null;
        $this->queryStems = [];
        $this->wordStems = [];
        $this->indexStemLanguage = $this->storage instanceof ProvidesStemming
            ? $this->storage->stemmingFor($this->indexName)
            : null;
        $stemLanguage = $this->indexStemLanguage === null
            ? null
            : ($query->getLanguage() ?: $this->indexStemLanguage);
        if ($stemLanguage !== null && !StemmerFactory::isSupported($stemLanguage)) {
            $stemLanguage = null;
        }

        $tokens = $this->analyzer->tokenize($queryText);
        // Stop words are those of the query's language; with none, English, or on an
        // index that stems the language of the index
        $tokens = $this->analyzer->removeStopWords($tokens, $query->getLanguage() ?: $this->indexStemLanguage);

        // Reset fuzzy term map for this query
        $this->fuzzyTermMap = [];

        // Check if we should use correction mode (new approach) or expansion mode (old approach)
        $useCorrectionMode = $this->config['fuzzy_correction_mode'] ?? true;

        // Try merging adjacent tokens if enabled (e.g., "robo cop" -> "robocop")
        if ($query->isFuzzy() && $this->config['enable_fuzzy'] && ($this->config['enable_word_merge'] ?? true)) {
            $tokens = $this->tryMergeTokens($tokens);
        }

        $processedTokens = [];
        $correctedTokens = [];
        $tokenCount = count($tokens);
        $remainingFuzzy = (int)($this->config['fuzzy_total_max_variations'] ?? 30);
        $this->correctionLanguage = $query->getLanguage() ?: $this->indexStemLanguage;
        // The last word is searched as a prefix when the config asks, or when it reads as cut short
        $prefixLast = (bool)($this->config['prefix_last_token'] ?? false);
        $prefixAlternative = null;
        // Readings searched beside the words, each an FTS expression (see alternativeReading())
        $alternatives = [];

        foreach ($tokens as $idx => $token) {
            // Mark original terms as exact matches
            $this->fuzzyTermMap[mb_strtolower($token)] = ['type' => 'exact', 'original' => $token];

            if ($query->isFuzzy() && $this->config['enable_fuzzy']) {
                // If enabled, only fuzz the last token (better for as-you-type)
                if (($this->config['fuzzy_last_token_only'] ?? false) && $idx !== $tokenCount - 1) {
                    $processedTokens[] = $token;
                    $correctedTokens[] = $token;
                    continue;
                }

                if ($useCorrectionMode) {
                    // NEW APPROACH: Find single best correction. A last word cut short is also
                    // searched as a prefix, which finds every word it begins, and then needs
                    // no completion of its own; a correction of it is searched beside it
                    $cutShort = $idx === $tokenCount - 1 && $this->readsAsPrefix($token);
                    $correction = $this->findBestCorrection($token, !$cutShort);
                    if ($cutShort && strncmp($correction, mb_strtolower($token), strlen($token)) === 0) {
                        $correction = $token;
                        $prefixLast = true;
                    } elseif ($cutShort && $correction === $token) {
                        $prefixLast = true;
                    } elseif ($cutShort) {
                        $prefixAlternative = $token . '*';
                    }
                    $correctedTokens[] = $correction;
                    $alternative = $correction === $token ? $this->alternativeReading($token) : null;
                    if ($alternative !== null) {
                        $alternatives[] = strpos($alternative, ' ') === false
                            ? Fts5Escaper::escapeToken($alternative)
                            : self::phrase($alternative);
                        $this->fuzzyTermMap[$alternative] = ['type' => 'correction', 'original' => $token];
                    }

                    if ($correction !== $token) {
                        // Mark the correction as a fuzzy match
                        $this->fuzzyTermMap[mb_strtolower($correction)] = [
                            'type' => 'correction',
                            'original' => $token
                        ];

                        $this->logger->debug('Using typo correction', [
                            'original' => $token,
                            'corrected' => $correction
                        ]);
                    }

                    // For processedTokens, just use the correction
                    $processedTokens[] = $correction;
                } else {
                    // OLD APPROACH: Generate multiple variations
                    $processedTokens[] = $token;

                    $this->logger->debug('Fuzzy search enabled (expansion mode)', [
                        'token' => $token,
                        'fuzzy_algorithm' => $this->config['fuzzy_algorithm'] ?? 'basic',
                        'enable_fuzzy' => $this->config['enable_fuzzy']
                    ]);
                    $fuzzyTokens = $this->generateFuzzyVariations($token);
                    if ($remainingFuzzy > 0 && count($fuzzyTokens) > $remainingFuzzy) {
                        $fuzzyTokens = array_slice($fuzzyTokens, 0, $remainingFuzzy);
                    }
                    $this->logger->debug('Generated fuzzy variations', [
                        'original' => $token,
                        'variations' => $fuzzyTokens
                    ]);

                    // Mark fuzzy variations and calculate their distance
                    foreach ($fuzzyTokens as $fuzzyToken) {
                        if (mb_strtolower($fuzzyToken) !== mb_strtolower($token)) {
                            $fuzzyInfo = [
                                'type' => 'fuzzy',
                                'original' => $token
                            ];

                            // Add distance/similarity based on algorithm
                            $algorithm = $this->config['fuzzy_algorithm'] ?? 'basic';
                            if ($algorithm === 'levenshtein') {
                                $fuzzyInfo['distance'] = Levenshtein::distance($token, $fuzzyToken);
                            } elseif ($algorithm === 'jaro_winkler') {
                                $fuzzyInfo['similarity'] = JaroWinkler::similarity($token, $fuzzyToken);
                            } elseif ($algorithm === 'trigram') {
                                $fuzzyInfo['similarity'] = Trigram::similarity($token, $fuzzyToken);
                            } else {
                                $fuzzyInfo['distance'] = 1; // Default for basic algorithm
                            }

                            $this->fuzzyTermMap[mb_strtolower($fuzzyToken)] = $fuzzyInfo;
                        }
                    }

                    $processedTokens = array_merge($processedTokens, $fuzzyTokens);
                    // Budget down (exclude original token itself if present at [0])
                    $remainingFuzzy = max(0, $remainingFuzzy - max(0, count($fuzzyTokens) - 1));
                }
            } else {
                // No fuzzy - just use original token
                $processedTokens[] = $token;
                $correctedTokens[] = $token;
            }
        }

        if ($this->config['enable_synonyms']) {
            $processedTokens = $this->expandSynonyms($processedTokens, $query->getLanguage());
        }

        // For SQLite FTS5, we need to properly format the query
        // In correction mode, use corrected tokens directly
        if ($query->isFuzzy() && $this->config['enable_fuzzy'] && $useCorrectionMode && !empty($correctedTokens)) {
            // Use corrected tokens as if the user typed them correctly
            $tokensToUse = $correctedTokens;
        } else {
            // Use original tokens (for non-fuzzy or expansion mode)
            $tokensToUse = $tokens;
        }

        // Separate exact tokens from fuzzy variations
        $fuzzyTokens = [];

        // Corrections that make sense together: a word whose neighbour it is never near is
        // read again with the others ('die herd' is 'die hard', 'blek panther' 'black panther')
        if ($query->isFuzzy() && $this->config['enable_fuzzy'] && $useCorrectionMode && count($tokensToUse) > 1) {
            $tokensToUse = $this->correctInContext($tokens, $tokensToUse, $prefixLast);
            foreach ($tokensToUse as $i => $word) {
                if ($word !== $correctedTokens[$i]) {
                    $this->fuzzyTermMap[mb_strtolower($word)] = ['type' => 'correction', 'original' => $tokens[$i]];
                    $this->logger->debug('Using typo correction', ['original' => $tokens[$i], 'corrected' => $word]);
                }
            }
        }

        $exactTokens = $tokensToUse;

        // In expansion mode, add fuzzy variations
        if (!$useCorrectionMode) {
            foreach ($processedTokens as $token) {
                if (!in_array($token, $exactTokens)) {
                    $fuzzyTokens[] = $token;
                }
            }
        }

        // Build query that prioritizes exact matches
        // Include synonyms (if any) even when fuzzy is disabled
        if (($this->config['enable_synonyms'] ?? false) && !$query->isFuzzy() && !empty($this->config['synonyms'])) {
            $additional = [];
            foreach ($processedTokens as $t) {
                if (!in_array($t, $exactTokens, true)) {
                    $additional[] = $t;
                }
            }
            if (!empty($additional)) {
                $exactTokens = array_values(array_unique(array_merge($exactTokens, $additional)));
                $fuzzyTokens = [];
            }
        }

        // The raw query is an OR of components: the phrase, the words near each other, and
        // each word. A fuzzy search in correction mode builds it from the corrected words, so
        // it finds what a search without fuzzy finds for them; an AND of the words found
        // nothing when one of them was not in the documents ('Stir Wars' found no 'Star Wars').
        if ($query->isFuzzy() && !$useCorrectionMode && !empty($fuzzyTokens)) {
            // Build structured query that strongly prioritizes exact matches
            // Use parentheses to group exact matches with higher priority
            // Optional prefix on last token
            if ($prefixLast && !empty($exactTokens)) {
                $lastIdx = count($exactTokens) - 1;
                $exactTokens[$lastIdx] .= '*';
            }

            // Build exact match component with boost
            $exactComponents = [];
            if (count($tokens) > 1) {
                // Escape tokens for FTS phrase
                $escapedTokens = Fts5Escaper::escapeTokens($tokens, true);
                // Exact phrase gets highest priority
                if (!empty($escapedTokens)) {
                    $exactComponents[] = '"' . implode(' ', $escapedTokens) . '"';
                }
            }

            // Escape exact tokens for FTS (as bare terms)
            $escapedExactTokens = Fts5Escaper::escapeTokens($exactTokens);

            // Add individual exact tokens with NEAR proximity (if multiple tokens)
            if (count($escapedExactTokens) > 1) {
                // Use NEAR operator to boost documents with terms close together
                $exactComponents[] = 'NEAR(' . implode(' ', $escapedExactTokens) . ', 10)';
            } elseif (!empty($escapedExactTokens)) {
                // Single token - just add it
                foreach ($escapedExactTokens as $token) {
                    $exactComponents[] = $token;
                }
            }

            // Build fuzzy component - group them with lower priority
            $escapedFuzzyTokens = Fts5Escaper::escapeTokens($fuzzyTokens);
            $fuzzyComponent = count($escapedFuzzyTokens) > 0 ? '(' . implode(' OR ', $escapedFuzzyTokens) . ')' : '';

            // Combine with exact matches having priority
            // Structure: (exact_phrase OR NEAR(exact_terms)) OR (fuzzy_terms)
            // This ensures exact matches score higher than fuzzy ones
            if (!empty($exactComponents) && $fuzzyComponent !== '') {
                $processedQuery = '(' . implode(' OR ', $exactComponents) . ') OR ' . $fuzzyComponent;
            } elseif (!empty($exactComponents)) {
                $processedQuery = implode(' OR ', $exactComponents);
            } else {
                $processedQuery = $fuzzyComponent;
            }
        } else {
            // No fuzzy variations (a search without fuzzy, or a fuzzy one in correction mode):
            // the words as searched, plus synonyms
            if ($prefixLast && !empty($exactTokens)) {
                $lastIdx = count($exactTokens) - 1;
                $exactTokens[$lastIdx] .= '*';
            }

            $exactComponents = [];
            $escapedExactTokens = Fts5Escaper::escapeTokens($exactTokens);
            if (count($tokensToUse) > 1) {
                // The phrase is of the words as searched (corrected, without synonyms)
                $escapedTokens = Fts5Escaper::escapeTokens($tokensToUse, true);

                // Add exact phrase
                if (!empty($escapedTokens)) {
                    $exactComponents[] = '"' . implode(' ', $escapedTokens) . '"';
                }
                // Add NEAR query for proximity boost
                if (count($escapedExactTokens) > 1) {
                    $exactComponents[] = 'NEAR(' . implode(' ', $escapedExactTokens) . ', 10)';
                }
            }
            // Add individual tokens
            foreach ($escapedExactTokens as $token) {
                $exactComponents[] = $token;
            }
            if ($prefixAlternative !== null) {
                $alternatives[] = Fts5Escaper::escapeToken($prefixAlternative);
            }
            foreach ($alternatives as $alternative) {
                $exactComponents[] = $alternative;
            }

            $processedQuery = implode(' OR ', array_unique($exactComponents));
        }

        // The stems of the words as searched join the raw query as further alternatives, and
        // so do those of a word as typed that was corrected: `runs` corrected to `rugs` also
        // finds what `run` finds. The corrections are one per token, so they line up with
        // $tokens; the typed words go first, as a prefix applies to the last word searched.
        if ($stemLanguage !== null && $processedQuery !== '') {
            $typedWords = [];
            foreach ($tokensToUse as $i => $word) {
                if (isset($tokens[$i]) && strcasecmp($tokens[$i], $word) !== 0) {
                    $typedWords[] = $tokens[$i];
                }
            }
            $alternatives = StemQuery::alternatives(
                $this->analyzer,
                $processedQuery,
                array_merge($typedWords, $tokensToUse),
                $stemLanguage,
                $prefixLast
            );
            $this->stemQuery = $alternatives['query'];
            $this->queryStems = array_merge($this->queryStems, $alternatives['stems']);
        }

        $this->logger->debug('Processed query', ['original' => $queryText, 'processed' => $processedQuery]);

        $newQuery = clone $query;
        $newQuery->setQuery($processedQuery);

        return $newQuery;
    }

    /**
     * Runs a query on the storage, without its query cache when the search being run is
     * one that is not cached (see getCacheKey()).
     */
    private function storageSearch(array $storageQuery): array
    {
        if ($this->bypassStorageCache) {
            $storageQuery['bypass_cache'] = true;
        }

        return $this->storage->search($this->indexName, $storageQuery);
    }

    private function buildStorageQuery(SearchQuery $query): array
    {
        $storageQuery = [
            'query' => $query->getQuery(),
            'filters' => $query->getFilters(),
            'limit' => min($query->getLimit(), $this->config['max_results']),
            'offset' => $query->getOffset(),
            'sort' => $query->getSort(),
            'language' => $query->getLanguage()
        ];

        if (!empty($query->getFields())) {
            $storageQuery['fields'] = $query->getFields();
        }

        // On an index that stems, the words are also matched by their stems
        if ($this->stemQuery !== null) {
            $storageQuery['stem_query'] = $this->stemQuery;
            $storageQuery['stem_weight'] = (float)($this->config['stem_weight'] ?? 0.5);
        }

        // Pass field weights if configured
        if (!empty($this->config['field_weights'])) {
            $storageQuery['field_weights'] = $this->config['field_weights'];
        }
        foreach ([
            'field_weight_candidate_multiplier',
            'field_weight_candidate_min',
            'field_weight_candidate_max',
            'field_weight_candidate_cap',
        ] as $key) {
            if (isset($this->config[$key])) {
                $storageQuery[$key] = $this->config[$key];
            }
        }

        // Add geo filters if present
        if ($query->hasGeoFilters()) {
            $geoFilters = $query->getGeoFilters();

            // Convert GeoPoint and GeoBounds objects to arrays for storage layer
            if (isset($geoFilters['near'])) {
                $geoFilters['near']['point'] = $geoFilters['near']['point']->toArray();
            }

            if (isset($geoFilters['within'])) {
                $geoFilters['within']['bounds'] = $geoFilters['within']['bounds']->toArray();
            }

            if (isset($geoFilters['distance_sort'])) {
                $geoFilters['distance_sort']['from'] = $geoFilters['distance_sort']['from']->toArray();
            }

            // search.geo_units is the unit of a near() radius and max_distance when
            // the query names none
            if (!isset($geoFilters['units']) && is_string($this->config['geo_units'] ?? null)) {
                $geoFilters['units'] = strtolower($this->config['geo_units']);
            }

            $storageQuery['geoFilters'] = $geoFilters;
        }

        $queryOptions = $query->getOptions();
        if (array_key_exists('bypass_cache', $queryOptions)) {
            $storageQuery['bypass_cache'] = (bool)$queryOptions['bypass_cache'];
        }

        return $storageQuery;
    }

    private function processResults(array $results, SearchQuery $query, ?string $originalQuery = null): array
    {
        $processedResults = [];
        $minScore = $this->config['min_score'];

        // Find the maximum score for normalization
        $maxScore = 0.0;
        foreach ($results as $result) {
            if ($result['score'] > $maxScore) {
                $maxScore = $result['score'];
            }
        }

        // The fuzzy penalty compares each result with the query's own terms
        $penalize = $query->isFuzzy() && !empty($this->fuzzyTermMap);
        $queryTokens = $penalize ? array_map('mb_strtolower', $this->analyzer->tokenize($query->getQuery())) : [];
        $systemFields = array_flip([
            'id', 'score', 'metadata', 'language', 'type', 'timestamp', 'distance', 'centroid_lat', 'centroid_lng',
        ]);

        foreach ($results as $result) {
            if ($result['score'] < $minScore) {
                continue;
            }

            // Apply fuzzy penalty if this is a fuzzy search
            $adjustedScore = $result['score'];
            if ($penalize) {
                $adjustedScore = $result['score'] * (1 - $this->calculateFuzzyPenalty($result, $queryTokens));
            }

            // Content fields, without metadata and system fields
            $contentFields = array_diff_key($result, $systemFields);

            $highlights = [];
            if ($query->shouldHighlight()) {
                // On an index that stems, the words found by their stem are marked too, in
                // the language of the result, else of the query, else of the index
                $stemLanguage = null;
                if ($this->indexStemLanguage !== null) {
                    $stemLanguage = ($result['language'] ?? null) ?: ($query->getLanguage() ?: $this->indexStemLanguage);
                }
                $highlights = $this->generateHighlights(
                    $contentFields,
                    $originalQuery ?? $query->getQuery(),
                    $query->getHighlightLength(),
                    $stemLanguage
                );
            }

            // Normalize text score to 0-100 range
            $normalizedScore = $maxScore > 0 ? round(($adjustedScore / $maxScore) * 100, 1) : 0;

            // Optional distance-based scoring (0-100) using exponential decay over km
            $finalScore = $normalizedScore;
            if (isset($result['distance'])) {
                $km = max(0.0, (float)$result['distance'] / 1000.0);
                $k = (float)($this->config['distance_decay_k'] ?? 0.005);
                $distanceScore = max(0.0, min(100.0, 100.0 * exp(-$k * $km)));
                $dw = (float)($this->config['distance_weight'] ?? 0.0);
                $dw = max(0.0, min(1.0, $dw));
                $finalScore = round((1.0 - $dw) * $normalizedScore + $dw * $distanceScore, 1);
            }

            $filteredDocument = $this->filterResultFields($contentFields);

            $resultData = [
                'id' => $result['id'],
                'score' => $finalScore,
                'document' => $filteredDocument,
                'highlights' => $highlights,
                'metadata' => $result['metadata'] ?? []
            ];

            // Add distance if present and attach optional units/bearing metadata
            if (isset($result['distance'])) {
                $resultData['distance'] = $result['distance'];
                $geoFilters = $query->getGeoFilters();
                $units = strtolower($geoFilters['units'] ?? ($this->config['geo_units'] ?? 'm'));
                $resultData['metadata']['distance_units'] = in_array($units, ['km','mi']) ? $units : 'm';
                if (isset($result['centroid_lat']) && isset($result['centroid_lng'])) {
                    $from = $geoFilters['distance_sort']['from'] ?? ($geoFilters['near']['point'] ?? null);
                    if ($from instanceof \YetiSearch\Geo\GeoPoint) {
                        $fromLat = $from->getLatitude();
                        $fromLng = $from->getLongitude();
                    } elseif (is_array($from) && isset($from['lat'], $from['lng'])) {
                        $fromLat = (float)$from['lat'];
                        $fromLng = (float)$from['lng'];
                    } else {
                        $fromLat = $fromLng = null;
                    }
                    if ($fromLat !== null) {
                        $bearing = $this->computeBearing($fromLat, $fromLng, (float)$result['centroid_lat'], (float)$result['centroid_lng']);
                        $resultData['metadata']['bearing'] = $bearing;
                        $resultData['metadata']['bearing_cardinal'] = $this->bearingToCardinal($bearing);
                    }
                }
            }

            $processedResult = new SearchResult($resultData);

            $processedResults[] = $processedResult;
        }

        // Re-sort results if we applied fuzzy penalties or geo scoring weight, unless the
        // query asked for an order of its own (a distance sort or sortBy)
        if (!$this->hasRequestedOrder($query) && ($penalize || ($this->config['distance_weight'] ?? 0.0) > 0)) {
            usort($processedResults, function ($a, $b) {
                return $b->getScore() <=> $a->getScore();
            });
        }

        return $processedResults;
    }

    private function computeBearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dlambda = deg2rad($lng2 - $lng1);
        $y = sin($dlambda) * cos($phi2);
        $x = cos($phi1) * sin($phi2) - sin($phi1) * cos($phi2) * cos($dlambda);
        $theta = atan2($y, $x);
        $deg = rad2deg($theta);
        return fmod(($deg + 360.0), 360.0);
    }

    private function bearingToCardinal(float $bearing): string
    {
        $dirs = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];
        $idx = (int)round(fmod($bearing, 360.0) / 22.5) % 16;
        return $dirs[$idx];
    }

    /**
     * @param string[] $originalTokens The query's terms, lowercased
     */
    private function calculateFuzzyPenalty(array $result, array $originalTokens): float
    {
        // Get the configured fuzzy penalty (default 0.5 = 50% penalty for fuzzy matches)
        $basePenalty = $this->config['fuzzy_score_penalty'] ?? 0.5;

        // Try to determine which terms matched in this result
        // This is a simplified approach - ideally we'd parse the FTS match info
        $documentText = '';

        // Handle external_content mode where content is nested under 'document' key
        if (isset($result['document']) && is_array($result['document'])) {
            foreach ($result['document'] as $field => $value) {
                if (is_string($value)) {
                    $documentText .= ' ' . mb_strtolower($value);
                }
            }
        } else {
            // Legacy mode: extract content fields directly from result
            $contentFields = array_diff_key($result, array_flip(['id', 'score', 'metadata', 'language', 'type', 'timestamp', 'distance', 'highlights']));
            if (!empty($contentFields)) {
                foreach ($contentFields as $field => $value) {
                    if (is_string($value)) {
                        $documentText .= ' ' . mb_strtolower($value);
                    }
                }
            }
        }

        // Check for exact phrase match
        $hasExactPhrase = false;
        if (count($originalTokens) > 1) {
            $exactPhrase = implode(' ', $originalTokens);
            if (stripos($documentText, $exactPhrase) !== false) {
                $hasExactPhrase = true;
            }
        }

        // Check if any exact terms match
        $exactMatchCount = 0;
        $totalExactTerms = count($originalTokens);
        $hasFuzzyMatch = false;
        $minDistance = PHP_INT_MAX;
        $maxSimilarity = 0.0;

        // Count exact token matches
        foreach ($originalTokens as $token) {
            if (stripos($documentText, $token) !== false) {
                $exactMatchCount++;
            }
        }

        // Check fuzzy matches
        foreach ($this->fuzzyTermMap as $term => $info) {
            if ($info['type'] === 'fuzzy' && stripos($documentText, $term) !== false) {
                $hasFuzzyMatch = true;
                if (isset($info['distance'])) {
                    $minDistance = min($minDistance, $info['distance']);
                }
                if (isset($info['similarity'])) {
                    $maxSimilarity = max($maxSimilarity, $info['similarity']);
                }
            }
        }

        // Calculate exact match ratio
        $exactMatchRatio = $totalExactTerms > 0 ? $exactMatchCount / $totalExactTerms : 0;

        // If document has exact phrase match, minimal penalty
        if ($hasExactPhrase) {
            return 0.05; // 5% penalty - still prioritize over non-matches but below perfect
        }

        // If all exact terms match, very small penalty
        if ($exactMatchRatio >= 1.0 && !$hasFuzzyMatch) {
            return 0.1; // 10% penalty for all terms matching but not as phrase
        }

        // If most exact terms match
        if ($exactMatchRatio >= 0.75 && !$hasFuzzyMatch) {
            return 0.2; // 20% penalty
        }

        // Mixed exact and fuzzy matches
        if ($exactMatchRatio > 0 && $hasFuzzyMatch) {
            // Scale penalty based on how many exact matches we have
            // More exact matches = less penalty
            $mixedPenalty = $basePenalty * (1.0 - $exactMatchRatio * 0.5);
            return $mixedPenalty;
        }

        // Only fuzzy matches - apply stronger penalty based on similarity/distance
        if ($hasFuzzyMatch) {
            if ($maxSimilarity > 0) {
                // For Jaro-Winkler: higher similarity = less penalty
                // But still apply significant penalty for fuzzy-only matches
                if ($maxSimilarity >= 0.95) {
                    return $basePenalty * 0.7; // Very close match
                } elseif ($maxSimilarity >= 0.85) {
                    return $basePenalty * 0.85; // Good match
                } else {
                    return $basePenalty; // Full penalty for poor matches
                }
            } elseif ($minDistance !== PHP_INT_MAX && $minDistance > 0) {
                // For Levenshtein: smaller distance = less penalty
                if ($minDistance === 1) {
                    return $basePenalty * 0.7; // Single character difference
                } elseif ($minDistance === 2) {
                    return $basePenalty * 0.85; // Two character difference
                } else {
                    return $basePenalty; // Full penalty for larger distances
                }
            }
        }

        // No matches at all or unknown case - apply full penalty
        return $basePenalty;
    }

    private function generateHighlights(array $document, string $query, int $length, ?string $stemLanguage = null): array
    {
        $highlights = [];

        // Build a token list from the raw query and any fuzzy variants
        // Clean up FTS syntax: remove quotes, un-escape apostrophes, remove operators
        $cleanQuery = $query;
        $cleanQuery = str_replace("''", "'", $cleanQuery);  // Un-escape apostrophes
        $cleanQuery = preg_replace('/["()]/', ' ', $cleanQuery);  // Remove quotes and parens
        $cleanQuery = preg_replace('/\b(OR|AND|NEAR|NOT)\b/i', ' ', $cleanQuery);  // Remove FTS operators
        $cleanQuery = preg_replace('/\s+/', ' ', $cleanQuery);  // Normalize spaces

        $tokens = array_filter(array_map('trim', explode(' ', mb_strtolower($cleanQuery))));

        // If fuzzy processing generated variants, include those for highlighting
        if (!empty($this->fuzzyTermMap)) {
            foreach ($this->fuzzyTermMap as $term => $info) {
                // Only include clean terms (skip wildcards or special chars)
                if ($term === '' || strpos($term, '*') !== false || strpos($term, '"') !== false) {
                    continue;
                }
                // Un-escape apostrophes in fuzzy terms too
                $cleanTerm = str_replace("''", "'", $term);
                $tokens[] = $cleanTerm;
            }
        }

        // Words of the document that share a stem with a query term are marked as well.
        // A word a term already marks (the term itself, or with an 's') is left out, or
        // it would be marked twice.
        if ($stemLanguage !== null && !empty($this->queryStems)) {
            $marked = array_flip($tokens);
            foreach ($this->wordsSharingQueryStem($document, $stemLanguage) as $word) {
                if (!isset($marked[$word]) && !(substr($word, -1) === 's' && isset($marked[substr($word, 0, -1)]))) {
                    $tokens[] = $word;
                }
            }
        }

        // De-duplicate and prefer longer tokens first to avoid nested highlights
        $tokens = array_values(array_unique($tokens));
        usort($tokens, function ($a, $b) {
            return mb_strlen($b) <=> mb_strlen($a);
        });

        foreach ($document as $field => $value) {
            // Handle nested content structure (for chunks)
            if ($field === 'content' && is_array($value)) {
                // Process nested fields like content.title, content.content, etc.
                foreach ($value as $subfield => $subvalue) {
                    if (!is_string($subvalue) || $subvalue === '') {
                        continue;
                    }

                    $snippet = $this->extractSnippet($subvalue, $tokens, $length);
                    if ($snippet !== '') {
                        // For important fields like title, use them directly
                        if ($subfield === 'title' && !isset($highlights['title'])) {
                            $highlights['title'] = $this->highlightTerms($snippet, $tokens);
                        } elseif ($subfield === 'content') {
                            // For chunks, use the nested content as the main content highlight
                            $highlights['content'] = $this->highlightTerms($snippet, $tokens);
                        } else {
                            $highlights[$field . '.' . $subfield] = $this->highlightTerms($snippet, $tokens);
                        }
                    }
                }
            } elseif (is_string($value) && $value !== '') {
                $snippet = $this->extractSnippet($value, $tokens, $length);
                if ($snippet !== '') {
                    $highlights[$field] = $this->highlightTerms($snippet, $tokens);
                }
            }
        }

        return $highlights;
    }

    /**
     * The words of a document whose stem is the stem of one of the query's terms,
     * as they are written in it, lowercased.
     *
     * @return string[]
     */
    private function wordsSharingQueryStem(array $document, string $language): array
    {
        if (!StemmerFactory::isSupported($language)) {
            return [];
        }

        $queryStems = array_flip($this->queryStems);
        $found = [];
        $visit = function ($value) use (&$visit, &$found, $language, $queryStems) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $visit($item);
                }
                return;
            }
            if (!is_string($value) || $value === '') {
                return;
            }
            if (!preg_match_all('/[\p{L}\p{N}]{2,}/u', $value, $matches)) {
                return;
            }
            foreach ($matches[0] as $word) {
                $word = UTF8::strtolower($word);
                if (isset($found[$word])) {
                    continue;
                }
                if (!isset($this->wordStems[$language][$word])) {
                    $this->wordStems[$language][$word] = $this->analyzer->stem($word, $language);
                }
                if (isset($queryStems[$this->wordStems[$language][$word]])) {
                    $found[$word] = true;
                }
            }
        };
        $visit($document);

        return array_map('strval', array_keys($found));
    }

    private function extractSnippet(string $text, array $terms, int $length): string
    {
        $bestPosition = 0;
        $bestScore = 0;

        $lowerText = UTF8::strtolower($text);
        $textLength = UTF8::strlen($text);

        foreach ($terms as $term) {
            // Try exact match first
            $pos = UTF8::stripos($lowerText, $term);
            if ($pos !== false) {
                $score = 1 / ($pos + 1);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestPosition = $pos;
                }
            }

            // Also try plural form
            $pluralPos = UTF8::stripos($lowerText, $term . 's');
            if ($pluralPos !== false) {
                $score = 1 / ($pluralPos + 1);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestPosition = $pluralPos;
                }
            }
        }

        // Adjust start to show more context before the match
        $contextBefore = min(50, intdiv($length, 3)); // Show some context before match
        $start = max(0, $bestPosition - $contextBefore);
        $end = min($textLength, $start + $length);

        if ($start > 0) {
            $pos = mb_strpos($text, ' ', $start, 'UTF-8');
            $start = $pos !== false ? $pos : $start;
        }

        if ($end < $textLength) {
            $prefix = mb_substr($text, 0, $end, 'UTF-8');
            $pos = mb_strrpos($prefix, ' ', 0, 'UTF-8');
            $end = $pos !== false ? $pos : $end;
        }

        $snippet = mb_substr($text, $start, $end - $start, 'UTF-8');

        if ($start > 0) {
            $snippet = '...' . ltrim($snippet);
        }

        if ($end < $textLength) {
            $snippet = rtrim($snippet) . '...';
        }

        return $snippet;
    }

    /**
     * Marks the terms, and their plurals with an 's', in one pass over the text, so a term is
     * never marked inside another term's mark or inside the tags of one (a query for "mark"
     * with the default <mark> tag). The terms come longest first.
     */
    private function highlightTerms(string $text, array $terms): string
    {
        $alternatives = [];
        foreach ($terms as $term) {
            if ($term !== '') {
                $alternatives[] = preg_quote($term, '/');
            }
        }
        if (empty($alternatives)) {
            return $text;
        }

        $open = $this->config['highlight_tag'];
        $close = $this->config['highlight_tag_close'];
        $highlighted = preg_replace_callback(
            '/\b((?:' . implode('|', $alternatives) . ')s?)\b/iu',
            function (array $match) use ($open, $close): string {
                return $open . $match[1] . $close;
            },
            $text
        );

        return $highlighted ?? $text;
    }

    /**
     * Whether the query asks for an order of its own (a distance sort or sortBy), which
     * deduplication must keep instead of ranking by score.
     */
    private function hasRequestedOrder(SearchQuery $query): bool
    {
        return !empty($query->getSort()) || isset($query->getGeoFilters()['distance_sort']);
    }

    /**
     * @param bool $keepOrder Keep each route where it first appears in $results (for a
     *                        query that sorts by distance or a field) instead of ranking
     *                        the routes by their composite score
     */
    private function deduplicateByRoute(array $results, bool $keepOrder = false): array
    {
        // Aggregate all chunk scores per route and rank by the composite score
        $routeAgg = [];
        $noRoute = [];
        $position = 0;

        foreach ($results as $result) {
            $position++;
            $route = (string)$result->get('route', '');
            if ($route === '') {
                $noRoute[] = ['result' => $result, 'position' => $position];
                continue;
            }
            if (!isset($routeAgg[$route])) {
                $routeAgg[$route] = [
                    'sum' => 0.0,
                    'count' => 0,
                    'best' => $result,
                    'bestScore' => $result->getScore(),
                    'position' => $position,
                ];
            }
            $routeAgg[$route]['sum'] += (float)$result->getScore();
            $routeAgg[$route]['count'] += 1;
            if ($result->getScore() > $routeAgg[$route]['bestScore']) {
                $routeAgg[$route]['best'] = $result;
                $routeAgg[$route]['bestScore'] = $result->getScore();
            }
        }

        $deduplicated = [];
        $positions = [];
        foreach ($routeAgg as $route => $agg) {
            $best = $agg['best'];
            // Re-emit a representative result using the best document but composite score
            $doc = $best->getDocument();
            $meta = $best->getMetadata();
            $meta['chunk_aggregate_score'] = $agg['sum'];
            $meta['chunk_count'] = $agg['count'];
            $data = [
                'id' => $best->getId(),
                'score' => (float)$agg['sum'],
                'document' => $doc,
                'highlights' => $best->getHighlights(),
                'metadata' => $meta
            ];
            if ($best->hasDistance()) {
                $data['distance'] = $best->getDistance();
            }
            $deduplicated[] = new \YetiSearch\Models\SearchResult($data);
            $positions[] = $agg['position'];
        }

        foreach ($noRoute as $entry) {
            $deduplicated[] = $entry['result'];
            $positions[] = $entry['position'];
        }

        if ($keepOrder) {
            array_multisort($positions, SORT_ASC, SORT_NUMERIC, $deduplicated);
            return $deduplicated;
        }

        usort($deduplicated, function ($a, $b) {
            return $b->getScore() <=> $a->getScore();
        });
        return $deduplicated;
    }

    private function filterResultFields(array $document): array
    {
        if (empty($this->config['result_fields'])) {
            return $document;
        }

        $filtered = [];
        foreach ($this->config['result_fields'] as $field) {
            if (isset($document[$field])) {
                $filtered[$field] = $document[$field];
            }
        }

        return $filtered;
    }

    /**
     * @param array $storageQuery The query the results were found with. The facets count its
     *                            matches: the processed text (corrected, expanded and escaped),
     *                            the filters, the location and the stems.
     */
    private function computeFacets(SearchQuery $query, array $storageQuery): array
    {
        $facets = [];
        $counted = ['query', 'filters', 'language', 'geoFilters', 'stem_query', 'fields'];
        $facetQuery = ['limit' => 1000, 'offset' => 0] + array_intersect_key($storageQuery, array_flip($counted));
        // Read once for all the value and range facets
        $rows = null;

        foreach ($query->getFacets() as $name => $options) {
            // Distance facet: bucket results by distance thresholds from a point
            if ($name === 'distance') {
                $from = $options['from'] ?? null;
                if ($from instanceof \YetiSearch\Geo\GeoPoint) {
                    $fromArr = $from->toArray();
                } elseif (is_array($from) && isset($from['lat'], $from['lng'])) {
                    $fromArr = ['lat' => (float)$from['lat'],'lng' => (float)$from['lng']];
                } else {
                    $this->logger->warning('Distance facet skipped: it needs a from point with lat and lng');
                    continue;
                }
                $ranges = $options['ranges'] ?? [];
                if (empty($ranges) || !is_array($ranges)) {
                    $this->logger->warning('Distance facet skipped: it needs a list of distance thresholds in ranges');
                    continue;
                }
                $units = strtolower($options['units'] ?? ($this->config['geo_units'] ?? 'm'));
                $factor = 1.0;
                if ($units === 'km') {
                    $factor = 1000.0;
                } elseif (in_array($units, ['mi','mile','miles'])) {
                    $factor = 1609.344;
                }

                // Measured from the facet's point, among the documents the query's location
                // keeps. A near() of the query is measured from its own point, which is the
                // facet's in the usual case.
                $distanceQuery = $facetQuery;
                $distanceQuery['geoFilters']['distance_sort'] = ['from' => $fromArr, 'direction' => 'ASC'];
                try {
                    $results = $this->storageSearch($distanceQuery);
                    // Counted by position: a float array key would be cut to
                    // an int, putting 1.5 km in the 1 km bucket.
                    $ranges = array_values($ranges);
                    $counts = array_fill(0, count($ranges), 0);
                    $beyond = 0;
                    foreach ($results as $row) {
                        // A document with no geo has no distance, so it belongs in no bucket
                        if (!isset($row['distance'])) {
                            continue;
                        }
                        $dist = (float)$row['distance'];
                        $dUnits = $dist / $factor;
                        $placed = false;
                        foreach ($ranges as $i => $r) {
                            if ($dUnits <= (float)$r) {
                                $counts[$i]++;
                                $placed = true;
                                break;
                            }
                        }
                        if (!$placed) {
                            $beyond++;
                        }
                    }
                    $facetResults = [];
                    foreach ($ranges as $i => $r) {
                        $facetResults[] = ['value' => sprintf('<= %s %s', $r, $units), 'count' => $counts[$i]];
                    }
                    if ($beyond > 0) {
                        $facetResults[] = ['value' => sprintf('> %s %s', end($ranges), $units), 'count' => $beyond];
                    }
                    $facets['distance'] = $facetResults;
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to compute distance facet', ['error' => $e->getMessage()]);
                }
                continue;
            }

            // The facet's name is the key it comes back under; 'field' lets a
            // facet named price_range count the price field.
            $field = $options['field'] ?? $name;
            if (!is_string($field) || $field === '') {
                $this->logger->warning('Facet skipped: field must be a field name', ['facet' => $name]);
                continue;
            }

            $buckets = null;
            if (array_key_exists('ranges', $options) || ($options['type'] ?? null) === 'range') {
                $buckets = $this->facetRangeBuckets($name, $options['ranges'] ?? null);
                if ($buckets === []) {
                    $facets[$name] = [];
                    continue;
                }
            }

            try {
                $results = $rows ?? ($rows = $this->storageSearch($facetQuery));

                // A misspelled field, or a facet named after a field no
                // document has, would otherwise come back empty unexplained.
                if ($results !== [] && !$this->rowsHaveField($results, $field)) {
                    $this->logger->notice('Facet field has no value in any matching document', [
                        'facet' => $name,
                        'field' => $field,
                    ]);
                }

                if ($buckets !== null) {
                    $facets[$name] = $this->countFacetRanges($results, $field, $buckets);
                    continue;
                }

                $facetValues = [];
                foreach ($results as $result) {
                    // Extract value from content fields or metadata. A list
                    // (tags, categories) counts each of its values once.
                    $value = $result[$field] ?? ($result['metadata'][$field] ?? null);
                    $seen = [];
                    foreach (is_array($value) ? $value : [$value] as $item) {
                        if ($item === null || !is_scalar($item)) {
                            continue;
                        }
                        // A float as an array key would be cut to an int (4.5 to 4)
                        $key = is_float($item) ? (string)$item : $item;
                        if (isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;
                        if (!isset($facetValues[$key])) {
                            $facetValues[$key] = ['value' => $item, 'count' => 0];
                        }
                        $facetValues[$key]['count']++;
                    }
                }

                $facetResults = [];
                foreach ($facetValues as $key => $entry) {
                    if ($entry['count'] >= $this->config['facet_min_count']) {
                        $facetResults[] = [
                            'value' => is_float($entry['value']) ? $entry['value'] : $key,
                            'count' => $entry['count']
                        ];
                    }
                }

                usort($facetResults, function ($a, $b) {
                    return $b['count'] <=> $a['count'];
                });

                $facets[$name] = array_slice($facetResults, 0, $options['limit'] ?? 10);
            } catch (\Throwable $e) {
                // One facet that cannot be counted leaves the others and the
                // results standing
                $this->logger->warning('Failed to compute facet', [
                    'facet' => $name,
                    'field' => $field,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $facets;
    }

    /**
     * The buckets of a range facet, from its 'ranges' option: each range has
     * a numeric 'from' (inclusive) and/or 'to' (exclusive) and an optional
     * 'key' to label it. A range the engine cannot use is left out and logged.
     *
     * @param mixed $ranges
     * @return array<int, array{key: string, from: int|float|null, to: int|float|null}>
     */
    private function facetRangeBuckets(string $name, $ranges): array
    {
        if (!is_array($ranges) || $ranges === []) {
            $this->logger->warning('Range facet skipped: ranges must be a list of ranges', ['facet' => $name]);
            return [];
        }

        $buckets = [];
        foreach ($ranges as $range) {
            $from = is_array($range) ? ($range['from'] ?? null) : null;
            $to = is_array($range) ? ($range['to'] ?? null) : null;
            if (($from === null && $to === null)
                || ($from !== null && !is_numeric($from))
                || ($to !== null && !is_numeric($to))
            ) {
                $this->logger->warning('Facet range ignored: a range needs a numeric from, to, or both', [
                    'facet' => $name,
                    'range' => $range,
                ]);
                continue;
            }

            $from = $from === null ? null : $from + 0;
            $to = $to === null ? null : $to + 0;
            if (isset($range['key']) && is_scalar($range['key'])) {
                $key = (string)$range['key'];
            } elseif ($from === null) {
                $key = '< ' . $to;
            } elseif ($to === null) {
                $key = '>= ' . $from;
            } else {
                $key = $from . ' - ' . $to;
            }

            $buckets[] = ['key' => $key, 'from' => $from, 'to' => $to];
        }

        return $buckets;
    }

    private function rowsHaveField(array $rows, string $field): bool
    {
        foreach ($rows as $row) {
            if (($row[$field] ?? ($row['metadata'][$field] ?? null)) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count the rows whose numeric field value falls in each range bucket.
     * Buckets come back in the order they were given, empty ones included.
     * A row with a list of numbers counts once in each bucket one of them
     * falls in.
     */
    private function countFacetRanges(array $rows, string $field, array $buckets): array
    {
        $counts = array_fill(0, count($buckets), 0);

        foreach ($rows as $row) {
            $value = $row[$field] ?? ($row['metadata'][$field] ?? null);
            $numbers = [];
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (is_numeric($item)) {
                    $numbers[] = $item + 0;
                }
            }

            foreach ($buckets as $i => $bucket) {
                foreach ($numbers as $number) {
                    if (($bucket['from'] === null || $number >= $bucket['from'])
                        && ($bucket['to'] === null || $number < $bucket['to'])
                    ) {
                        $counts[$i]++;
                        break;
                    }
                }
            }
        }

        $facetResults = [];
        foreach ($buckets as $i => $bucket) {
            $facetResults[] = [
                'value' => $bucket['key'],
                'count' => $counts[$i],
                'from' => $bucket['from'],
                'to' => $bucket['to'],
            ];
        }

        return $facetResults;
    }

    /**
     * The aggregations a query asks for (SearchQuery::aggregate()), over every document it
     * matches: 'count' (how many numbers the field holds), 'sum', 'avg', 'min' and 'max', each as
     * ['value' => ...], and 'stats' with all five. A field is read as a facet's is; numbers and
     * numeric strings count, each number of a list once. With no value, avg, min and max are
     * null and sum is 0.
     */
    private function computeAggregations(SearchQuery $query, array $storageQuery): array
    {
        $requested = [];
        foreach ($query->getAggregations() as $name => $aggregation) {
            $type = strtolower((string)($aggregation['type'] ?? ''));
            $field = $aggregation['options']['field'] ?? null;
            $known = in_array($type, ['count', 'sum', 'avg', 'min', 'max', 'stats'], true);
            if (!$known || !is_string($field) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $field)) {
                $this->logger->warning(
                    'Aggregation skipped: it needs a type of count, sum, avg, min, max or stats and a field name',
                    ['aggregation' => $name]
                );
                continue;
            }
            $requested[$name] = [$type, $field];
        }
        if ($requested === []) {
            return [];
        }

        $fields = array_values(array_unique(array_column($requested, 1)));
        $stats = array_fill_keys($fields, ['count' => 0, 'sum' => 0, 'min' => null, 'max' => null]);
        $counted = ['query', 'filters', 'language', 'geoFilters', 'stem_query', 'fields'];
        $matched = array_intersect_key($storageQuery, array_flip($counted));
        try {
            foreach ($this->documentValues($matched, $fields) as $values) {
                foreach ($values as $field => $value) {
                    foreach (is_array($value) ? $value : [$value] as $item) {
                        if (!is_int($item) && !is_float($item) && !(is_string($item) && is_numeric($item))) {
                            continue;
                        }
                        $number = $item + 0;
                        $stat = &$stats[$field];
                        $stat['count']++;
                        $stat['sum'] += $number;
                        $stat['min'] = $stat['min'] === null ? $number : min($stat['min'], $number);
                        $stat['max'] = $stat['max'] === null ? $number : max($stat['max'], $number);
                        unset($stat);
                    }
                }
            }
        } catch (\Throwable $e) {
            // The results stand without their aggregations
            $this->logger->warning('Failed to compute aggregations', ['error' => $e->getMessage()]);

            return [];
        }

        $aggregations = [];
        foreach ($requested as $name => [$type, $field]) {
            $stat = $stats[$field];
            $stat['avg'] = $stat['count'] > 0 ? (float)$stat['sum'] / $stat['count'] : null;
            $aggregations[$name] = $type === 'stats'
                ? [
                    'count' => $stat['count'],
                    'sum' => $stat['sum'],
                    'avg' => $stat['avg'],
                    'min' => $stat['min'],
                    'max' => $stat['max'],
                ]
                : ['value' => $stat[$type]];
        }

        return $aggregations;
    }

    /**
     * The values of some fields in every document a storage query matches (see
     * ProvidesFieldValues), read from the results of a search of up to max_results documents
     * when the storage cannot read them on their own
     *
     * @param string[] $fields
     * @return iterable<array<string, mixed>>
     */
    private function documentValues(array $storageQuery, array $fields): iterable
    {
        if ($this->storage instanceof ProvidesFieldValues) {
            return $this->storage->fieldValues($this->indexName, $storageQuery, $fields);
        }

        $documents = [];
        $limit = (int)($this->config['max_results'] ?? 1000);
        foreach ($this->storageSearch(['limit' => $limit, 'offset' => 0] + $storageQuery) as $result) {
            $values = [];
            foreach ($fields as $field) {
                if (strpos($field, 'metadata.') === 0) {
                    $values[$field] = $result['metadata'][substr($field, 9)] ?? null;
                } elseif (strpos($field, 'content.') === 0) {
                    $values[$field] = $result[substr($field, 8)] ?? null;
                } else {
                    $values[$field] = $result[$field] ?? ($result['metadata'][$field] ?? null);
                }
            }
            $documents[] = $values;
        }

        return $documents;
    }

    private function generateFuzzyVariations(string $term): array
    {
        // Check if we should use specific fuzzy matching algorithm
        $fuzzyAlgorithm = $this->config['fuzzy_algorithm'] ?? 'basic';

        switch ($fuzzyAlgorithm) {
            case 'levenshtein':
                return $this->generateLevenshteinVariations($term);
            case 'jaro_winkler':
                return $this->generateJaroWinklerVariations($term);
            case 'trigram':
                return $this->generateTrigramVariations($term);
            case 'basic':
            default:
                // Fall back to original basic implementation
                $variations = [];
                $length = strlen($term);

                if ($length <= 3) {
                    return [];
                }

                for ($i = 0; $i < $length; $i++) {
                    if ($i > 0) {
                        $variation = substr($term, 0, $i) . '*' . substr($term, $i + 1);
                        $variations[] = $variation;
                    }

                    $variation = substr($term, 0, $i) . substr($term, $i + 1);
                    if (strlen($variation) >= 3) {
                        $variations[] = $variation;
                    }
                }

                for ($i = 0; $i < $length - 1; $i++) {
                    $variation = substr($term, 0, $i) . $term[$i + 1] . $term[$i] . substr($term, $i + 2);
                    $variations[] = $variation;
                }

                return array_unique($variations);
        }
    }

    /**
     * The indexed terms fuzzy matching picks from, at most max_indexed_terms of them, the most
     * frequent first: 'freq' holds the frequency of each term, and 'length', 'first2' and
     * 'last2' the terms by their length and by their first and last two characters, keyed by
     * that order, so a lookup reads only the terms it can match. Read from the storage once per
     * indexed_terms_cache_ttl, and again after the index changes or a search gives other limits.
     *
     * @return array{freq: array<string, int>, length: array<int, array<int, string>>,
     *               first2: array<string, array<int, string>>, last2: array<string, array<int, string>>}
     */
    private function lexicon(): array
    {
        $key = [
            $this->indexChangeToken(),
            (int)($this->config['min_term_frequency'] ?? 1),
            (int)($this->config['max_indexed_terms'] ?? 20000),
        ];
        $ttl = $this->config['indexed_terms_cache_ttl'] ?? 300;
        if ($this->lexicon === null || $key !== $this->lexiconKey || (time() - $this->lexiconTime) > $ttl) {
            $terms = $this->storage->getIndexedTerms($this->indexName, $key[1], $key[2]);
            $lexicon = ['freq' => [], 'length' => [], 'first2' => [], 'last2' => []];
            $rank = 0;
            foreach ($terms as $term => $frequency) {
                $term = mb_strtolower((string)$term);
                if (!isset($lexicon['freq'][$term])) {
                    $lexicon['freq'][$term] = (int)$frequency;
                    $lexicon['length'][mb_strlen($term)][$rank] = $term;
                    $lexicon['first2'][mb_substr($term, 0, 2)][$rank] = $term;
                    $lexicon['last2'][mb_substr($term, -2)][$rank++] = $term;
                }
            }
            $this->lexicon = $lexicon;
            $this->termLookup = [];
            $this->matchCounts = [];
            $this->lexiconTime = time();
            $this->lexiconKey = $key;
        }

        return $this->lexicon;
    }

    /**
     * The lexicon's terms of $min to $max characters, the most frequent first
     *
     * @param array<int, string>|null $from Terms of the lexicon keyed by their order; null for all
     * @return string[]
     */
    private function termsOfLength(int $min, int $max, ?array $from = null): array
    {
        $terms = [];
        if ($from === null) {
            $byLength = $this->lexicon()['length'];
            for ($length = max(1, $min); $length <= $max; $length++) {
                $terms += $byLength[$length] ?? [];
            }
        } else {
            foreach ($from as $rank => $term) {
                $length = mb_strlen($term);
                if ($length >= $min && $length <= $max) {
                    $terms[$rank] = $term;
                }
            }
        }
        ksort($terms);

        return $terms;
    }

    private function generateLevenshteinVariations(string $term): array
    {
        $variations = [$term]; // Always include the original term

        // Skip short terms
        $termLen = mb_strlen($term);
        if ($termLen <= 3) {
            return $variations;
        }

        $threshold = $this->config['levenshtein_threshold'] ?? 2;
        $maxVariations = $this->config['max_fuzzy_variations'] ?? 10;

        try {
            $termLower = mb_strtolower($term);
            $termBigrams = $this->bigrams($termLower);
            $candidateTerms = [];

            foreach ($this->termsOfLength($termLen - $threshold, $termLen + $threshold) as $indexedTerm) {
                if (strcasecmp($term, $indexedTerm) === 0) {
                    continue;
                }

                // Skip terms whose first and last characters both differ, and terms that share
                // no bigram with the term, before measuring the distance
                if (
                    $threshold <= 2
                    && $termLower[0] !== $indexedTerm[0]
                    && $termLower[-1] !== $indexedTerm[-1]
                ) {
                    continue;
                }
                if (
                    mb_strlen($indexedTerm) >= 4
                    && empty(array_intersect_key($termBigrams, $this->bigrams($indexedTerm)))
                ) {
                    continue;
                }

                $distance = Levenshtein::distance($term, $indexedTerm);
                if ($distance <= $threshold) {
                    $candidateTerms[] = [
                        'term' => $indexedTerm,
                        'distance' => $distance,
                        'similarity' => 1.0 - $distance / max($termLen, mb_strlen($indexedTerm)),
                    ];
                }
            }

            // Sort by distance (ascending) and similarity (descending)
            usort($candidateTerms, function ($a, $b) {
                return [$a['distance'], $b['similarity']] <=> [$b['distance'], $a['similarity']];
            });

            foreach (array_slice($candidateTerms, 0, $maxVariations) as $candidate) {
                $variations[] = $candidate['term'];
            }
        } catch (\Exception $e) {
            // If there's an error, log it and return just the original term
            $this->logger->error('Failed to generate Levenshtein variations', [
                'term' => $term,
                'error' => $e->getMessage()
            ]);
        }

        return array_unique($variations);
    }

    /**
     * The byte pairs of a string, as keys
     *
     * @return array<string, true>
     */
    private function bigrams(string $text): array
    {
        $bigrams = [];
        for ($i = 0, $n = strlen($text) - 1; $i < $n; $i++) {
            $bigrams[substr($text, $i, 2)] = true;
        }

        return $bigrams;
    }

    private function generateJaroWinklerVariations(string $term): array
    {
        $variations = [$term]; // Always include the original term

        // Skip short terms
        if (mb_strlen($term) <= 3) {
            return $variations;
        }

        try {
            $matches = JaroWinkler::findBestMatches(
                $term,
                array_keys($this->lexicon()['freq']),
                $this->config['jaro_winkler_threshold'] ?? 0.85,
                $this->config['max_fuzzy_variations'] ?? 10,
                $this->config['jaro_winkler_prefix_scale'] ?? 0.1
            );
            foreach ($matches as $match) {
                $variations[] = $match[0]; // match is [term, score]
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate Jaro-Winkler variations', [
                'term' => $term,
                'error' => $e->getMessage()
            ]);
        }

        return array_unique($variations);
    }

    /**
     * Try to merge adjacent tokens if the merged form exists in the index
     * E.g., ["robo", "cop"] -> ["robocop"] if "robocop" is indexed
     */
    private function tryMergeTokens(array $tokens): array
    {
        if (count($tokens) < 2) {
            return $tokens;
        }

        $freq = $this->lexicon()['freq'];
        $result = [];
        $i = 0;
        $count = count($tokens);

        while ($i < $count) {
            // Try merging current token with next token
            if ($i < $count - 1) {
                $merged = mb_strtolower($tokens[$i] . $tokens[$i + 1]);
                if (isset($freq[$merged])) {
                    $this->logger->debug('Merged adjacent tokens', [
                        'token1' => $tokens[$i],
                        'token2' => $tokens[$i + 1],
                        'merged' => $merged
                    ]);
                    $result[] = $merged;
                    $this->fuzzyTermMap[$merged] = [
                        'type' => 'merge',
                        'original' => $tokens[$i] . ' ' . $tokens[$i + 1]
                    ];
                    $i += 2; // Skip both tokens
                    continue;
                }
            }

            $result[] = $tokens[$i];
            $i++;
        }

        return $result;
    }

    /**
     * The most likely indexed term one edit away from the term: the one with the highest
     * frequency times editLikelihood(), with its frequency, trigram similarity and score, for
     * comparison with a compound split
     *
     * @return array{term: string, frequency: int, similarity: float, score: float}|null
     */
    private function findQuickFuzzyMatchWithScore(string $term): ?array
    {
        $candidates = $this->oneEditCandidates(mb_strtolower($term));
        if ($candidates === []) {
            return null;
        }
        $best = (string)array_key_first($candidates);

        return [
            'term' => $best,
            'frequency' => $this->wordFrequency($best),
            'similarity' => Trigram::similarity(mb_strtolower($term), $best),
            'score' => $candidates[$best],
        ];
    }

    /**
     * The indexed terms one edit from a word, with their frequency times editLikelihood(), the
     * likeliest first. They come from the lexicon and, when the storage can look terms up, from
     * the rarer terms that start like the word, those in min_term_frequency documents or more.
     *
     * @return array<string, float>
     */
    private function oneEditCandidates(string $word): array
    {
        $length = mb_strlen($word);
        if ($length < 2) {
            return [];
        }
        $lexicon = $this->lexicon();
        $minFrequency = (int)($this->config['min_term_frequency'] ?? 1);

        // One edit leaves the first two or the last two characters of a word of four or more
        $near = ($lexicon['first2'][mb_substr($word, 0, 2)] ?? []) + ($lexicon['last2'][mb_substr($word, -2)] ?? []);
        $frequencies = [];
        foreach ($this->termsOfLength($length - 1, $length + 1, $near) as $candidate) {
            $frequencies[$candidate] = $lexicon['freq'][$candidate];
        }
        foreach ($this->termsSharingStart($word) as $candidate => $frequency) {
            if (
                !isset($frequencies[$candidate]) && $frequency >= $minFrequency
                && abs(mb_strlen((string)$candidate) - $length) <= 1
            ) {
                $frequencies[(string)$candidate] = $frequency;
            }
        }

        $scores = $this->scoreOneEdit($word, $frequencies);
        // A slip in the second letter changes the first two: with no word found, the rarer words
        // that start with the first letter are looked through too
        if ($scores === [] && $length >= 5) {
            $frequencies = [];
            foreach ($this->termsSharingStart($word, 1) as $candidate => $frequency) {
                if ($frequency >= $minFrequency && abs(mb_strlen((string)$candidate) - $length) <= 1) {
                    $frequencies[(string)$candidate] = $frequency;
                }
            }
            $scores = $this->scoreOneEdit($word, $frequencies);
        }

        return $scores;
    }

    /**
     * Candidates one edit from a word, with their frequency times editLikelihood(), the likeliest
     * first, leaving out stop words
     *
     * @param array<string, int> $frequencies Candidates with their frequencies
     * @return array<string, float>
     */
    private function scoreOneEdit(string $word, array $frequencies): array
    {
        $ascii = !preg_match('/[\x80-\xFF]/', $word);
        $scores = [];
        foreach ($frequencies as $candidate => $frequency) {
            $candidate = (string)$candidate;
            // A swap of two letters is two edits for Levenshtein, one here
            $distance = $ascii && !preg_match('/[\x80-\xFF]/', $candidate)
                ? levenshtein($word, $candidate)
                : Levenshtein::distance($word, $candidate);
            if ($distance > 2) {
                continue;
            }
            $likelihood = self::editLikelihood($word, $candidate);
            if ($likelihood > 0) {
                $scores[$candidate] = $frequency * $likelihood;
            }
        }
        // A stop word is no correction: 'herd' is not 'her'
        $kept = $this->analyzer->removeStopWords(array_map('strval', array_keys($scores)), $this->correctionLanguage);
        $scores = array_intersect_key($scores, array_flip($kept));
        arsort($scores);

        return $scores;
    }

    /**
     * How likely $typed is a slip for $candidate when it is one edit from it: 1 for two
     * neighbouring letters swapped, a letter left out, a letter typed twice or next to a key
     * beside it, and a letter replaced by a key beside it or by another vowel; 0.3 for any other
     * one-letter change; 0 when it is not one edit away (or is the same word)
     */
    private static function editLikelihood(string $typed, string $candidate): float
    {
        $a = preg_match('/[\x80-\xFF]/', $typed) ? mb_str_split($typed) : str_split($typed);
        $b = preg_match('/[\x80-\xFF]/', $candidate) ? mb_str_split($candidate) : str_split($candidate);
        $typedLength = count($a);
        $candidateLength = count($b);
        $i = 0;
        while ($i < $typedLength && $i < $candidateLength && $a[$i] === $b[$i]) {
            $i++;
        }

        if ($typedLength === $candidateLength) {
            if ($i === $typedLength) {
                return 0.0;
            }
            if (array_slice($a, $i + 1) === array_slice($b, $i + 1)) {
                $vowels = 'aeiouy';
                $likely = KeyboardProximity::isAdjacentTypo($a[$i], $b[$i])
                    || (strpos($vowels, $a[$i]) !== false && strpos($vowels, $b[$i]) !== false);

                return $likely ? 1.0 : 0.3;
            }
            $swapped = $i + 1 < $typedLength && $a[$i] === $b[$i + 1] && $a[$i + 1] === $b[$i]
                && array_slice($a, $i + 2) === array_slice($b, $i + 2);

            return $swapped ? 1.0 : 0.0;
        }

        if ($typedLength === $candidateLength + 1 && array_slice($a, $i + 1) === array_slice($b, $i)) {
            // A letter too many: likely when it doubles a neighbour or is a key beside one
            foreach ([$a[$i - 1] ?? null, $a[$i + 1] ?? null] as $neighbour) {
                $doubled = $neighbour === $a[$i];
                if ($neighbour !== null && ($doubled || KeyboardProximity::isAdjacentTypo($a[$i], $neighbour))) {
                    return 1.0;
                }
            }

            return 0.3;
        }

        // A letter left out
        return $typedLength + 1 === $candidateLength && array_slice($a, $i) === array_slice($b, $i + 1) ? 1.0 : 0.0;
    }

    /**
     * The corrected words of a query, read again where two neighbours are near each other in
     * no document: of the readings of the two (each word as typed when it is in the index, its
     * correction, and the likeliest words one and two edits from it as typed), the pair with the
     * most documents where they are near, weighted by how likely each reading is. A word of three
     * characters or less, a known misspelling's correction, a split into two words, and a last
     * word searched as a prefix are kept.
     *
     * @param string[] $typed The words as typed
     * @param string[] $chosen Their corrections, by position
     * @return string[]
     */
    private function correctInContext(array $typed, array $chosen, bool $prefixLast): array
    {
        $typed = array_values($typed);
        $chosen = array_values($chosen);
        $last = count($chosen) - 1;
        // A known misspelling ('fone' is 'phone') is not read again
        $fixed = function (int $i) use (&$chosen, $typed, $last, $prefixLast): bool {
            return ($prefixLast && $i === $last) || strpos($chosen[$i], ' ') !== false
                || PhoneticMatcher::quickPhoneticCorrection($typed[$i]) !== null;
        };
        // A short word that is in no document says nothing about its neighbours, which are
        // read as neighbours of each other
        $positions = [];
        foreach ($chosen as $i => $word) {
            if (mb_strlen($word) > 3 || $this->wordFrequency(mb_strtolower($word)) > 0) {
                $positions[] = $i;
            }
        }

        for ($k = 0; $k + 1 < count($positions); $k++) {
            [$i, $j] = [$positions[$k], $positions[$k + 1]];
            if ($fixed($i) || $fixed($j) || $this->nearCount($chosen[$i], $chosen[$j]) > 0) {
                continue;
            }
            // One of the two read again first, the other as it is; then both
            $lefts = $this->readings($typed[$i], $chosen[$i]);
            $rights = $this->readings($typed[$j], $chosen[$j]);
            $best = null;
            $passes = [[$lefts, [$chosen[$j] => 1.0]], [[$chosen[$i] => 1.0], $rights], [$lefts, $rights]];
            foreach ($passes as [$leftReadings, $rightReadings]) {
                foreach ($leftReadings as $left => $leftWeight) {
                    foreach ($rightReadings as $right => $rightWeight) {
                        $score = $this->nearCount((string)$left, (string)$right) * $leftWeight * $rightWeight;
                        if ($score > 0 && ($best === null || $score > $best[2])) {
                            $best = [(string)$left, (string)$right, $score];
                        }
                    }
                }
                if ($best !== null) {
                    [$chosen[$i], $chosen[$j]] = $best;
                    break;
                }
            }
        }

        return $chosen;
    }

    /**
     * What a word may have been meant as, with how likely each reading is: the word as typed
     * when it is in the index (1), its correction (0.5), the likeliest three words one edit from
     * it (0.1 for a likely slip) and, for a word not in the lexicon, the six commonest words two
     * edits from it (0.01)
     *
     * @return array<string, float>
     */
    private function readings(string $typed, string $chosen): array
    {
        $word = mb_strtolower($typed);
        $readings = [$chosen => 0.5];
        if (mb_strlen($word) <= 3) {
            return [$chosen => 1.0];
        }
        $inIndex = $this->wordFrequency($word) > 0;
        if ($inIndex) {
            $readings[$word] = 1.0;
        }
        foreach (array_slice($this->oneEditCandidates($word), 0, 3, true) as $candidate => $score) {
            $likelihood = min(1.0, $score / max(1, $this->wordFrequency((string)$candidate)));
            $readings[(string)$candidate] = max($readings[(string)$candidate] ?? 0, 0.1 * $likelihood);
        }
        // Two slips in a word of the lexicon are too unlikely to consider
        foreach (isset($this->lexicon()['freq'][$word]) ? [] : $this->twoEditCandidates($word, 6) as $candidate) {
            $readings[$candidate] = $readings[$candidate] ?? 0.01;
        }

        return $readings;
    }

    /**
     * The commonest words of the lexicon two edits from a word that start with the same letter
     *
     * @return string[]
     */
    private function twoEditCandidates(string $word, int $limit): array
    {
        $length = mb_strlen($word);
        $ascii = !preg_match('/[\x80-\xFF]/', $word);
        $first = mb_substr($word, 0, 1);
        $found = [];
        foreach ($this->termsOfLength($length - 2, $length + 2) as $term) {
            if (strncmp($term, $first, strlen($first)) !== 0 || $term === $word) {
                continue;
            }
            $distance = $ascii && !preg_match('/[\x80-\xFF]/', $term)
                ? levenshtein($word, $term)
                : Levenshtein::distance($word, $term);
            if ($distance === 2) {
                $found[] = $term;
            }
        }
        $found = $this->analyzer->removeStopWords($found, $this->correctionLanguage);

        return array_slice($found, 0, $limit);
    }

    /**
     * How many documents have two words near each other (within three words, either order)
     */
    private function nearCount(string $left, string $right): int
    {
        $escaped = Fts5Escaper::escapeTokens([$left, $right]);

        return count($escaped) === 2 ? $this->matchCount('NEAR(' . implode(' ', $escaped) . ', 3)') : 0;
    }

    /**
     * How many documents match an FTS expression, remembered until the lexicon is read again
     */
    private function matchCount(string $expression): int
    {
        $this->lexicon();

        return $this->matchCounts[$expression] ??= $this->storage->count($this->indexName, ['query' => $expression]);
    }

    /**
     * What else a rare word of the index, one below the lexicon, may have been meant as: a word
     * one likely slip away in KEEP_TYPED_RATIO times as many documents ('supermen' is in two,
     * 'superman' in many), else two words run together that stand side by side SPLIT_RATIO
     * times as often ('spiderman', 'spider man'). It is searched beside the word, which keeps
     * the documents that do have the word ('the giver' is not only 'give').
     */
    private function alternativeReading(string $word): ?string
    {
        $word = mb_strtolower($word);
        $frequency = $this->wordFrequency($word);
        if ($frequency === 0 || mb_strlen($word) < 4 || isset($this->lexicon()['freq'][$word])) {
            return null;
        }
        $nearest = $this->findQuickFuzzyMatchWithScore($word);
        if ($nearest !== null && $nearest['score'] >= self::KEEP_TYPED_RATIO * $frequency) {
            return $nearest['term'];
        }
        $split = ($this->config['enable_compound_word_split'] ?? true) ? $this->tryCompoundWordSplit($word) : null;

        return $split !== null && $this->matchCount(self::phrase($split)) >= self::SPLIT_RATIO * $frequency
            ? $split
            : null;
    }

    /**
     * A phrase of words for an FTS expression
     */
    private static function phrase(string $words): string
    {
        return '"' . implode(' ', Fts5Escaper::escapeTokens(explode(' ', $words), true)) . '"';
    }

    /**
     * Whether a word reads as the beginning of longer words more than as a word of its own: it
     * has four characters or more, is not a word of the lexicon, and begins an indexed word in
     * more documents than it is in itself ('termin', 'knigh', 'incept'; not 'wars', 'angel')
     */
    private function readsAsPrefix(string $word): bool
    {
        $word = mb_strtolower($word);
        $length = strlen($word);
        $lexicon = $this->lexicon();
        if (
            mb_strlen($word) < 4
            || isset($lexicon['freq'][$word])
            || !($this->config['enable_prefix_matching'] ?? true)
        ) {
            return false;
        }

        $longer = $this->termsSharingStart($word);
        foreach ($lexicon['first2'][mb_substr($word, 0, 2)] ?? [] as $term) {
            $longer[$term] = $lexicon['freq'][$term];
        }
        $own = $this->wordFrequency($word);
        foreach ($longer as $term => $frequency) {
            $term = (string)$term;
            if ($frequency > $own && strlen($term) > $length && strncmp($term, $word, $length) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many documents a word is in: from the lexicon, else from the storage's terms
     */
    private function wordFrequency(string $word): int
    {
        return $this->lexicon()['freq'][$word] ?? ($this->termsSharingStart($word)[$word] ?? 0);
    }

    /**
     * The indexed terms that start with the first two (or $characters) characters of a word, with
     * their frequencies, as the storage looks them up; none when it cannot. Kept with the lexicon.
     *
     * @return array<string, int>
     */
    private function termsSharingStart(string $word, int $characters = 2): array
    {
        $start = mb_substr($word, 0, $characters);
        if (mb_strlen($start) < $characters || !$this->storage instanceof ProvidesTermLookup) {
            return [];
        }
        $this->lexicon();

        return $this->termLookup[$start] ??= $this->storage->termsStartingWith($this->indexName, $start, 20000);
    }

    /**
     * Try to split a compound word into valid indexed terms
     * Returns the split version if found, null otherwise
     */
    private function tryCompoundWordSplit(string $term): ?string
    {
        $termLower = mb_strtolower($term);
        $termLen = mb_strlen($termLower);

        // Only try splits for words of reasonable length
        if ($termLen < 6 || $termLen > 20) {
            return null;
        }

        $freq = $this->lexicon()['freq'];
        $bestSplit = null;
        $bestScore = 0;
        $minPartLength = 3;
        $minPartFrequency = 5; // Require both parts to have reasonable frequency

        for ($i = $minPartLength; $i <= $termLen - $minPartLength; $i++) {
            $part1 = mb_substr($termLower, 0, $i);
            $part2 = mb_substr($termLower, $i);
            $freq1 = $freq[$part1] ?? 0;
            $freq2 = $freq[$part2] ?? 0;

            // Skip if either part is too rare (likely false positive)
            if ($freq1 < $minPartFrequency || $freq2 < $minPartFrequency) {
                continue;
            }

            // Both parts are valid terms - prefer higher combined frequency
            $score = log($freq1 + 1) + log($freq2 + 1);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestSplit = $part1 . ' ' . $part2;
            }
        }

        return $bestSplit;
    }

    /**
     * Find the single best correction for a potentially misspelled term
     * Uses multi-algorithm consensus for improved accuracy
     * Returns the original term if no good correction is found
     */
    /**
     * @param bool $complete Whether a word cut short may be completed to the word it begins
     */
    private function findBestCorrection(string $term, bool $complete = true): string
    {
        if (!$this->config['enable_fuzzy']) {
            return $term;
        }

        // Common typos first, which include words of three letters ("teh" is "the"). One that is
        // also a word of the index is read as the typo only when what it stands for is in
        // KEEP_TYPED_RATIO times as many documents: 'were' is no slip of 'where', nor a brand
        // named Fone one of 'phone'
        $known = PhoneticMatcher::quickPhoneticCorrection($term);
        if ($known !== null) {
            $typed = $this->wordFrequency(mb_strtolower($term));
            if ($typed === 0 || $this->matchCount(self::phrase($known)) >= self::KEEP_TYPED_RATIO * $typed) {
                return $known;
            }
        }

        // Other short words are not corrected
        $termLen = mb_strlen($term);
        if ($termLen <= 3) {
            return $term;
        }

        try {
            $freq = $this->lexicon()['freq'];

            // A word that is in the index is kept, proper nouns and domain terms included (the
            // words around it may still correct it, see correctInContext(), and a rare one is
            // searched beside a commoner reading, see alternativeReading())
            if ($this->wordFrequency(mb_strtolower($term)) > 0) {
                return $term;
            }

            // The beginning of a word, unless a word one slip away is the likelier reading
            $quickFuzzyMatch = $this->findQuickFuzzyMatchWithScore($term);
            if ($complete && ($this->config['enable_prefix_matching'] ?? true)) {
                $prefixMatch = $this->findBestPrefixMatch($term);
                if (
                    $prefixMatch !== null
                    && (
                        $quickFuzzyMatch === null
                        || $freq[$prefixMatch] * self::PREFIX_WEIGHT >= $quickFuzzyMatch['score']
                    )
                ) {
                    return $prefixMatch;
                }
            }

            // Try BOTH quick fuzzy match AND compound split, then compare scores
            // This allows choosing the best interpretation:
            // - "batmen" → "batman" (fuzzy wins: a likely slip of a common word)
            // - "madmax" → "mad max" (compound wins: exact split into valid words)
            $compoundSplit = ($this->config['enable_compound_word_split'] ?? true)
                ? $this->tryCompoundWordSplit($term)
                : null;

            if ($quickFuzzyMatch !== null && $compoundSplit !== null) {
                $fuzzyMatch = $quickFuzzyMatch['term'];
                $fuzzyFreq = $quickFuzzyMatch['frequency'];
                $partFreqs = [];
                foreach (explode(' ', $compoundSplit) as $part) {
                    $partFreqs[] = $freq[$part] ?? 0;
                }
                $minPartFreq = min($partFreqs);
                $maxPartFreq = max($partFreqs);

                // Check if compound split is "balanced" - both parts have similar frequencies
                // Unbalanced splits like "scare face" (35 vs 694) suggest false positive
                // Balanced splits like "mad max" (130 vs 161) are likely real compounds
                $isBalancedCompound = $maxPartFreq > 0 && $minPartFreq / $maxPartFreq >= 0.15;

                // Check if this is a pure substitution with high similarity
                // Pure substitutions like "supermen" → "superman" (sim=0.538) should prefer fuzzy
                // But "madmax" → "madman" (sim=0.455) should allow compound "mad max"
                $isHighSimilaritySubstitution = $termLen === mb_strlen($fuzzyMatch)
                    && $quickFuzzyMatch['similarity'] >= 0.5;

                // Key insights:
                // 1. If fuzzy match is MORE common than the rarest compound part,
                //    it's likely the correct single term (typo correction)
                //    e.g., "batmen" → "batman" (55) > "bat" (24)
                // 2. If high-similarity same-length substitution, prefer fuzzy
                //    e.g., "supermen" → "superman" (sim=0.538) over "super men"
                //    but NOT "madmax" → "madman" (sim=0.455), allow "mad max"
                // 3. If compound is unbalanced (one very common word like "face", "men"),
                //    prefer fuzzy match as the compound is likely spurious
                //    e.g., "scareface" → "scarface" vs "scare face" (35 vs 694)
                // 4. If compound is balanced AND fuzzy match is less common,
                //    prefer compound split (words run together)
                //    e.g., "madmax" → "mad max" (130 vs 161)
                $preferFuzzy = $fuzzyFreq > $minPartFreq
                    || ($fuzzyFreq > 0 && ($isHighSimilaritySubstitution || !$isBalancedCompound));

                return $preferFuzzy ? $fuzzyMatch : $compoundSplit;
            }
            if ($quickFuzzyMatch !== null) {
                return $quickFuzzyMatch['term'];
            }
            if ($compoundSplit !== null) {
                return $compoundSplit;
            }

            // Otherwise the candidate most algorithms agree on: the highest consensus score, then
            // the highest score weighted by frequency, then the most frequent term
            $minConsensus = max(0.65, ($this->config['correction_threshold'] ?? 0.6) * 0.7);
            $best = null;
            foreach ($this->termsOfLength($termLen - 2, $termLen + 2) as $indexedTerm) {
                // A term more than two edits away that keeps less than 45% of the word is not taken
                // as a correction. This is a rule, not a bound: such a term could reach the
                // consensus, but it is a different word, not a typo of this one.
                $distance = Levenshtein::distance($term, $indexedTerm);
                $levenshtein = 1 - $distance / max($termLen, mb_strlen($indexedTerm));
                if ($distance > 2 && $levenshtein < 0.45) {
                    continue;
                }
                $scores = [
                    'trigram' => 1.0,
                    'levenshtein' => $levenshtein,
                    'jaro_winkler' => 1.0,
                    'phonetic' => PhoneticMatcher::phoneticSimilarity($term, $indexedTerm),
                    'keyboard' => KeyboardProximity::proximityScore($term, $indexedTerm),
                ];
                // The trigram and Jaro-Winkler scores cost the most, and are measured only for a
                // candidate that could still win with both at 1, their highest
                $needed = max($minConsensus, $best[1] ?? 0.0);
                if ($this->calculateConsensusScore($scores) < $needed) {
                    continue;
                }
                $scores['trigram'] = Trigram::similarity($term, $indexedTerm);
                $scores['jaro_winkler'] = JaroWinkler::similarity($term, $indexedTerm);
                $consensus = $this->calculateConsensusScore($scores);
                if ($consensus < $needed) {
                    continue;
                }

                $weighted = $consensus * $this->calculateFrequencyWeight($freq[$indexedTerm]);
                if ($weighted >= 0.7 && ($best === null || $consensus > $best[1] || $weighted > $best[2])) {
                    $best = [$indexedTerm, $consensus, $weighted];
                }
            }

            if ($best !== null) {
                return $best[0];
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to find enhanced correction', [
                'term' => $term,
                'error' => $e->getMessage()
            ]);
        }

        // No good correction found, return original
        return $term;
    }

    /**
     * Find the best prefix match for a term
     * Returns a term that starts with the input if found
     */
    private function findBestPrefixMatch(string $term): ?string
    {
        $termLower = mb_strtolower($term);
        $termLen = mb_strlen($termLower);

        // Only match prefixes for terms of reasonable length
        if ($termLen < 4 || $termLen > 10) {
            return null;
        }

        // Indexed terms 1 to 5 characters longer that start with the term
        $lexicon = $this->lexicon();
        $matches = [];
        $sameStart = $lexicon['first2'][mb_substr($termLower, 0, 2)] ?? [];
        foreach ($this->termsOfLength($termLen + 1, $termLen + 5, $sameStart) as $indexedTerm) {
            if (strncmp($indexedTerm, $termLower, strlen($termLower)) === 0) {
                $matches[] = [
                    'term' => $indexedTerm,
                    'frequency' => $lexicon['freq'][$indexedTerm],
                    'extra_len' => mb_strlen($indexedTerm) - $termLen,
                ];
            }
        }

        if (empty($matches)) {
            return null;
        }

        // Language-agnostic sorting with frequency consideration
        // If a longer match has significantly higher frequency, prefer it
        usort($matches, function ($a, $b) {
            // Calculate a score that considers both length and frequency
            // Prefer shorter extensions unless longer one has much higher frequency
            $aScore = $a['frequency'] / (1.0 + $a['extra_len'] * 0.5);
            $bScore = $b['frequency'] / (1.0 + $b['extra_len'] * 0.5);

            // If scores are close, prefer shorter extension
            if (abs($aScore - $bScore) < max($aScore, $bScore) * 0.3) {
                return $a['extra_len'] <=> $b['extra_len'];
            }

            return $bScore <=> $aScore;
        });

        return $matches[0]['term'];
    }

    /**
     * The weighted mean of the algorithms' positive scores, raised by a tenth when two or more
     * of them reach 0.8; 0 when fewer than two are positive, so no one algorithm (like
     * phonetic) decides alone
     *
     * @param array<string, float> $scores By algorithm
     */
    private function calculateConsensusScore(array $scores): float
    {
        $weightedScore = 0.0;
        $totalWeight = 0.0;
        $positive = 0;
        $high = 0;

        foreach ($scores as $algorithm => $score) {
            if ($score >= 0.8) {
                $high++;
            }
            if ($score > 0 && isset(self::CONSENSUS_WEIGHTS[$algorithm])) {
                $weightedScore += $score * self::CONSENSUS_WEIGHTS[$algorithm];
                $totalWeight += self::CONSENSUS_WEIGHTS[$algorithm];
                $positive++;
            }
        }

        if ($positive < 2) {
            return 0.0;
        }

        $consensusScore = $weightedScore / $totalWeight * ($high >= 2 ? 1.1 : 1.0);

        return min(1.0, $consensusScore);
    }

    /**
     * More frequent terms are more likely corrections; the weight is capped at 3
     */
    private function calculateFrequencyWeight(int $frequency): float
    {
        return min(1.0 + log(1 + $frequency) / 5.0, 3.0);
    }

    private function generateTrigramVariations(string $term): array
    {
        $variations = [$term]; // Always include the original term

        // Skip short terms
        $termLen = mb_strlen($term);
        if ($termLen <= 3) {
            return $variations;
        }

        try {
            $matches = Trigram::findBestMatches(
                $term,
                array_keys($this->lexicon()['freq']),
                $this->config['trigram_threshold'] ?? 0.3,
                $this->config['max_fuzzy_variations'] ?? 10,
                // Adaptive n-gram for short tokens
                $termLen <= 4 ? 2 : ($this->config['trigram_size'] ?? 3)
            );
            foreach ($matches as $match) {
                $variations[] = $match[0]; // match is [term, score]
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate Trigram variations', [
                'term' => $term,
                'error' => $e->getMessage()
            ]);
        }

        return array_unique($variations);
    }

    private function expandSynonyms(array $tokens, ?string $language = null): array
    {
        // Read again when a search or updateConfig() gives other synonyms
        $source = $this->config['synonyms'] ?? [];
        if ($this->synonymsCache === null || $source !== $this->synonymsSource) {
            $this->synonymsSource = $source;
            $map = $source;
            // A path with a NUL byte is none (file_exists() throws on it from PHP 8.6)
            if (is_string($map) && strpos($map, "\0") === false && file_exists($map)) {
                try {
                    $json = file_get_contents($map);
                    $decoded = json_decode($json, true);
                    if (is_array($decoded)) {
                        $map = $decoded;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to load synonyms file', ['file' => $map, 'error' => $e->getMessage()]);
                    $map = [];
                }
            }
            $this->synonymsCache = is_array($map) ? $map : [];
        }

        $map = $this->synonymsCache;
        if ($language && isset($map[$language]) && is_array($map[$language])) {
            $map = $map[$language];
        }
        if (!is_array($map) || empty($map)) {
            return $tokens;
        }
        $caseSensitive = (bool)($this->config['synonyms_case_sensitive'] ?? false);
        $perTermMax = (int)($this->config['synonyms_max_expansions'] ?? 3);
        $totalCap = max(5, $perTermMax * 10);

        $expanded = $tokens;
        $totalAdded = 0;
        foreach ($tokens as $t) {
            $key = $caseSensitive ? $t : mb_strtolower($t);
            $syns = $map[$key] ?? null;
            if (!is_array($syns) || empty($syns)) {
                continue;
            }
            $addedForTerm = 0;
            foreach ($syns as $s) {
                if ($addedForTerm >= $perTermMax || $totalAdded >= $totalCap) {
                    break;
                }
                $s = (string)$s;
                $tokenToAdd = (strpos($s, ' ') !== false) ? '"' . $s . '"' : $s;
                if (!in_array($tokenToAdd, $expanded, true)) {
                    $expanded[] = $tokenToAdd;
                    $addedForTerm++;
                    $totalAdded++;
                }
            }
            if ($totalAdded >= $totalCap) {
                break;
            }
        }
        return $expanded;
    }

    private function generateSuggestion(string $query): ?string
    {
        $tokens = $this->analyzer->tokenize($query);
        $suggestions = [];
        $corrections = [];
        $countMemo = [];

        foreach ($tokens as $token) {
            // First, try to find the best correction using our enhanced method
            $correction = $this->findBestCorrection($token);

            if ($correction !== $token) {
                // This token was corrected
                $corrections[] = $correction;
                continue;
            }

            // If no correction found, try fuzzy variations
            $fuzzyVariations = $this->generateFuzzyVariations($token);

            foreach ($fuzzyVariations as $variation) {
                $testQuery = new SearchQuery($variation);
                $testQuery->limit(1);
                $variationKey = $variation;
                if (!isset($countMemo[$variationKey])) {
                    $countMemo[$variationKey] = $this->count($testQuery) > 0;
                }
                if ($countMemo[$variationKey]) {
                    $suggestions[] = $variation;
                    break;
                }
            }
        }

        // Prefer corrections over fuzzy suggestions
        if (!empty($corrections)) {
            $suggestion = implode(' ', $corrections);

            // Verify that the corrected query actually has results
            $testQuery = new SearchQuery($suggestion);
            $testQuery->limit(1);
            if (!isset($countMemo[$suggestion])) {
                $countMemo[$suggestion] = $this->count($testQuery) > 0;
            }
            if ($countMemo[$suggestion]) {
                $this->logger->debug('Did you mean suggestion generated', [
                    'original' => $query,
                    'suggestion' => $suggestion,
                    'type' => 'correction'
                ]);
                return $suggestion;
            }
        }

        // Fall back to fuzzy suggestions
        if (!empty($suggestions)) {
            $suggestion = implode(' ', $suggestions);
            $this->logger->debug('Did you mean suggestion generated', [
                'original' => $query,
                'suggestion' => $suggestion,
                'type' => 'fuzzy'
            ]);
            return $suggestion;
        }

        return null;
    }

    /**
     * Generate multiple "did you mean" suggestions with confidence scores
     */
    public function generateSuggestions(string $query, int $maxSuggestions = 3): array
    {
        $tokens = $this->analyzer->tokenize($query);
        $allSuggestions = [];
        $countMemo = [];

        // Generate corrections for each token
        foreach ($tokens as $tokenIndex => $token) {
            $correction = $this->findBestCorrection($token);

            if ($correction !== $token) {
                // Create a corrected query
                $correctedTokens = $tokens;
                $correctedTokens[$tokenIndex] = $correction;
                $suggestion = implode(' ', $correctedTokens);

                // Calculate confidence
                $confidence = $this->calculateCorrectionConfidence($token, $correction);

                $allSuggestions[] = [
                    'text' => $suggestion,
                    'confidence' => $confidence,
                    'type' => 'correction',
                    'original_token' => $token,
                    'correction' => $correction
                ];
            }
        }

        // Sort by confidence
        usort($allSuggestions, function ($a, $b) {
            return $b['confidence'] <=> $a['confidence'];
        });

        // Verify suggestions have results and limit
        $validSuggestions = [];
        foreach ($allSuggestions as $suggestion) {
            if (count($validSuggestions) >= $maxSuggestions) {
                break;
            }

            $testQuery = new SearchQuery($suggestion['text']);
            $testQuery->limit(1);
            $text = $suggestion['text'];
            if (!isset($countMemo[$text])) {
                $countMemo[$text] = $this->count($testQuery) > 0;
            }
            if ($countMemo[$text]) {
                $validSuggestions[] = $suggestion;
            }
        }

        return $validSuggestions;
    }

    /**
     * Calculate confidence score for a correction
     */
    private function calculateCorrectionConfidence(string $original, string $correction): float
    {
        // Quick phonetic match gets high confidence
        if (PhoneticMatcher::quickPhoneticCorrection($original) === $correction) {
            return 0.95;
        }

        // Calculate consensus score
        $scores = [
            'trigram' => Trigram::similarity($original, $correction),
            'levenshtein' => Levenshtein::similarity($original, $correction),
            'jaro_winkler' => JaroWinkler::similarity($original, $correction),
            'phonetic' => PhoneticMatcher::phoneticSimilarity($original, $correction),
            'keyboard' => KeyboardProximity::proximityScore($original, $correction)
        ];

        $consensusScore = $this->calculateConsensusScore($scores);

        // Boost for keyboard proximity typos (very common)
        if ($scores['keyboard'] >= 0.8) {
            $consensusScore *= 1.1;
        }

        // Boost for phonetic matches
        if ($scores['phonetic'] >= 0.8) {
            $consensusScore *= 1.05;
        }

        return min(1.0, $consensusScore);
    }

    /**
     * The key a search is held under, or null when it cannot be told from another search and
     * so is not cached at all: the options hold something that does not encode (an object
     * whose jsonSerialize() fails or returns NAN, INF or a resource, invalid UTF-8) or throws
     * while encoding. Searches that failed to encode would all share the key of an empty string.
     */
    private function getCacheKey(SearchQuery $query, array $options = []): ?string
    {
        try {
            $keyData = $query->toArray();
            // Include cache-relevant options that affect the results
            if (!empty($options['unique_by_route'])) {
                $keyData['_unique_by_route'] = true;
            }
            if (array_key_exists('semantic', $options)) {
                $keyData['_semantic'] = (bool)$options['semantic'];
            }
            if (isset($options['semantic_weight'])) {
                $keyData['_semantic_weight'] = (float)$options['semantic_weight'];
            }
            // search() merges the runtime options into the config, so they decide what is
            // returned as much as the query does (min_score, boosts, fuzzy settings, ...).
            // Sorted by name, so the same options in another order are the same search.
            if (!empty($options)) {
                $keyData['_options'] = $this->sortedByKey($options);
            }
            // The weight of a stem match is in the key as it applies, whether it comes from
            // the options or from the config
            $keyData['_stem_weight'] = (float)($this->config['stem_weight'] ?? 0.5);

            $encoded = json_encode($keyData);
        } catch (\Throwable $e) {
            return null;
        }

        return $encoded === false ? null : md5($encoded);
    }

    /**
     * An array with its string keys in order, at every depth. Lists keep the order they have.
     */
    private function sortedByKey(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->sortedByKey($value);
            }
        }
        if (array_keys($values) !== range(0, count($values) - 1)) {
            ksort($values);
        }

        return $values;
    }

    /**
     * The storage's token for this index, or null when the storage cannot
     * tell; then a result held in memory lasts its cache_ttl whatever changes.
     */
    private function indexChangeToken(): ?string
    {
        return $this->storage instanceof TracksIndexChanges
            ? $this->storage->indexChangeToken($this->indexName)
            : null;
    }

    private function isCached(?string $key, ?string $changeToken): bool
    {
        if ($key === null || !isset($this->cache[$key])) {
            return false;
        }

        $cached = $this->cache[$key];
        // A write since the result was cached makes it stale
        if ($cached['token'] !== $changeToken) {
            unset($this->cache[$key]);
            return false;
        }

        return (time() - $cached['time']) < $this->config['cache_ttl'];
    }

    private function cacheResults(?string $key, SearchResults $results, ?string $changeToken): void
    {
        // With no cache_ttl nothing would ever be read back
        if ($key === null || ($this->config['cache_ttl'] ?? 0) <= 0) {
            return;
        }

        // Entries are added in time order, so the first one is the oldest
        unset($this->cache[$key]);
        $this->cache[$key] = [
            'results' => $results,
            'time' => time(),
            'token' => $changeToken,
        ];
        if (count($this->cache) > 100) {
            unset($this->cache[array_key_first($this->cache)]);
        }
    }
}
