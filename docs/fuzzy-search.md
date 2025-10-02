# Fuzzy Search in YetiSearch

## Overview

YetiSearch supports multiple fuzzy search algorithms to provide typo tolerance and improve search accuracy. These algorithms help find matches even when search terms contain spelling errors, character transpositions, or other common mistakes.

### 🆕 Enhanced Fuzzy Search (v2.2+)

YetiSearch 2.2+ includes **enhanced fuzzy search with modern typo correction** that behaves like Google and Elasticsearch:

- **Automatic typo correction** using multi-algorithm consensus scoring
- **Phonetic matching** for sound-alike typos (fone→phone, thier→their)  
- **Keyboard proximity analysis** for fat-finger errors (qyick→quick)
- **Enhanced "Did You Mean?" suggestions** with confidence scores

For detailed information about the enhanced fuzzy search features, see the [Enhanced Fuzzy Search Guide](enhanced-fuzzy-search.md).

The enhanced features are **enabled by default** in v2.2+ and work alongside the traditional fuzzy algorithms described below.

## Available Fuzzy Algorithms

### 1. Trigram (Default)

Trigram matching breaks words into 3-character sequences and finds similar terms based on shared trigrams. This is the default algorithm as it provides the best balance of accuracy and performance.

**Characteristics:**
- Fast indexing and searching
- No additional term indexing required
- Excellent for most use cases
- Good handling of insertions, deletions, and substitutions

**Example:**
```
"programming" → ["pro", "rog", "ogr", "gra", "ram", "amm", "mmi", "min", "ing"]
"porgramming" → ["por", "org", "rgr", "gra", "ram", "amm", "mmi", "min", "ing"]
Shared trigrams: 6/9 = 67% similarity
```

### 2. Jaro-Winkler

Jaro-Winkler distance is optimized for short strings like names and titles. It gives higher scores to strings with common prefixes.

**Characteristics:**
- Very fast performance
- Best for short text (names, titles, codes)
- Favors matches with matching prefixes
- Less effective for long text

**Example:**
```
"Skywalker" vs "Sywalker" = high similarity (common prefix "S")
"Martha" vs "Marhta" = high similarity (transposition)
```

### 3. Levenshtein

Levenshtein distance (edit distance) measures the minimum number of single-character edits required to change one word into another.

**Characteristics:**
- Most flexible and accurate
- Handles insertions, deletions, and substitutions
- Requires term indexing (slower indexing)
- Configurable edit distance threshold

**Examples:**
```
"Anakin" → "Amakin" = distance 1 (substitute 'n' with 'm')
"programming" → "porgramming" = distance 2 (insert 'r', delete 'r')
```

### 4. Basic

The original fuzzy implementation using character deletions and wildcards.

**Characteristics:**
- Fastest performance
- Simple character deletion approach
- Limited accuracy
- Good for basic typo tolerance

## Configuration

```php
$config = [
    'search' => [
        'enable_fuzzy' => true,                  // Enable fuzzy search
        'fuzzy_algorithm' => 'trigram',          // Options: 'trigram', 'jaro_winkler', 'levenshtein', 'basic'
        'levenshtein_threshold' => 2,            // For Levenshtein: max edit distance (1-3)
        'min_term_frequency' => 2,               // Min frequency for candidate terms
        'max_indexed_terms' => 10000,            // Max terms to check
        'max_fuzzy_variations' => 8,             // Max variations per search term
        'fuzzy_score_penalty' => 0.4,            // Score penalty for fuzzy matches (0.0-1.0)
        'indexed_terms_cache_ttl' => 300         // Cache TTL in seconds
    ]
];
```

### Configuration Options Explained

- **`fuzzy_algorithm`**: Choose the fuzzy matching algorithm
  - `'trigram'` (default): Best overall performance and accuracy
  - `'jaro_winkler'`: Best for short strings
  - `'levenshtein'`: Most accurate but requires term indexing
  - `'basic'`: Fastest but limited accuracy

- **`levenshtein_threshold`**: Maximum edit distance (Levenshtein only)
  - `1`: Only very close matches (single typo)
  - `2`: Moderate tolerance (recommended)
  - `3`: Very permissive (may return false positives)

- **`min_term_frequency`**: Minimum times a term must appear
  - Higher values = faster search but may miss rare terms
  - Lower values = more comprehensive but slower

- **`max_indexed_terms`**: Maximum number of indexed terms to check
  - Affects performance for Levenshtein algorithm
  - Higher values = more comprehensive but slower

- **`max_fuzzy_variations`**: Limits fuzzy matches per search term
  - Prevents performance issues with common typos

- **`fuzzy_score_penalty`**: Score reduction for fuzzy matches
  - `0.0` = maximum penalty (fuzzy matches score very low)
  - `0.4` = 40% score reduction (default)
  - `1.0` = no penalty (not recommended)

## Usage

```php
// Basic fuzzy search (uses configured algorithm)
$results = $search->search('products', 'laptp computr', [
    'fuzzy' => true
]);

// Override algorithm for specific search
$results = $search->search('names', 'Jon Smth', [
    'fuzzy' => true,
    'fuzzy_algorithm' => 'jaro_winkler'  // Best for names
]);

// Search with fuzzy disabled (exact matches only)
$results = $search->search('products', 'laptop', [
    'fuzzy' => false
]);
```

## Algorithm Comparison

| Feature | Trigram | Jaro-Winkler | Levenshtein | Basic |
|---------|---------|--------------|-------------|-------|
| **Speed** | Fast | Very Fast | Moderate | Fastest |
| **Accuracy** | High | High (short text) | Highest | Low |
| **Character deletion** | ✓ | ✓ | ✓ | ✓ |
| **Character insertion** | ✓ | ✓ | ✓ | ✗ |
| **Character substitution** | ✓ | ✓ | ✓ | ✗ |
| **Transposition** | ✓ | ✓ | ✓ | ✓ (adjacent) |
| **Term indexing required** | ✗ | ✗ | ✓ | ✗ |
| **Best for** | General use | Names/titles | High accuracy | Simple typos |

## Performance Characteristics

### Indexing Performance (tested on M4 MacBook Pro)
- **Trigram, Jaro-Winkler, Basic**: ~4,360 movies/second documents/second
- **Levenshtein**: ~1,770  documents/second (due to term indexing)

### Search Performance Impacts
- **Basic**: 5% average
- **Jaro-Winkler**: 10% average
- **Trigram**: 20% average
- **Levenshtein**: 50% average (depends on vocabulary size)

## How Each Algorithm Works

### Trigram Matching
1. Breaks search terms into 3-character sequences
2. Finds indexed terms with similar trigram patterns
3. Calculates similarity based on shared trigrams
4. Expands query with similar terms

### Jaro-Winkler
1. Calculates character matches within a sliding window
2. Considers transpositions
3. Applies prefix bonus for matching starts
4. Best suited for short strings

### Levenshtein
1. Queries indexed terms from database
2. Calculates edit distance for each candidate
3. Filters terms within threshold distance
4. Ranks by distance and similarity

### Basic
1. Creates variations by deleting characters
2. Adds wildcard patterns
3. Simple but limited approach

## Choosing the Right Algorithm

### Use Trigram (default) when:
- You need a good balance of speed and accuracy
- Working with general text content
- You want consistent performance
- No special requirements

### Use Jaro-Winkler when:
- Searching primarily names, titles, or codes
- Working with short text fields
- Common prefixes are important
- Speed is critical

### Use Levenshtein when:
- Accuracy is more important than speed
- You need to handle complex typos
- Working with technical terms or specialized vocabulary
- Can afford slower indexing

### Use Basic when:
- Speed is the top priority
- Simple typo tolerance is sufficient
- Working with very large datasets
- Indexing performance is critical

## Examples

### Example 1: Product Search
```php
// Trigram handles various typos well
$results = $search->search('products', 'wireles hedphones', [
    'fuzzy' => true  // Finds "wireless headphones"
]);
```

### Example 2: Name Search
```php
// Jaro-Winkler excels at name matching
$config['search']['fuzzy_algorithm'] = 'jaro_winkler';
$results = $search->search('contacts', 'John Smth', [
    'fuzzy' => true  // Finds "John Smith"
]);
```

### Example 3: Technical Terms
```php
// Levenshtein for complex technical terms
$config['search']['fuzzy_algorithm'] = 'levenshtein';
$results = $search->search('docs', 'PostgreSQL', [
    'fuzzy' => true  // Also finds common misspellings
]);
```

## Benchmarking

YetiSearch includes benchmarking tools to help choose the best algorithm:

```php
use YetiSearch\Tools\FuzzyBenchmark;

$benchmark = new FuzzyBenchmark($search);
$results = $benchmark->runAllBenchmarks();

// Compare algorithms
foreach ($results as $algorithm => $metrics) {
    echo sprintf(
        "%s: %.1f%% accuracy, %.2fms avg search time\n",
        $algorithm,
        $metrics['accuracy'],
        $metrics['avg_time']
    );
}
```

## Optimization Tips

### For Best Performance
```php
$config = [
    'search' => [
        'fuzzy_algorithm' => 'trigram',
        'min_term_frequency' => 5,
        'max_indexed_terms' => 5000,
        'max_fuzzy_variations' => 5,
        'indexed_terms_cache_ttl' => 600
    ]
];
```

### For Best Accuracy
```php
$config = [
    'search' => [
        'fuzzy_algorithm' => 'levenshtein',
        'levenshtein_threshold' => 2,
        'min_term_frequency' => 1,
        'max_indexed_terms' => 20000,
        'fuzzy_score_penalty' => 0.3
    ]
];
```

### For Name Matching
```php
$config = [
    'search' => [
        'fuzzy_algorithm' => 'jaro_winkler',
        'min_term_frequency' => 1,
        'fuzzy_score_penalty' => 0.2
    ]
];
```

## Troubleshooting

### No Fuzzy Matches Found
- Verify `enable_fuzzy` is `true`
- Check if the algorithm is appropriate for your content
- For Levenshtein, try increasing the threshold
- Ensure terms exist in the index with sufficient frequency

### Too Many False Positives
- Increase `fuzzy_score_penalty` to rank exact matches higher
- For Levenshtein, reduce the threshold
- Reduce `max_fuzzy_variations`
- Consider using a more restrictive algorithm

### Performance Issues
- Switch from Levenshtein to Trigram or Basic
- Increase `min_term_frequency`
- Decrease `max_indexed_terms` and `max_fuzzy_variations`
- Enable caching with appropriate TTL
- Consider indexing less content

## Result Ranking

YetiSearch implements smart ranking to ensure the best matches appear first:

1. **Exact matches** always rank highest
2. **Regular matches** rank above fuzzy matches
3. **Shorter matches** rank above longer ones with the same terms
4. **Fuzzy matches** receive a configurable score penalty

This ensures that even with fuzzy matching enabled, the most relevant results appear first.

## Future Enhancements

Potential improvements to fuzzy search:

1. **Phonetic Matching**: Soundex/Metaphone for names
2. **Context-Aware Fuzzy**: Consider surrounding words
3. **Language-Specific Rules**: Different algorithms per language
4. **Machine Learning**: Learn common typos from search logs
5. **Hybrid Approaches**: Combine algorithms based on content type