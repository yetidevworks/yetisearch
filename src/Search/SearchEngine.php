<?php

namespace YetiSearch\Search;

use YetiSearch\Contracts\SearchEngineInterface;
use YetiSearch\Contracts\StorageInterface;
use YetiSearch\Contracts\AnalyzerInterface;
use YetiSearch\Models\SearchQuery;
use YetiSearch\Models\SearchResults;
use YetiSearch\Models\SearchResult;
use YetiSearch\Exceptions\SearchException;
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
    
    private function processQuery(SearchQuery $query): SearchQuery
    {
        $queryText = $query->getQuery();
        
        $tokens = $this->analyzer->tokenize($queryText);
        $tokens = $this->analyzer->removeStopWords($tokens, $query->getLanguage());
        
        $processedTokens = [];
        foreach ($tokens as $token) {
            $stemmed = $this->analyzer->stem($token, $query->getLanguage());
            $processedTokens[] = $stemmed;
            
            if ($query->isFuzzy() && $this->config['enable_fuzzy']) {
                $fuzzyTokens = $this->generateFuzzyVariations($stemmed);
                $processedTokens = array_merge($processedTokens, $fuzzyTokens);
            }
        }
        
        if ($this->config['enable_synonyms']) {
            $processedTokens = $this->expandSynonyms($processedTokens);
        }
        
        $processedQuery = implode(' OR ', array_unique($processedTokens));
        
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
            
            $highlights = [];
            if ($query->shouldHighlight()) {
                $highlights = $this->generateHighlights(
                    $result['document'],
                    $originalQuery ?? $query->getQuery(),
                    $query->getHighlightLength()
                );
            }
            
            // Normalize score to 0-100 range
            $normalizedScore = $maxScore > 0 ? round(($result['score'] / $maxScore) * 100, 1) : 0;
            
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
        
        // Sort by score descending
        usort($processedResults, function($a, $b) {
            return $b->getScore() <=> $a->getScore();
        });
        
        return $processedResults;
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