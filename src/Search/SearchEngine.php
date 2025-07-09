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
            'result_fields' => ['title', 'content', 'excerpt', 'url', 'author', 'tags'],
            'facet_min_count' => 1
        ], $config);
        
        $this->logger = $logger ?? new NullLogger();
    }
    
    public function search(SearchQuery $query): SearchResults
    {
        $startTime = microtime(true);
        $cacheKey = $this->getCacheKey($query);
        
        if ($this->isCached($cacheKey)) {
            $this->logger->debug('Returning cached results', ['query' => $query->getQuery()]);
            return $this->cache[$cacheKey]['results'];
        }
        
        try {
            $this->logger->debug('Executing search', ['query' => $query->toArray()]);
            
            $processedQuery = $this->processQuery($query);
            $storageQuery = $this->buildStorageQuery($processedQuery);
            
            $results = $this->storage->search($this->indexName, $storageQuery);
            $totalCount = $this->storage->count($this->indexName, $storageQuery);
            
            $searchResults = $this->processResults($results, $processedQuery);
            
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
        
        return $storageQuery;
    }
    
    private function processResults(array $results, SearchQuery $query): array
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
                    $query->getQuery(),
                    $query->getHighlightLength()
                );
            }
            
            // Normalize score to 0-100 range
            $normalizedScore = $maxScore > 0 ? round(($result['score'] / $maxScore) * 100, 1) : 0;
            
            $processedResult = new SearchResult([
                'id' => $result['id'],
                'score' => $normalizedScore,
                'document' => $this->filterResultFields($result['document']),
                'highlights' => $highlights,
                'metadata' => $result['metadata'] ?? []
            ]);
            
            $processedResults[] = $processedResult;
        }
        
        return $processedResults;
    }
    
    private function generateHighlights(array $document, string $query, int $length): array
    {
        $highlights = [];
        $queryTokens = $this->analyzer->tokenize($query);
        
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
            $pos = stripos($lowerText, $term);
            if ($pos !== false) {
                $score = 1 / ($pos + 1);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestPosition = $pos;
                }
            }
        }
        
        $start = max(0, $bestPosition - ($length / 2));
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
            $pattern = '/\b(' . preg_quote($term, '/') . ')\b/i';
            $replacement = $this->config['highlight_tag'] . '$1' . $this->config['highlight_tag_close'];
            $highlighted = preg_replace($pattern, $replacement, $highlighted);
        }
        
        return $highlighted;
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