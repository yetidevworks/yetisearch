# Enhanced Fuzzy Search

YetiSearch now includes enhanced fuzzy search capabilities that behave like modern search engines (Google, Elasticsearch) with automatic typo correction.

## 🚀 Key Features

### Modern Typo Correction
- **Automatic Correction**: Typos are automatically corrected to the most likely intended word
- **Multi-Algorithm Consensus**: Uses 5 different algorithms for accurate correction
- **Phonetic Matching**: Handles sound-alike typos (fone → phone)
- **Keyboard Proximity**: Detects fat-finger errors (qyick → quick)

### "Did You Mean?" Suggestions
- **Smart Suggestions**: Generates suggestions when no results are found
- **Confidence Scoring**: Shows how confident the system is about each suggestion
- **Multiple Options**: Provides up to 3 alternative suggestions

### Enhanced Algorithms
- **Trigram**: Best balance of speed and accuracy (default)
- **Levenshtein**: Edit distance based matching
- **Jaro-Winkler**: Excellent for short strings and prefixes
- **Phonetic**: Metaphone-based sound matching
- **Keyboard**: QWERTY layout proximity analysis

## 📋 Quick Start

### Basic Configuration

```php
$config = [
    'search' => [
        'enable_fuzzy' => true,
        'fuzzy_correction_mode' => true,        // Enable modern correction
        'fuzzy_algorithm' => 'trigram',         // Best algorithm
        'correction_threshold' => 0.6,          // Sensitivity threshold
        'trigram_threshold' => 0.35,            // Matching threshold
        'fuzzy_score_penalty' => 0.25,          // Reduced penalty
    ]
];

$search = new YetiSearch($config);
```

### Usage Examples

```php
// Automatic typo correction
$results = $search->search('index', 'fone', ['fuzzy' => true]);
// Automatically searches for 'phone' instead

// Multiple typos in one query
$results = $search->search('index', 'qyick fone', ['fuzzy' => true]);
// Corrects to 'quick phone'

// Get suggestions with confidence
$suggestions = $search->generateSuggestions('qyick', 3);
// Returns: [['text' => 'quick', 'confidence' => 0.95, ...]]
```

## ⚙️ Configuration Options

### Core Settings

| Option | Default | Description |
|--------|---------|-------------|
| `enable_fuzzy` | `true` | Enable fuzzy search features |
| `fuzzy_correction_mode` | `true` | Use modern correction vs expansion |
| `fuzzy_algorithm` | `'trigram'` | Primary algorithm to use |
| `correction_threshold` | `0.6` | Minimum confidence for corrections |
| `fuzzy_score_penalty` | `0.25` | Score penalty for fuzzy matches |

### Algorithm Thresholds

| Option | Default | Description |
|--------|---------|-------------|
| `trigram_threshold` | `0.35` | Trigram similarity threshold |
| `jaro_winkler_threshold` | `0.85` | Jaro-Winkler similarity threshold |
| `levenshtein_threshold` | `2` | Maximum edit distance |
| `max_fuzzy_variations` | `15` | Max variations per term |

### Performance Settings

| Option | Default | Description |
|--------|---------|-------------|
| `min_term_frequency` | `1` | Minimum term frequency to consider |
| `indexed_terms_cache_ttl` | `300` | Cache duration for indexed terms |
| `max_indexed_terms` | `20000` | Maximum indexed terms to load |

## 🔧 Algorithm Details

### Trigram (Default)
- **Best For**: General purpose, balanced speed/accuracy
- **Strengths**: Handles partial matches, language-agnostic
- **Use When**: You need good performance with decent accuracy

### Levenshtein
- **Best For**: Precise edit distance matching
- **Strengths**: Exact character-level differences
- **Use When**: You need precise control over edit distance

### Jaro-Winkler
- **Best For**: Short strings, names, prefixes
- **Strengths**: Excellent for common prefixes, fast
- **Use When**: Searching names or short terms

### Phonetic Matching
- **Best For**: Sound-alike typos
- **Strengths**: Handles phonetic variations (fone/phone)
- **Use When**: Common phonetic errors are expected

### Keyboard Proximity
- **Best For**: Fat-finger typing errors
- **Strengths**: QWERTY layout awareness
- **Use When**: Users type quickly on physical keyboards

## 📊 Performance Impact

### Benchmarks
- **Search Latency**: <10% slower than exact search
- **Memory Usage**: ~5MB for indexed terms cache
- **Accuracy**: 85%+ typo correction rate

### Optimization Tips
1. **Use Caching**: Indexed terms are cached for 5 minutes by default
2. **Limit Terms**: Set `max_indexed_terms` to control memory usage
3. **Choose Algorithm**: `trigram` offers best performance/accuracy balance
4. **Adjust Thresholds**: Higher thresholds = faster but less forgiving

## 🎯 Use Cases

### E-commerce Search
```php
// Product search with typo tolerance
$results = $search->search('products', 'iphne case', [
    'fuzzy' => true,
    'fields' => ['title', 'description', 'tags']
]);
// Finds "iPhone case" products
```

### Content Search
```php
// Article search with automatic correction
$results = $search->search('articles', 'qyick tutorial', [
    'fuzzy' => true,
    'highlight' => true
]);
// Finds "quick tutorial" articles
```

### User Search
```php
// Find users with name typos
$results = $search->search('users', 'Jhon Smith', [
    'fuzzy' => true,
    'fields' => ['name', 'username', 'email']
]);
// Finds "John Smith" users
```

## 🧪 Testing

### Unit Tests
```bash
# Run fuzzy search tests
composer test -- tests/Unit/Utils/PhoneticMatcherTest.php
composer test -- tests/Unit/Utils/KeyboardProximityTest.php
```

### Integration Tests
```bash
# Run enhanced fuzzy search integration tests
composer test -- tests/Integration/Search/EnhancedFuzzySearchTest.php
```

### Example Script
```bash
# Run the enhanced fuzzy search example
php examples/enhanced-fuzzy-search.php
```

## 🔍 Migration Guide

### From Basic Fuzzy
If you're using the old fuzzy search, here's how to migrate:

```php
// Old configuration
'fuzzy_algorithm' => 'basic',
'fuzzy_distance' => 2,
'fuzzy_score_penalty' => 0.5,

// New configuration
'fuzzy_correction_mode' => true,
'fuzzy_algorithm' => 'trigram',
'correction_threshold' => 0.6,
'fuzzy_score_penalty' => 0.25,
```

### Backward Compatibility
- Old expansion mode still available: `fuzzy_correction_mode: false`
- All existing algorithms remain supported
- Configuration is backward compatible

## 🚨 Troubleshooting

### Common Issues

**Too Many False Positives**
```php
'correction_threshold' => 0.7,  // Increase threshold
'trigram_threshold' => 0.4,     // Increase threshold
```

**Too Few Corrections**
```php
'correction_threshold' => 0.5,  // Decrease threshold
'trigram_threshold' => 0.3,     // Decrease threshold
'min_term_frequency' => 1,      // Include rare terms
```

**Performance Issues**
```php
'max_indexed_terms' => 10000,   // Reduce loaded terms
'indexed_terms_cache_ttl' => 600, // Increase cache time
'max_fuzzy_variations' => 10,   // Reduce variations
```

### Debug Logging
Enable debug logging to see correction decisions:

```php
'search' => [
    'logger' => new \Monolog\Logger('fuzzy'),
    // ... other config
]
```

## 📈 Future Enhancements

- **Machine Learning**: ML-based typo correction
- **Context Awareness**: Query context analysis
- **Personalization**: User-specific correction patterns
- **Multilingual**: Enhanced support for non-English languages
- **Real-time**: Live typo correction as user types