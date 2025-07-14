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
            'enable_suggestions' => true,
            'cache_ttl' => 300,
            'result_fields' => ['title', 'content', 'excerpt', 'url', 'author', 'tags', 'route'],
            'facet_min_count' => 1
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
            
            $results = $this->storage->search($this->indexName, $storageQuery);
            $totalCount = $this->storage->count($this->indexName, $storageQuery);
            
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
        
        $suggestions = [];
        
        $fuzzyTerms = $this->generateFuzzyVariations($term);
        
        foreach ($fuzzyTerms as $fuzzyTerm) {
            $query = new SearchQuery($fuzzyTerm);
            $query->limit(5);
            
            try {
                $results = $this->search($query);
                
                foreach ($results as $result) {
                    $title = $result->get('title', '');
                    if (!empty($title) && !in_array($title, $suggestions)) {
                        $suggestions[] = [
                            'text' => $title,
                            'score' => $result->getScore()
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Continue with other suggestions
            }
        }
        
        usort($suggestions, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        return array_slice($suggestions, 0, $options['limit'] ?? 10);
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
        foreach ($tokens as $token) {
            // For now, don't stem since FTS is not using porter
            $processedTokens[] = $token;
            // Mark original terms as exact matches
            $this->fuzzyTermMap[strtolower($token)] = ['type' => 'exact', 'original' => $token];
            
            if ($query->isFuzzy() && $this->config['enable_fuzzy']) {
                $this->logger->debug('Fuzzy search enabled', [
                    'token' => $token,
                    'fuzzy_algorithm' => $this->config['fuzzy_algorithm'] ?? 'basic',
                    'enable_fuzzy' => $this->config['enable_fuzzy']
                ]);
                $fuzzyTokens = $this->generateFuzzyVariations($token);
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
            }
        }
        
        if ($this->config['enable_synonyms']) {
            $processedTokens = $this->expandSynonyms($processedTokens);
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
        if ($query->isFuzzy() && !empty($fuzzyTokens)) {
            // Put exact terms first, then fuzzy variations
            // This should give higher scores to exact matches due to BM25 scoring
            $allTokens = array_unique(array_merge($exactTokens, $fuzzyTokens));
            
            if (count($exactTokens) > 1) {
                // For multi-word queries, also search for the exact phrase
                $exactPhrase = '"' . implode(' ', $exactTokens) . '"';
                $processedQuery = $exactPhrase . ' OR ' . implode(' OR ', $allTokens);
            } else {
                // Single word query
                $processedQuery = implode(' OR ', $allTokens);
            }
        } else {
            // No fuzzy search - just use exact tokens
            if (count($exactTokens) > 1) {
                $exactPhrase = '"' . implode(' ', $exactTokens) . '"';
                $orTerms = implode(' OR ', $exactTokens);
                $processedQuery = "({$exactPhrase} OR {$orTerms})";
            } else {
                $processedQuery = implode(' ', $exactTokens);
            }
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
                $highlights = $this->generateHighlights(
                    $result['document'],
                    $originalQuery ?? $query->getQuery(),
                    $query->getHighlightLength()
                );
            }
            
            // Normalize score to 0-100 range
            $normalizedScore = $maxScore > 0 ? round(($adjustedScore / $maxScore) * 100, 1) : 0;
            
            $filteredDocument = $this->filterResultFields($result['document']);
            
            // Log first result to see structure
            static $logged = false;
            if (!$logged) {
                $this->logger->debug('Result document fields', [
                    'raw_fields' => array_keys($result['document']),
                    'filtered_fields' => array_keys($filteredDocument),
                    'has_route' => isset($filteredDocument['route']),
                    'route_value' => $filteredDocument['route'] ?? 'NOT SET'
                ]);
                $logged = true;
            }
            
            $resultData = [
                'id' => $result['id'],
                'score' => $normalizedScore,
                'document' => $filteredDocument,
                'highlights' => $highlights,
                'metadata' => $result['metadata'] ?? []
            ];
            
            // Add distance if present
            if (isset($result['distance'])) {
                $resultData['distance'] = $result['distance'];
            }
            
            $processedResult = new SearchResult($resultData);
            
            $processedResults[] = $processedResult;
        }
        
        // Re-sort results if we applied fuzzy penalties
        if ($query->isFuzzy() && !empty($this->fuzzyTermMap)) {
            usort($processedResults, function($a, $b) {
                return $b->getScore() <=> $a->getScore();
            });
        }
        
        return $processedResults;
    }
    
    private function calculateFuzzyPenalty(array $result, SearchQuery $query): float
    {
        // Get the configured fuzzy penalty (default 0.3 = 30% penalty)
        $basePenalty = $this->config['fuzzy_score_penalty'] ?? 0.3;
        
        // Try to determine which terms matched in this result
        // This is a simplified approach - ideally we'd parse the FTS match info
        $documentText = '';
        if (isset($result['document'])) {
            foreach ($result['document'] as $field => $value) {
                if (is_string($value)) {
                    $documentText .= ' ' . strtolower($value);
                }
            }
        }
        
        // Check if any exact terms match
        $hasExactMatch = false;
        $hasFuzzyMatch = false;
        $minDistance = PHP_INT_MAX;
        $maxSimilarity = 0.0;
        
        foreach ($this->fuzzyTermMap as $term => $info) {
            if (stripos($documentText, $term) !== false) {
                if ($info['type'] === 'exact') {
                    $hasExactMatch = true;
                } else {
                    $hasFuzzyMatch = true;
                    if (isset($info['distance'])) {
                        $minDistance = min($minDistance, $info['distance']);
                    }
                    if (isset($info['similarity'])) {
                        $maxSimilarity = max($maxSimilarity, $info['similarity']);
                    }
                }
            }
        }
        
        // If document has exact matches, reduce or eliminate penalty
        if ($hasExactMatch && !$hasFuzzyMatch) {
            return 0.0; // No penalty for exact matches only
        }
        
        if ($hasExactMatch && $hasFuzzyMatch) {
            // Mixed matches - apply half penalty
            return $basePenalty * 0.5;
        }
        
        // Only fuzzy matches - apply penalty based on similarity/distance
        if ($maxSimilarity > 0) {
            // For Jaro-Winkler: higher similarity = less penalty
            // similarity of 1.0 = no penalty, 0.7 = full penalty
            $similarityFactor = max(0, 1.0 - $maxSimilarity) / 0.3; // normalize to 0-1 range
            return $basePenalty * $similarityFactor;
        } else if ($minDistance !== PHP_INT_MAX && $minDistance > 0) {
            // For Levenshtein: smaller distance = less penalty
            $distanceFactor = min(1.0, $minDistance / 3.0);
            return $basePenalty * $distanceFactor;
        }
        
        return $basePenalty;
    }
    
    private function generateHighlights(array $document, string $query, int $length): array
    {
        $highlights = [];
        // Use raw query terms for highlighting
        $queryTokens = array_filter(explode(' ', strtolower($query)));
        
        foreach ($document as $field => $value) {
            if (!is_string($value) || empty($value)) {
                continue;
            }
            
            $snippet = $this->extractSnippet($value, $queryTokens, $length);
            
            if (!empty($snippet)) {
                $highlightedSnippet = $this->highlightTerms($snippet, $queryTokens);
                $highlights[$field] = $highlightedSnippet;
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
                    $value = $result['document'][$field] ?? null;
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
    
    private function expandSynonyms(array $tokens): array
    {
        // Placeholder for synonym expansion
        // This would load from a synonym dictionary
        return $tokens;
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