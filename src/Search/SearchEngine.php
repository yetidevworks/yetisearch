<?php

namespace YetiSearch\Search;

use YetiSearch\Contracts\SearchEngineInterface;
use YetiSearch\Contracts\StorageInterface;
use YetiSearch\Contracts\AnalyzerInterface;
use YetiSearch\Models\SearchQuery;
use YetiSearch\Models\SearchResults;
use YetiSearch\Models\SearchResult;
use YetiSearch\Exceptions\SearchException;
use YetiSearch\Utils\Levenshtein;
use YetiSearch\Utils\JaroWinkler;
use YetiSearch\Utils\Trigram;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class SearchEngine implements SearchEngineInterface
{
    private StorageInterface $storage;
    private AnalyzerInterface $analyzer;
    private LoggerInterface $logger;
    private string $indexName;
    private array $config;
    private array $cache = [];
    private array $fuzzyTermMap = [];
    private ?array $indexedTermsCache = null;
    private float $indexedTermsCacheTime = 0;
    private ?array $synonymsCache = null;
    
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
            'trigram_size' => 3,
            'trigram_threshold' => 0.5,
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
            'fuzzy_score_penalty' => 0.5,   // Penalty factor for fuzzy-only matches
            // Two-pass search strategy (disabled by default for performance)
            'two_pass_search' => false,     // Enable two-pass search for better field weighting
            'primary_fields' => ['title', 'h1', 'name', 'label'], // Fields to search in first pass
            'primary_field_limit' => 100,   // Max results from first pass
            // Multi-column FTS (enabled by default for better performance)
            'multi_column_fts' => true      // Use separate FTS columns for native BM25 weighting
        ], $config);
        
        $this->logger = $logger ?? new NullLogger();
    }
    
    public function search(SearchQuery $query, array $options = []): SearchResults
    {
        $startTime = microtime(true);
        $cacheKey = $this->getCacheKey($query);
        
        $this->logger->debug('SearchEngine::search called', [
            'query_text' => $query->getQuery(),
            'is_fuzzy' => $query->isFuzzy(),
            'config_enable_fuzzy' => $this->config['enable_fuzzy'] ?? false,
            'config_fuzzy_algorithm' => $this->config['fuzzy_algorithm'] ?? 'not set'
        ]);
        
        if ($this->isCached($cacheKey)) {
            $this->logger->debug('Returning cached results', ['query' => $query->getQuery()]);
            return $this->cache[$cacheKey]['results'];
        }
        
        try {
            $this->logger->debug('Executing search', ['query' => $query->toArray()]);
            
            // Store original query for highlighting
            $originalQuery = $query->getQuery();
            
            $processedQuery = $this->processQuery($query);
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
            
            if ($this->config['two_pass_search'] && !empty($this->config['field_weights'])) {
                $this->logger->debug('Executing two-pass search strategy');
                
                // First pass: Search only primary fields with high weights
                $primaryFields = $this->config['primary_fields'];
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
                        $primaryResults = $this->storage->search($this->indexName, $firstPassQuery);
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
                $secondPassResults = $this->storage->search($this->indexName, $secondPassQuery);
                
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
                usort($mergedResults, function($a, $b) {
                    return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
                });
                
                // Apply original limit/offset
                $results = array_slice($mergedResults, $originalOffset, $originalLimit);
                $totalCount = count($mergedResults);
                
            } else {
                // Single-pass search (standard mode)
                $results = $this->storage->search($this->indexName, $storageQuery);
                $totalCount = $this->storage->count($this->indexName, $storageQuery);
            }
            
            // Pass original query for highlighting
            $searchResults = $this->processResults($results, $processedQuery, $originalQuery);
            
            // Apply unique_by_route if requested
            if ($options['unique_by_route'] ?? false) {
                $this->logger->debug('Applying deduplication', ['unique_by_route' => true]);
                $searchResults = $this->deduplicateByRoute($searchResults);
                // Update total count after deduplication
                $totalCount = count($searchResults);
                
                // Now apply the original limit/offset to the deduplicated results
                $searchResults = array_slice($searchResults, $originalOffset, $originalLimit);
            } else {
                $this->logger->debug('Skipping deduplication', ['unique_by_route' => false]);
            }
            
            $facets = [];
            if (!empty($query->getFacets())) {
                $facets = $this->computeFacets($query);
            }
            
            $aggregations = [];
            if (!empty($query->getAggregations())) {
                $aggregations = $this->computeAggregations($query);
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
            
            $this->cacheResults($cacheKey, $finalResults);
            
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
        }
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
                    if (empty($title)) { continue; }

                    // De-dup per variant-result pair
                    $key = strtolower($title);
                    if (isset($seen[$variant][$key])) { continue; }
                    $seen[$variant][$key] = true;

                    $score = (float)$res->getScore();

                    // Prefer titles that contain or start with the (cleaned) variant
                    $titleLower = strtolower($title);
                    $variantLower = strtolower($variant);
                    if (strpos($titleLower, $variantLower) !== false) {
                        $score += $boostTitle;
                    }
                    if (strpos($titleLower, $variantLower) === 0) {
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
        usort($suggestions, function($a, $b) {
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
        
        // Clear any cached data that might be affected by config changes
        $this->indexedTermsCache = null;
        $this->indexedTermsCacheTime = 0;
    }
    
    private function processQuery(SearchQuery $query): SearchQuery
    {
        $queryText = $query->getQuery();
        
        $tokens = $this->analyzer->tokenize($queryText);
        $tokens = $this->analyzer->removeStopWords($tokens, $query->getLanguage());
        
        // Reset fuzzy term map for this query
        $this->fuzzyTermMap = [];
        
        $processedTokens = [];
        $tokenCount = count($tokens);
        $remainingFuzzy = (int)($this->config['fuzzy_total_max_variations'] ?? 30);
        foreach ($tokens as $idx => $token) {
            // For now, don't stem since FTS is not using porter
            $processedTokens[] = $token;
            // Mark original terms as exact matches
            $this->fuzzyTermMap[strtolower($token)] = ['type' => 'exact', 'original' => $token];
            
            if ($query->isFuzzy() && $this->config['enable_fuzzy']) {
                // If enabled, only fuzz the last token (better for as-you-type)
                if (($this->config['fuzzy_last_token_only'] ?? false) && $idx !== $tokenCount - 1) {
                    continue;
                }
                $this->logger->debug('Fuzzy search enabled', [
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
                    if (strtolower($fuzzyToken) !== strtolower($token)) {
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
                        
                        $this->fuzzyTermMap[strtolower($fuzzyToken)] = $fuzzyInfo;
                    }
                }
                
                $processedTokens = array_merge($processedTokens, $fuzzyTokens);
                // Budget down (exclude original token itself if present at [0])
                $remainingFuzzy = max(0, $remainingFuzzy - max(0, count($fuzzyTokens) - 1));
            }
        }
        
        if ($this->config['enable_synonyms']) {
            $processedTokens = $this->expandSynonyms($processedTokens, $query->getLanguage());
        }
        
        // For SQLite FTS5, we need to properly format the query
        // Separate exact tokens from fuzzy variations
        $exactTokens = [];
        $fuzzyTokens = [];
        
        foreach ($tokens as $token) {
            $exactTokens[] = $token;
        }
        
        foreach ($processedTokens as $token) {
            if (!in_array($token, $exactTokens)) {
                $fuzzyTokens[] = $token;
            }
        }
        
        // Build query that prioritizes exact matches
        // Include synonyms (if any) even when fuzzy is disabled
        if (($this->config['enable_synonyms'] ?? false) && !$query->isFuzzy() && !empty($this->config['synonyms'])) {
            $additional = [];
            foreach ($processedTokens as $t) {
                if (!in_array($t, $exactTokens, true)) { $additional[] = $t; }
            }
            if (!empty($additional)) {
                $exactTokens = array_values(array_unique(array_merge($exactTokens, $additional)));
                $fuzzyTokens = [];
            }
        }
        if ($query->isFuzzy() && !empty($fuzzyTokens)) {
            // Build structured query that strongly prioritizes exact matches
            // Use parentheses to group exact matches with higher priority
            // Optional prefix on last token
            if (($this->config['prefix_last_token'] ?? false) && !empty($exactTokens)) {
                $lastIdx = count($exactTokens) - 1;
                $exactTokens[$lastIdx] .= '*';
            }
            
            // Build exact match component with boost
            $exactComponents = [];
            if (count($tokens) > 1) {
                // Exact phrase gets highest priority
                $exactComponents[] = '"' . implode(' ', $tokens) . '"';
            }
            // Add individual exact tokens with NEAR proximity (if multiple tokens)
            if (count($exactTokens) > 1) {
                // Use NEAR operator to boost documents with terms close together
                $exactComponents[] = 'NEAR(' . implode(' ', $exactTokens) . ', 10)';
            } else if (!empty($exactTokens)) {
                // Single token - just add it
                foreach ($exactTokens as $token) {
                    $exactComponents[] = $token;
                }
            }
            
            // Build fuzzy component - group them with lower priority
            $fuzzyComponent = !empty($fuzzyTokens) ? '(' . implode(' OR ', $fuzzyTokens) . ')' : '';
            
            // Combine with exact matches having priority
            // Structure: (exact_phrase OR NEAR(exact_terms)) OR (fuzzy_terms)
            // This ensures exact matches score higher than fuzzy ones
            if (!empty($exactComponents) && !empty($fuzzyComponent)) {
                $processedQuery = '(' . implode(' OR ', $exactComponents) . ') OR ' . $fuzzyComponent;
            } elseif (!empty($exactComponents)) {
                $processedQuery = implode(' OR ', $exactComponents);
            } else {
                $processedQuery = $fuzzyComponent;
            }
        } else {
            // No fuzzy search - use exact tokens (+ synonyms), but build phrase from original tokens only
            if (($this->config['prefix_last_token'] ?? false) && !empty($exactTokens)) {
                $lastIdx = count($exactTokens) - 1;
                $exactTokens[$lastIdx] .= '*';
            }
            
            $exactComponents = [];
            if (count($tokens) > 1) {
                // Add exact phrase
                $exactComponents[] = '"' . implode(' ', $tokens) . '"';
                // Add NEAR query for proximity boost
                $exactComponents[] = 'NEAR(' . implode(' ', $exactTokens) . ', 10)';
            }
            // Add individual tokens
            foreach ($exactTokens as $token) {
                $exactComponents[] = $token;
            }
            
            $processedQuery = implode(' OR ', array_unique($exactComponents));
        }
        
        // Debug: Log the processed query
        if ($this->logger) {
            $this->logger->debug('Processed query', ['original' => $queryText, 'processed' => $processedQuery]);
        }
        
        $newQuery = clone $query;
        $newQuery->setQuery($processedQuery);
        
        return $newQuery;
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
        
        // Pass field weights if configured
        if (!empty($this->config['field_weights'])) {
            $storageQuery['field_weights'] = $this->config['field_weights'];
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
            
            $storageQuery['geoFilters'] = $geoFilters;
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
        
        foreach ($results as $result) {
            if ($result['score'] < $minScore) {
                continue;
            }
            
            // Apply fuzzy penalty if this is a fuzzy search
            $adjustedScore = $result['score'];
            if ($query->isFuzzy() && !empty($this->fuzzyTermMap)) {
                $fuzzyPenalty = $this->calculateFuzzyPenalty($result, $query);
                $adjustedScore = $result['score'] * (1 - $fuzzyPenalty);
                
                $this->logger->debug('Applied fuzzy penalty', [
                    'original_score' => $result['score'],
                    'penalty' => $fuzzyPenalty,
                    'adjusted_score' => $adjustedScore,
                    'doc_id' => $result['id'] ?? 'unknown'
                ]);
            }
            
            $highlights = [];
            if ($query->shouldHighlight()) {
                // Extract content fields for highlighting (exclude metadata and system fields)
                $contentFields = array_diff_key($result, array_flip(['id', 'score', 'metadata', 'language', 'type', 'timestamp', 'distance']));
                $highlights = $this->generateHighlights(
                    $contentFields,
                    $originalQuery ?? $query->getQuery(),
                    $query->getHighlightLength()
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
            
            // Extract content fields (exclude metadata and system fields)
            $contentFields = array_diff_key($result, array_flip(['id', 'score', 'metadata', 'language', 'type', 'timestamp', 'distance']));
            $filteredDocument = $this->filterResultFields($contentFields);
            
            // Log first result to see structure
            static $logged = false;
            if (!$logged) {
                $this->logger->debug('Result document fields', [
                    'raw_fields' => array_keys($contentFields),
                    'filtered_fields' => array_keys($filteredDocument),
                    'has_route' => isset($filteredDocument['route']),
                    'route_value' => $filteredDocument['route'] ?? 'NOT SET'
                ]);
                $logged = true;
            }
            
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
                        $fromLat = $from->getLatitude(); $fromLng = $from->getLongitude();
                    } elseif (is_array($from) && isset($from['lat'],$from['lng'])) {
                        $fromLat = (float)$from['lat']; $fromLng = (float)$from['lng'];
                    } else { $fromLat = $fromLng = null; }
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
        
        // Re-sort results if we applied fuzzy penalties or geo scoring weight
        if (($query->isFuzzy() && !empty($this->fuzzyTermMap)) || (($this->config['distance_weight'] ?? 0.0) > 0)) {
            usort($processedResults, function($a, $b) {
                return $b->getScore() <=> $a->getScore();
            });
        }
        
        return $processedResults;
    }

    private function computeBearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1); $phi2 = deg2rad($lat2);
        $dlambda = deg2rad($lng2 - $lng1);
        $y = sin($dlambda) * cos($phi2);
        $x = cos($phi1)*sin($phi2) - sin($phi1)*cos($phi2)*cos($dlambda);
        $theta = atan2($y, $x);
        $deg = rad2deg($theta);
        return fmod(($deg + 360.0), 360.0);
    }

    private function bearingToCardinal(float $bearing): string
    {
        $dirs = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];
        $idx = (int)round(($bearing % 360) / 22.5) % 16;
        return $dirs[$idx];
    }
    
    private function calculateFuzzyPenalty(array $result, SearchQuery $query): float
    {
        // Get the configured fuzzy penalty (default 0.5 = 50% penalty for fuzzy matches)
        $basePenalty = $this->config['fuzzy_score_penalty'] ?? 0.5;
        
        // Try to determine which terms matched in this result
        // This is a simplified approach - ideally we'd parse the FTS match info
        $documentText = '';
        // Extract content fields for scoring
        $contentFields = array_diff_key($result, array_flip(['id', 'score', 'metadata', 'language', 'type', 'timestamp', 'distance']));
        if (!empty($contentFields)) {
            foreach ($contentFields as $field => $value) {
                if (is_string($value)) {
                    $documentText .= ' ' . strtolower($value);
                }
            }
        }
        
        // Get original query tokens for exact match detection
        $queryText = $query->getQuery();
        $originalTokens = $this->analyzer->tokenize($queryText);
        $originalTokens = array_map('strtolower', $originalTokens);
        
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
                } else if ($maxSimilarity >= 0.85) {
                    return $basePenalty * 0.85; // Good match
                } else {
                    return $basePenalty; // Full penalty for poor matches
                }
            } else if ($minDistance !== PHP_INT_MAX && $minDistance > 0) {
                // For Levenshtein: smaller distance = less penalty
                if ($minDistance === 1) {
                    return $basePenalty * 0.7; // Single character difference
                } else if ($minDistance === 2) {
                    return $basePenalty * 0.85; // Two character difference
                } else {
                    return $basePenalty; // Full penalty for larger distances
                }
            }
        }
        
        // No matches at all or unknown case - apply full penalty
        return $basePenalty;
    }
    
    private function generateHighlights(array $document, string $query, int $length): array
    {
        $highlights = [];
        
        // Build a token list from the raw query and any fuzzy variants
        $tokens = array_filter(array_map('trim', explode(' ', mb_strtolower($query))));
        
        // If fuzzy processing generated variants, include those for highlighting
        if (!empty($this->fuzzyTermMap)) {
            foreach ($this->fuzzyTermMap as $term => $info) {
                // Only include clean terms (skip wildcards or special chars)
                if ($term === '' || strpos($term, '*') !== false || strpos($term, '"') !== false) {
                    continue;
                }
                $tokens[] = $term;
            }
        }
        
        // De-duplicate and prefer longer tokens first to avoid nested highlights
        $tokens = array_values(array_unique($tokens));
        usort($tokens, function($a, $b) { return mb_strlen($b) <=> mb_strlen($a); });
        
        foreach ($document as $field => $value) {
            if (!is_string($value) || $value === '') { continue; }
            
            $snippet = $this->extractSnippet($value, $tokens, $length);
            if ($snippet !== '') {
                $highlights[$field] = $this->highlightTerms($snippet, $tokens);
            }
        }
        
        return $highlights;
    }
    
    private function extractSnippet(string $text, array $terms, int $length): string
    {
        $bestPosition = 0;
        $bestScore = 0;
        
        $lowerText = strtolower($text);
        $textLength = strlen($text);
        
        foreach ($terms as $term) {
            // Try exact match first
            $pos = stripos($lowerText, $term);
            if ($pos !== false) {
                $score = 1 / ($pos + 1);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestPosition = $pos;
                }
            }
            
            // Also try plural form
            $pluralPos = stripos($lowerText, $term . 's');
            if ($pluralPos !== false) {
                $score = 1 / ($pluralPos + 1);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestPosition = $pluralPos;
                }
            }
        }
        
        // Adjust start to show more context before the match
        $contextBefore = min(50, $length / 3); // Show some context before match
        $start = max(0, $bestPosition - $contextBefore);
        $end = min($textLength, $start + $length);
        
        if ($start > 0) {
            $start = strpos($text, ' ', $start) ?: $start;
        }
        
        if ($end < $textLength) {
            $end = strrpos(substr($text, 0, $end), ' ') ?: $end;
        }
        
        $snippet = substr($text, $start, $end - $start);
        
        if ($start > 0) {
            $snippet = '...' . ltrim($snippet);
        }
        
        if ($end < $textLength) {
            $snippet = rtrim($snippet) . '...';
        }
        
        return $snippet;
    }
    
    private function highlightTerms(string $text, array $terms): string
    {
        $highlighted = $text;
        
        foreach ($terms as $term) {
            // Match the term and common variations (plural with 's')
            $pattern = '/\b(' . preg_quote($term, '/') . 's?)\b/i';
            $replacement = $this->config['highlight_tag'] . '$1' . $this->config['highlight_tag_close'];
            $highlighted = preg_replace($pattern, $replacement, $highlighted);
        }
        
        return $highlighted;
    }
    
    private function deduplicateByRoute(array $results): array
    {
        $routeMap = [];
        $deduplicated = [];
        
        $this->logger->debug('Deduplicating results', ['total' => count($results)]);
        
        foreach ($results as $result) {
            $route = $result->get('route', '');
            
            // Skip if no route
            if (empty($route)) {
                $deduplicated[] = $result;
                continue;
            }
            
            // Keep only the highest scoring result for each route
            if (!isset($routeMap[$route]) || $result->getScore() > $routeMap[$route]->getScore()) {
                $routeMap[$route] = $result;
            }
        }
        
        // Add all unique results
        foreach ($routeMap as $result) {
            $deduplicated[] = $result;
        }
        
        // Sort by score descending
        usort($deduplicated, function($a, $b) {
            return $b->getScore() <=> $a->getScore();
        });
        
        $this->logger->debug('Deduplication complete', [
            'unique_routes' => count($routeMap),
            'results_without_route' => count($deduplicated) - count($routeMap),
            'final_count' => count($deduplicated)
        ]);
        
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
    
    private function computeFacets(SearchQuery $query): array
    {
        $facets = [];
        
        foreach ($query->getFacets() as $field => $options) {
            // Distance facet: bucket results by distance thresholds from a point
            if ($field === 'distance') {
                $from = $options['from'] ?? null;
                if ($from instanceof \YetiSearch\Geo\GeoPoint) {
                    $fromArr = $from->toArray();
                } elseif (is_array($from) && isset($from['lat'],$from['lng'])) {
                    $fromArr = ['lat'=>(float)$from['lat'],'lng'=>(float)$from['lng']];
                } else {
                    continue;
                }
                $ranges = $options['ranges'] ?? [];
                if (empty($ranges) || !is_array($ranges)) { continue; }
                $units = strtolower($options['units'] ?? ($this->config['geo_units'] ?? 'm'));
                $factor = 1.0; if ($units==='km') $factor=1000.0; elseif (in_array($units,['mi','mile','miles'])) $factor=1609.344;

                $facetQuery = [
                    'query' => $query->getQuery(),
                    'filters' => $query->getFilters(),
                    'language' => $query->getLanguage(),
                    'limit' => 1000,
                    'offset' => 0,
                    'geoFilters' => [
                        'distance_sort' => ['from' => $fromArr, 'direction' => 'ASC']
                    ]
                ];
                try {
                    $results = $this->storage->search($this->indexName, $facetQuery);
                    $buckets = [];
                    foreach ($ranges as $r) { $buckets[(float)$r]=0; }
                    $buckets[INF] = 0;
                    foreach ($results as $row) {
                        $dist = (float)($row['distance'] ?? 0.0);
                        $dUnits = $dist / $factor;
                        $placed = false;
                        foreach ($ranges as $r) {
                            if ($dUnits <= (float)$r) { $buckets[(float)$r]++; $placed=true; break; }
                        }
                        if (!$placed) { $buckets[INF]++; }
                    }
                    $facetResults = [];
                    foreach ($ranges as $r) {
                        $facetResults[] = ['value' => sprintf('<= %s %s', $r, $units), 'count' => $buckets[(float)$r] ?? 0];
                    }
                    if (($buckets[INF] ?? 0) > 0) {
                        $facetResults[] = ['value' => sprintf('> %s %s', end($ranges), $units), 'count' => $buckets[INF]];
                    }
                    $facets['distance'] = $facetResults;
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to compute distance facet', ['error' => $e->getMessage()]);
                }
                continue;
            }
            $facetQuery = [
                'query' => $query->getQuery(),
                'filters' => $query->getFilters(),
                'language' => $query->getLanguage()
            ];
            
            try {
                $results = $this->storage->search($this->indexName, array_merge($facetQuery, [
                    'limit' => 1000,
                    'offset' => 0
                ]));
                
                $facetValues = [];
                foreach ($results as $result) {
                    // Extract value from content fields or metadata
                    $value = $result[$field] ?? ($result['metadata'][$field] ?? null);
                    if ($value !== null) {
                        if (!isset($facetValues[$value])) {
                            $facetValues[$value] = 0;
                        }
                        $facetValues[$value]++;
                    }
                }
                
                $facetResults = [];
                foreach ($facetValues as $value => $count) {
                    if ($count >= $this->config['facet_min_count']) {
                        $facetResults[] = [
                            'value' => $value,
                            'count' => $count
                        ];
                    }
                }
                
                usort($facetResults, function ($a, $b) {
                    return $b['count'] <=> $a['count'];
                });
                
                $facets[$field] = array_slice($facetResults, 0, $options['limit'] ?? 10);
                
            } catch (\Exception $e) {
                $this->logger->warning('Failed to compute facet', [
                    'field' => $field,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $facets;
    }
    
    private function computeAggregations(SearchQuery $query): array
    {
        // Placeholder for aggregation computation
        // This would be implemented based on specific aggregation types
        return [];
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
    
    private function generateLevenshteinVariations(string $term): array
    {
        $variations = [$term]; // Always include the original term
        
        // Skip short terms
        if (mb_strlen($term) <= 3) {
            return $variations;
        }
        
        // Get configuration
        $threshold = $this->config['levenshtein_threshold'] ?? 2;
        $minFrequency = $this->config['min_term_frequency'] ?? 2;
        $maxVariations = $this->config['max_fuzzy_variations'] ?? 10;
        
        try {
            // Use cached indexed terms if available and fresh (5 minute cache)
            $cacheTimeout = $this->config['indexed_terms_cache_ttl'] ?? 300; // 5 minutes default
            $now = time();
            
            if ($this->indexedTermsCache === null || ($now - $this->indexedTermsCacheTime) > $cacheTimeout) {
                $this->logger->debug('Loading indexed terms from database');
                $termLimit = $this->config['max_indexed_terms'] ?? 20000;
                $this->indexedTermsCache = $this->storage->getIndexedTerms($this->indexName, $minFrequency, $termLimit);
                $this->indexedTermsCacheTime = $now;
            } else {
                $this->logger->debug('Using cached indexed terms', [
                    'cache_age' => $now - $this->indexedTermsCacheTime
                ]);
            }
            
            $indexedTerms = $this->indexedTermsCache;
            
            // Find terms within the Levenshtein distance threshold
            $candidateTerms = [];
            $termLower = strtolower($term);
            $termLen = mb_strlen($term);
            
            foreach ($indexedTerms as $indexedTerm) {
                // Skip if same as search term (case insensitive)
                if (strcasecmp($term, $indexedTerm) === 0) {
                    continue;
                }
                
                // Quick length-based filter before expensive distance calculation
                $indexedLen = mb_strlen($indexedTerm);
                if (abs($termLen - $indexedLen) > $threshold) {
                    continue;
                }
                
                // Additional optimization: skip if first/last characters differ too much
                if ($threshold <= 2) {
                    $indexedLower = strtolower($indexedTerm);
                    if ($termLower[0] !== $indexedLower[0] && 
                        $termLower[strlen($termLower)-1] !== $indexedLower[strlen($indexedLower)-1]) {
                        continue;
                    }
                }

                // Bigram prefilter for words of reasonable length
                if ($termLen >= 4 && $indexedLen >= 4) {
                    $bigrams = static function(string $s): array {
                        $s = strtolower($s);
                        $out = [];
                        for ($i = 0, $n = strlen($s) - 1; $i < $n; $i++) {
                            $out[] = substr($s, $i, 2);
                        }
                        return array_unique($out);
                    };
                    $tBi = $bigrams($term);
                    $iBi = $bigrams($indexedTerm);
                    if (empty(array_intersect($tBi, $iBi))) {
                        continue;
                    }
                }
                
                // Calculate distance only if within bounds
                if (Levenshtein::isWithinDistance($term, $indexedTerm, $threshold)) {
                    $distance = Levenshtein::distance($term, $indexedTerm);
                    $candidateTerms[] = [
                        'term' => $indexedTerm,
                        'distance' => $distance,
                        'similarity' => Levenshtein::similarity($term, $indexedTerm)
                    ];
                }
            }
            
            // Sort by distance (ascending) and similarity (descending)
            usort($candidateTerms, function($a, $b) {
                if ($a['distance'] === $b['distance']) {
                    return $b['similarity'] <=> $a['similarity'];
                }
                return $a['distance'] <=> $b['distance'];
            });
            
            // Take the best matches up to the limit
            $candidateTerms = array_slice($candidateTerms, 0, $maxVariations);
            
            // Extract just the terms
            foreach ($candidateTerms as $candidate) {
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
    
    private function generateJaroWinklerVariations(string $term): array
    {
        $variations = [$term]; // Always include the original term
        
        // Skip short terms
        if (mb_strlen($term) <= 3) {
            return $variations;
        }
        
        // Get configuration
        $threshold = $this->config['jaro_winkler_threshold'] ?? 0.85;
        $minFrequency = $this->config['min_term_frequency'] ?? 2;
        $maxVariations = $this->config['max_fuzzy_variations'] ?? 10;
        $prefixScale = $this->config['jaro_winkler_prefix_scale'] ?? 0.1;
        
        try {
            // Use cached indexed terms if available
            $cacheTimeout = $this->config['indexed_terms_cache_ttl'] ?? 300;
            $now = time();
            
            if ($this->indexedTermsCache === null || ($now - $this->indexedTermsCacheTime) > $cacheTimeout) {
                $this->logger->debug('Loading indexed terms from database for Jaro-Winkler');
                $termLimit = $this->config['max_indexed_terms'] ?? 20000;
                $this->indexedTermsCache = $this->storage->getIndexedTerms($this->indexName, $minFrequency, $termLimit);
                $this->indexedTermsCacheTime = $now;
            }
            
            $indexedTerms = $this->indexedTermsCache;
            
            // Find best matches using Jaro-Winkler
            $matches = JaroWinkler::findBestMatches(
                $term,
                $indexedTerms,
                $threshold,
                $maxVariations,
                $prefixScale
            );
            
            // Extract just the terms from matches
            foreach ($matches as $match) {
                $variations[] = $match[0]; // match is [term, score]
            }
            
            $this->logger->debug('Generated Jaro-Winkler variations', [
                'term' => $term,
                'variations' => count($variations) - 1,
                'threshold' => $threshold
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate Jaro-Winkler variations', [
                'term' => $term,
                'error' => $e->getMessage()
            ]);
        }
        
        return array_unique($variations);
    }
    
    private function generateTrigramVariations(string $term): array
    {
        $variations = [$term]; // Always include the original term
        
        // Skip short terms
        if (mb_strlen($term) <= 3) {
            return $variations;
        }
        
        // Get configuration
        $threshold = $this->config['trigram_threshold'] ?? 0.5;
        $minFrequency = $this->config['min_term_frequency'] ?? 2;
        $maxVariations = $this->config['max_fuzzy_variations'] ?? 10;
        $ngramSize = $this->config['trigram_size'] ?? 3;
        // Adaptive n-gram for short tokens
        if (mb_strlen($term) <= 4) {
            $ngramSize = 2;
        }
        
        try {
            // Use cached indexed terms if available
            $cacheTimeout = $this->config['indexed_terms_cache_ttl'] ?? 300;
            $now = time();
            
            if ($this->indexedTermsCache === null || ($now - $this->indexedTermsCacheTime) > $cacheTimeout) {
                $this->logger->debug('Loading indexed terms from database for Trigram');
                $termLimit = $this->config['max_indexed_terms'] ?? 20000;
                $this->indexedTermsCache = $this->storage->getIndexedTerms($this->indexName, $minFrequency, $termLimit);
                $this->indexedTermsCacheTime = $now;
            }
            
            $indexedTerms = $this->indexedTermsCache;
            
            // Find best matches using Trigram similarity
            $matches = Trigram::findBestMatches(
                $term,
                $indexedTerms,
                $threshold,
                $maxVariations,
                $ngramSize
            );
            
            // Extract just the terms from matches
            foreach ($matches as $match) {
                $variations[] = $match[0]; // match is [term, score]
            }
            
            $this->logger->debug('Generated Trigram variations', [
                'term' => $term,
                'variations' => count($variations) - 1,
                'threshold' => $threshold,
                'ngram_size' => $ngramSize
            ]);
            
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
        if ($this->synonymsCache === null) {
            $map = $this->config['synonyms'] ?? [];
            if (is_string($map) && file_exists($map)) {
                try {
                    $json = file_get_contents($map);
                    $decoded = json_decode($json, true);
                    if (is_array($decoded)) { $map = $decoded; }
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
        if (!is_array($map) || empty($map)) { return $tokens; }
        $caseSensitive = (bool)($this->config['synonyms_case_sensitive'] ?? false);
        $perTermMax = (int)($this->config['synonyms_max_expansions'] ?? 3);
        $totalCap = max(5, $perTermMax * 10);

        $expanded = $tokens;
        $totalAdded = 0;
        foreach ($tokens as $t) {
            $key = $caseSensitive ? $t : mb_strtolower($t);
            $syns = $map[$key] ?? null;
            if (!is_array($syns) || empty($syns)) { continue; }
            $addedForTerm = 0;
            foreach ($syns as $s) {
                if ($addedForTerm >= $perTermMax || $totalAdded >= $totalCap) { break; }
                $s = (string)$s;
                $tokenToAdd = (strpos($s, ' ') !== false) ? '"' . $s . '"' : $s;
                if (!in_array($tokenToAdd, $expanded, true)) {
                    $expanded[] = $tokenToAdd;
                    $addedForTerm++; $totalAdded++;
                }
            }
            if ($totalAdded >= $totalCap) { break; }
        }
        return $expanded;
    }
    
    private function generateSuggestion(string $query): ?string
    {
        $tokens = $this->analyzer->tokenize($query);
        $suggestions = [];
        
        foreach ($tokens as $token) {
            $fuzzyVariations = $this->generateFuzzyVariations($token);
            
            foreach ($fuzzyVariations as $variation) {
                $testQuery = new SearchQuery($variation);
                $testQuery->limit(1);
                
                if ($this->count($testQuery) > 0) {
                    $suggestions[] = $variation;
                    break;
                }
            }
        }
        
        return !empty($suggestions) ? implode(' ', $suggestions) : null;
    }
    
    private function getCacheKey(SearchQuery $query): string
    {
        return md5(json_encode($query->toArray()));
    }
    
    private function isCached(string $key): bool
    {
        if (!isset($this->cache[$key])) {
            return false;
        }
        
        $cached = $this->cache[$key];
        return (time() - $cached['time']) < $this->config['cache_ttl'];
    }
    
    private function cacheResults(string $key, SearchResults $results): void
    {
        $this->cache[$key] = [
            'results' => $results,
            'time' => time()
        ];
        
        if (count($this->cache) > 100) {
            $oldestKey = null;
            $oldestTime = PHP_INT_MAX;
            
            foreach ($this->cache as $k => $v) {
                if ($v['time'] < $oldestTime) {
                    $oldestTime = $v['time'];
                    $oldestKey = $k;
                }
            }
            
            if ($oldestKey !== null) {
                unset($this->cache[$oldestKey]);
            }
        }
    }
}
