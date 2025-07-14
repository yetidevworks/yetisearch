# Levenshtein-Based Fuzzy Search in YetiSearch

## Overview

YetiSearch now supports Levenshtein distance-based fuzzy search, providing more accurate typo tolerance compared to the basic fuzzy search implementation. This feature helps find matches even when search terms contain multiple character errors.

## What is Levenshtein Distance?

Levenshtein distance (edit distance) measures the minimum number of single-character edits (insertions, deletions, or substitutions) required to change one word into another.

Examples:
- "Anakin" → "Amakin" = distance 1 (substitute 'n' with 'm')
- "Skywalker" → "Dkywalker" = distance 1 (substitute 'S' with 'D')
- "programming" → "porgramming" = distance 2 (insert 'r', delete 'r')

## Configuration

To enable Levenshtein-based fuzzy search, add these settings to your YetiSearch configuration:

```php
$config = [
    'search' => [
        'enable_fuzzy' => true,                  // Enable fuzzy search
        'fuzzy_algorithm' => 'levenshtein',      // Use Levenshtein (default: 'basic')
        'levenshtein_threshold' => 2,            // Maximum edit distance (default: 2)
        'min_term_frequency' => 2,               // Min frequency for candidate terms (default: 2)
        'max_fuzzy_variations' => 10,            // Max variations per term (default: 10)
        'fuzzy_score_penalty' => 0.5            // Score penalty for fuzzy matches (default: 0.5)
    ]
];
```

### Configuration Options Explained

- **`fuzzy_algorithm`**: Choose between 'levenshtein' or 'basic'
  - `'levenshtein'`: Uses edit distance to find similar indexed terms
  - `'basic'`: Original implementation using character deletions/wildcards

- **`levenshtein_threshold`**: Maximum allowed edit distance
  - `1`: Only very close matches (single typo)
  - `2`: Moderate tolerance (recommended)
  - `3+`: Very permissive (may return many false positives)

- **`min_term_frequency`**: Minimum times a term must appear to be considered
  - Higher values = faster search but may miss rare terms
  - Lower values = more comprehensive but slower

- **`max_fuzzy_variations`**: Limits the number of fuzzy matches per search term
  - Prevents performance issues with common typos

- **`fuzzy_score_penalty`**: Score reduction factor for fuzzy matches
  - `0.5` = fuzzy matches get 50% of the original score
  - `1.0` = no penalty (not recommended)
  - `0.0` = maximum penalty

## Usage

```php
// Search with fuzzy matching enabled
$results = $search->search('movies', 'Amakin Dkywalker', [
    'fuzzy' => true
]);

// The fuzziness parameter (0.0-1.0) is accepted but not used
// with Levenshtein algorithm - threshold is used instead
$results = $search->search('movies', 'porgramming', [
    'fuzzy' => true,
    'fuzziness' => 0.8  // Ignored with Levenshtein
]);
```

## How It Works

1. **Term Analysis**: When fuzzy search is enabled, each search term is analyzed
2. **Candidate Generation**: The system queries indexed terms from the database
3. **Distance Calculation**: Levenshtein distance is calculated for each candidate
4. **Filtering**: Only terms within the threshold distance are kept
5. **Ranking**: Candidates are sorted by distance and similarity
6. **Query Expansion**: The original query is expanded with fuzzy variations
7. **Scoring**: Results matching fuzzy terms receive a score penalty

## Performance Considerations

### Advantages
- More accurate than basic fuzzy search
- Finds matches with substitutions, insertions, and deletions
- Works well for common typos and misspellings

### Trade-offs
- Slower than basic fuzzy search due to distance calculations
- Requires querying indexed terms from the database
- Performance depends on the number of unique terms in the index

### Optimization Tips

1. **Adjust `min_term_frequency`**: Higher values reduce candidates but may miss rare terms
2. **Limit `max_fuzzy_variations`**: Prevents explosion of search terms
3. **Use appropriate `levenshtein_threshold`**: Lower is faster but less tolerant
4. **Consider index size**: Works best with focused indexes rather than massive datasets

## Comparison: Basic vs Levenshtein

| Feature | Basic Fuzzy | Levenshtein Fuzzy |
|---------|-------------|-------------------|
| Character deletion | ✓ | ✓ |
| Character insertion | ✗ | ✓ |
| Character substitution | ✗ | ✓ |
| Transposition | ✓ (adjacent only) | ✓ |
| Wildcards | ✓ | ✗ |
| Performance | Fast | Moderate |
| Accuracy | Low | High |

## Examples

### Example 1: Name Typos
```php
// Query: "Amakin Dkywalker" 
// Finds: Documents containing "Anakin Skywalker"
// Distance: 1 for each word
```

### Example 2: Programming Typos
```php
// Query: "porgramming langauge"
// Finds: Documents containing "programming language"
// Distance: 2 for "porgramming", 2 for "langauge"
```

### Example 3: Multiple Errors
```php
// Query: "Pyhton developmnet"
// Finds: Documents containing "Python development"
// Distance: 2 for "Pyhton", 2 for "developmnet"
```

## Limitations

1. **Distance Threshold**: Very different words won't match even if they sound similar
2. **No Phonetic Matching**: "Smith" won't match "Smythe" unless within edit distance
3. **Case Sensitivity**: Depends on analyzer configuration
4. **Performance**: Can be slow with large vocabularies

## Future Enhancements

Potential improvements to the Levenshtein implementation:

1. **Cached Distance Calculations**: Store frequently calculated distances
2. **Phonetic Fallback**: Use Soundex/Metaphone for names
3. **Weighted Edit Operations**: Different costs for different types of edits
4. **Context-Aware Scoring**: Consider word positions and surrounding terms
5. **Dictionary Integration**: Common misspellings database

## Troubleshooting

### No Fuzzy Matches Found
- Check if `fuzzy_algorithm` is set to `'levenshtein'`
- Verify `levenshtein_threshold` is appropriate (try increasing to 3)
- Ensure `min_term_frequency` isn't too high
- Confirm the terms exist in the index

### Too Many False Positives
- Reduce `levenshtein_threshold` to 1
- Increase `fuzzy_score_penalty` to rank exact matches higher
- Use more specific search terms

### Performance Issues
- Increase `min_term_frequency` to reduce candidates
- Decrease `max_fuzzy_variations`
- Consider using basic fuzzy for large datasets
- Index fewer unique terms