# Field Boosting and Scoring in YetiSearch

## Overview

YetiSearch implements an intelligent field-weighted scoring system that goes beyond basic relevance ranking. This system ensures that exact matches in important fields (like titles or names) rank higher than partial matches in longer content fields.

## How Field Boosting Works

### Basic Field Weights

Each field can be assigned a boost value that multiplies its relevance score:

```php
'fields' => [
    'title' => ['boost' => 3.0],       // 3x multiplier
    'description' => ['boost' => 1.0],  // 1x multiplier (baseline)
    'tags' => ['boost' => 2.0],        // 2x multiplier
]
```

### High-Priority Field Detection

Fields with boost values ≥ 2.5 are considered "high-priority fields" and receive special exact match handling. This threshold-based approach means you can configure which fields get special treatment without hardcoding field names.

## Scoring Algorithm

### 1. Base BM25 Score

YetiSearch uses SQLite's FTS5 with the BM25 algorithm for initial relevance scoring. BM25 considers:
- Term frequency in the document
- Inverse document frequency across the corpus
- Document length normalization

### 2. Field Weight Application

The base score is then modified based on which fields contain the search terms:

```
field_score = base_score * field_boost * match_quality
```

### 3. Exact Match Bonuses (High-Priority Fields Only)

For fields with boost ≥ 2.5:

- **Exact Field Match**: +50 points
  - Example: Searching "Star Wars" matches a document with title="Star Wars"
  
- **Near-Exact Match**: +30 points
  - Ignores punctuation differences
  - Example: Searching "star wars" matches "Star Wars!"

- **Length Penalty**: Up to 50% reduction for longer values
  - Applied when field contains the search phrase but has additional text
  - Formula: `penalty = 1.0 - min(0.5, (field_length - phrase_length) / 100)`
  - Example: "Star Wars" scores higher than "Star Wars: Episode IV - A New Hope"

### 4. Phrase Matching

- Exact phrases receive a 15x boost over individual word matches
- Multi-word queries automatically search for both the exact phrase and individual terms
- Example: Searching "star wars" generates: `("star wars" OR star OR wars)`

## Configuration Examples

### E-commerce Product Search

```php
$config = [
    'indexer' => [
        'fields' => [
            'product_name' => ['boost' => 3.0],    // Exact product names rank highest
            'brand' => ['boost' => 2.5],           // Brand matches are important
            'category' => ['boost' => 2.0],        // Category is moderately important
            'description' => ['boost' => 1.0],     // Full descriptions have baseline weight
            'specifications' => ['boost' => 0.5],  // Technical specs are less important
        ]
    ]
];
```

### Blog/Article Search

```php
$config = [
    'indexer' => [
        'fields' => [
            'title' => ['boost' => 3.0],          // Article titles most important
            'author' => ['boost' => 2.5],         // Author names get exact match bonus
            'tags' => ['boost' => 2.0],           // Tags are moderately weighted
            'excerpt' => ['boost' => 1.5],        // Excerpts more important than body
            'content' => ['boost' => 1.0],        // Full content has baseline weight
        ]
    ]
];
```

### Knowledge Base Search

```php
$config = [
    'indexer' => [
        'fields' => [
            'question' => ['boost' => 3.5],       // FAQ questions highest priority
            'answer_summary' => ['boost' => 2.0], // Summaries moderately weighted
            'answer_detail' => ['boost' => 1.0],  // Detailed answers baseline
            'related_topics' => ['boost' => 1.5], // Related topics slightly boosted
        ]
    ]
];
```

## Best Practices

### 1. Choosing Boost Values

- **3.0+**: Primary identifier fields (titles, names, questions)
- **2.5-3.0**: Important exact-match fields (brands, authors, categories)
- **1.5-2.0**: Moderately important fields (tags, excerpts, summaries)
- **1.0**: Baseline content fields (descriptions, body text)
- **< 1.0**: De-emphasize fields (metadata, technical details)

### 2. Field Design

- Keep high-boost fields concise and meaningful
- Use separate fields for different types of content
- Consider user search intent when assigning boost values

### 3. Testing and Tuning

```php
// Test different boost configurations
$testConfigs = [
    'config1' => ['title' => 3.0, 'content' => 1.0],
    'config2' => ['title' => 5.0, 'content' => 1.0],
    'config3' => ['title' => 3.0, 'content' => 0.5],
];

foreach ($testConfigs as $name => $boosts) {
    // Index with different boost values
    // Run test queries
    // Measure result quality
}
```

## Advanced Scoring Customization

While YetiSearch's default scoring works well for most use cases, you can extend it:

### Custom Scoring Factors

```php
// Future feature: Custom scoring functions
$config = [
    'search' => [
        'custom_scoring' => function($document, $query, $baseScore) {
            // Add recency boost
            $age = time() - $document['timestamp'];
            $recencyBoost = 1 / (1 + $age / 86400); // Decay over days
            
            return $baseScore * $recencyBoost;
        }
    ]
];
```

### Dynamic Boost Adjustment

```php
// Adjust boosts based on search context
$searchContext = 'product_search'; // or 'support_search', etc.

$boostProfiles = [
    'product_search' => [
        'product_name' => 3.0,
        'price' => 0.5,
    ],
    'support_search' => [
        'question' => 3.5,
        'answer' => 1.0,
    ],
];

$indexer = $search->createIndex('content', [
    'fields' => $boostProfiles[$searchContext]
]);
```

## Troubleshooting

### Results Not Ranking as Expected

1. **Check boost values**: Ensure high-priority fields have boost ≥ 2.5
2. **Verify field content**: Exact match bonuses only apply to complete field matches
3. **Review search queries**: Multi-word queries should use quotes for exact phrases
4. **Examine document structure**: Ensure important content is in appropriately boosted fields

### Performance Considerations

- Field boosting calculation happens during result retrieval
- More fields with high boosts may slightly increase processing time
- The 2.5 threshold for exact match processing is optimized for performance

## Example: Movie Database Search

```php
// Configuration
$config = [
    'indexer' => [
        'fields' => [
            'title' => ['boost' => 3.0],
            'genres' => ['boost' => 2.0],
            'overview' => ['boost' => 1.0],
        ]
    ]
];

// Sample data
$movies = [
    ['title' => 'Star Wars', 'genres' => 'Sci-Fi', 'overview' => 'A space opera...'],
    ['title' => 'Star Wars: Episode IV', 'genres' => 'Sci-Fi', 'overview' => 'The first film...'],
    ['title' => 'Spaceballs', 'genres' => 'Comedy', 'overview' => 'A Star Wars parody...'],
];

// Search for "star wars" will rank:
// 1. "Star Wars" - Exact title match (huge bonus)
// 2. "Star Wars: Episode IV" - Title contains phrase (length penalty applied)
// 3. "Spaceballs" - Only overview contains phrase (lower boost field)
```

This intelligent scoring system ensures users find what they're looking for quickly, with the most relevant results appearing first.