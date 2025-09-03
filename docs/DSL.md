# YetiSearch DSL (Domain Specific Language)

YetiSearch now supports a powerful DSL for constructing complex search queries using either natural language syntax or URL query parameters that comply with the JSON API specification.

## DSL Query Syntax

The DSL allows you to write queries in a natural, SQL-like syntax that's easy to read and write.

### Basic Search

```
golang tutorial
```

### With Filters

```
author = "John Doe" AND status = "published"
```

### Complex Query Example

```
golang tutorial author = "Johan" AND -status IN [draft,deleted] AND (category = "tech" OR tags LIKE "%golang%") FIELDS title:post_title,author SORT -created_at PAGE 1,10
```

### Supported Operators

- **Comparison**: `=`, `!=`, `>`, `<`, `>=`, `<=`
- **Pattern Matching**: `LIKE` (with % wildcards)
- **List Operations**: `IN`, `NOT IN`
- **Logical**: `AND`, `OR`
- **Negation**: `-` prefix (e.g., `-status` negates the condition)

### Keywords

- **FIELDS**: Specify which fields to return
- **SORT**: Sort results (use `-` prefix for descending)
- **PAGE**: Pagination (page number, items per page)
- **LIMIT**: Maximum results
- **OFFSET**: Skip results
- **FUZZY**: Enable fuzzy matching
- **NEAR**: Geo proximity search
- **WITHIN**: Geo bounds search

## URL Query Parameters (JSON API Compliant)

YetiSearch also supports URL query parameters following the JSON API specification:

### Basic Query

```
?q=golang+tutorial
```

### With Filters

```
?filter[category][eq]=tech&filter[tags][in]=go,php
```

### Complete Example

```
?filter[category][eq]=tech&filter[tags][in]=go,php&sort=-date&fields=title,author.name:writer&page[limit]=15&page[offset]=30
```

### Filter Operators

- `eq`: Equal to
- `neq`, `ne`: Not equal to
- `gt`: Greater than
- `gte`: Greater than or equal
- `lt`: Less than
- `lte`: Less than or equal
- `like`: Pattern matching
- `in`: In list
- `nin`: Not in list
- `between`: Between two values
- `exists`: Field exists
- `null`: Is null
- `notnull`: Is not null

### Pagination

Two styles are supported:

1. **Offset-based**:
   ```
   ?page[limit]=20&page[offset]=40
   ```

2. **Page number-based**:
   ```
   ?page[number]=3&page[size]=20
   ```

### Sorting

Use `-` prefix for descending order:
```
?sort=-created_at,title
```

Or explicit direction:
```
?sort=created_at:desc,title:asc
```

### Field Selection

Select specific fields:
```
?fields=title,author,created_at
```

With aliases:
```
?fields[title]=headline&fields[author]=writer
```

### Geo Queries

Near a point:
```
?geo[near][lat]=37.7749&geo[near][lng]=-122.4194&geo[near][radius]=1000&geo[near][units]=m
```

Within bounds:
```
?geo[within][north]=40&geo[within][south]=30&geo[within][east]=-70&geo[within][west]=-80
```

Sort by distance:
```
?geo[sort][lat]=37.7749&geo[sort][lng]=-122.4194&geo[sort][direction]=asc
```

## PHP Usage Examples

### Using DSL Syntax

```php
use YetiSearch\YetiSearch;
use YetiSearch\DSL\QueryBuilder;

$yeti = new YetiSearch($config);
$builder = new QueryBuilder($yeti);

// Simple DSL query
$results = $builder->searchWithDSL('articles', 
    'golang author = "John" AND status = "published" SORT -created_at LIMIT 10'
);

// Complex DSL query
$results = $builder->searchWithDSL('products',
    'laptop category = "electronics" AND price > 500 AND price < 2000 ' .
    'AND brand IN [Apple, Dell, Lenovo] ' .
    'FIELDS name,price,brand,specs ' .
    'SORT price PAGE 1,20'
);
```

### Using URL Parameters

```php
// From query string
$results = $builder->searchWithURL('articles', $_SERVER['QUERY_STRING']);

// From array
$params = [
    'q' => 'golang',
    'filter' => [
        'author' => ['eq' => 'John'],
        'status' => ['in' => 'published,featured']
    ],
    'sort' => '-created_at',
    'page' => ['limit' => 10]
];

$results = $builder->searchWithURL('articles', $params);
```

### Using Fluent Interface

```php
$results = $builder->query('golang tutorial')
    ->in('articles')
    ->where('status', 'published')
    ->whereIn('category', ['tech', 'programming'])
    ->whereBetween('price', 10, 100)
    ->whereNotNull('featured_at')
    ->fields(['title', 'author', 'summary'])
    ->orderBy('created_at', 'desc')
    ->orderBy('score', 'desc')
    ->limit(20)
    ->offset(40)
    ->fuzzy(true, 0.8)
    ->highlight(true, 200)
    ->boost('title', 2.0)
    ->boost('tags', 1.5)
    ->get();

// Get just the first result
$first = $builder->query('specific term')
    ->in('articles')
    ->where('id', 123)
    ->first();

// Get count only
$count = $builder->query('golang')
    ->in('articles')
    ->where('status', 'published')
    ->count();
```

### Geo Queries with Fluent Interface

```php
// Find nearby restaurants
$results = $builder->query('pizza')
    ->in('restaurants')
    ->nearPoint(37.7749, -122.4194, 1000, 'm')
    ->sortByDistance(37.7749, -122.4194, 'asc')
    ->limit(10)
    ->get();

// Find places within bounds
$results = $builder->query('')
    ->in('places')
    ->withinBounds(40.0, 30.0, -70.0, -80.0)
    ->where('type', 'park')
    ->get();
```

### Metadata Fields Configuration

YetiSearch distinguishes between content fields (searchable text) and metadata fields (filterable/sortable attributes). Understanding this distinction is crucial for proper query construction.

#### What are Metadata Fields?

Metadata fields are document attributes stored separately from the searchable content. They are:
- Used for filtering (e.g., `author = "John"`)
- Used for sorting (e.g., `SORT -views`)
- Stored in the `metadata` array when indexing documents
- Accessed via JSON extraction in SQL queries

#### Default Metadata Fields

YetiSearch comes with a predefined list of common metadata fields that are automatically recognized:

```php
// Default metadata fields (automatically prefixed with 'metadata.')
$defaultFields = [
    'author', 'status', 'category', 'tags', 'date', 'published', 'draft', 'type',
    'created_at', 'updated_at', 'views', 'likes', 'rating', 'score', 'priority',
    'user', 'owner', 'assignee', 'reviewer', 'editor', 'price', 'cost', 'quantity',
    'stock', 'sku', 'id', 'uuid', 'slug', 'url', 'email', 'phone', 'address',
    'city', 'state', 'country', 'zip', 'lat', 'lng', 'latitude', 'longitude'
];
```

#### Configuring Custom Metadata Fields

You can customize which fields are treated as metadata in three ways:

##### 1. During QueryBuilder Construction

```php
$builder = new QueryBuilder($yetiSearch, [
    'metadata_fields' => [
        // Your custom fields
        'company', 'department', 'priority_level', 
        'custom_score', 'internal_id', 'workflow_state'
    ]
]);
```

##### 2. Using the setMetadataFields Method

```php
// Replace the entire metadata fields list
$builder->setMetadataFields([
    'author', 'status', 'company', 'department', 'custom_field'
]);
```

##### 3. Adding Individual Fields

```php
// Add fields one at a time (keeps existing fields)
$builder->addMetadataField('company');
$builder->addMetadataField('department');
$builder->addMetadataField('custom_score');
```

#### Indexing Documents with Metadata

When indexing documents, always place filterable/sortable attributes in the `metadata` array:

```php
$yetiSearch->index('products', [
    'id' => 'prod-123',
    'content' => [
        // Searchable text content
        'title' => 'Premium Wireless Headphones',
        'description' => 'High-quality audio with noise cancellation',
        'features' => 'Bluetooth 5.0, 30-hour battery life'
    ],
    'metadata' => [
        // Filterable/sortable attributes
        'price' => 299.99,
        'category' => 'electronics',
        'brand' => 'AudioTech',
        'stock' => 50,
        'rating' => 4.5,
        'release_date' => '2024-01-15',
        'on_sale' => true
    ]
]);
```

#### Using Metadata Fields in Queries

Once configured, metadata fields can be used naturally in DSL queries without the `metadata.` prefix:

```php
// DSL automatically adds metadata. prefix for recognized fields
$results = $builder->searchWithDSL('products',
    'headphones AND price < 300 AND rating >= 4 SORT -rating'
);

// URL parameters work the same way
$results = $builder->searchWithURL('products',
    'q=headphones&filter[price][lt]=300&filter[rating][gte]=4&sort=-rating'
);

// Fluent interface
$results = $builder->query('headphones')
    ->in('products')
    ->where('price', 300, '<')
    ->where('rating', 4, '>=')
    ->orderBy('rating', 'desc')
    ->get();
```

#### Manual Metadata Prefix

If a field isn't in your configured metadata fields list, you can still access it by using the explicit prefix:

```php
// For non-configured metadata fields, use explicit prefix
$results = $builder->searchWithDSL('products',
    'metadata.custom_attribute = "special"'
);
```

#### Important Notes

1. **Performance**: Metadata fields are extracted from JSON at query time, which may be slower than indexed columns for very large datasets.

2. **Type Casting**: Numeric comparisons (>, <, >=, <=) automatically cast values to REAL in SQL. Ensure your metadata values are stored as numbers, not strings.

3. **Content vs Metadata**: 
   - Content fields are indexed for full-text search
   - Metadata fields are for filtering and sorting
   - Don't duplicate data between content and metadata

4. **Direct Columns**: The following fields are always treated as direct database columns (not metadata):
   - `type`, `language`, `id`, `timestamp`

### Field Aliases

In addition to metadata field configuration, you can create aliases for field names:

```php
$builder->setFieldAliases([
    'writer' => 'author',
    'headline' => 'title',
    'published' => 'published_at'
]);

// Now you can use aliases in queries
$results = $builder->searchWithDSL('articles',
    'writer = "John" AND published > "2024-01-01"'
);
```

Field aliases work in combination with metadata field configuration. If an aliased field maps to a metadata field, it will be properly prefixed.

### Integration with YetiSearch

The DSL is fully integrated with YetiSearch's existing features:

```php
use YetiSearch\YetiSearch;
use YetiSearch\DSL\QueryBuilder;

// Initialize YetiSearch
$config = [
    'storage' => ['path' => 'search.db'],
    'search' => [
        'enable_fuzzy' => true,
        'enable_suggestions' => true
    ]
];

$yeti = new YetiSearch($config);

// Create QueryBuilder
$builder = new QueryBuilder($yeti, [
    'field_aliases' => [
        'writer' => 'author',
        'headline' => 'title'
    ],
    'default_limit' => 20,
    'max_limit' => 1000
]);

// Use any query style
$dslResults = $builder->searchWithDSL('articles', 'your DSL query');
$urlResults = $builder->searchWithURL('articles', $_GET);
$fluentResults = $builder->query('term')->in('articles')->get();
```

## CLI Usage

The YetiSearch CLI has been updated to support DSL queries:

```bash
# Using DSL syntax
bin/yetisearch search --index=articles --dsl='author = "John" AND status = "published" SORT -created_at LIMIT 10'

# Using URL parameters
bin/yetisearch search --index=articles --url='filter[author][eq]=John&sort=-created_at&page[limit]=10'
```

## Advanced Examples

### Combining Multiple Features

```php
// Complex e-commerce search
$results = $builder->query('wireless headphones')
    ->in('products')
    ->where('category', 'electronics')
    ->whereBetween('price', 50, 300)
    ->whereIn('brand', ['Sony', 'Bose', 'Apple'])
    ->whereNotNull('in_stock')
    ->where('rating', 4, '>=')
    ->fields(['name', 'price', 'brand', 'rating', 'image_url'])
    ->facet('brand')
    ->facet('price_range', [
        'ranges' => [
            ['to' => 100],
            ['from' => 100, 'to' => 200],
            ['from' => 200]
        ]
    ])
    ->orderBy('rating', 'desc')
    ->orderBy('price', 'asc')
    ->fuzzy(true, 0.85)
    ->highlight(true)
    ->limit(24)
    ->get();
```

### Building Dynamic Queries

```php
$query = $builder->query();

// Add base query
if ($searchTerm = $_GET['q'] ?? null) {
    $query->search($searchTerm);
}

// Add filters dynamically
if ($category = $_GET['category'] ?? null) {
    $query->where('category', $category);
}

if ($minPrice = $_GET['min_price'] ?? null) {
    $query->where('price', $minPrice, '>=');
}

if ($tags = $_GET['tags'] ?? null) {
    $query->whereIn('tags', explode(',', $tags));
}

// Add geo filter if location provided
if (isset($_GET['lat'], $_GET['lng'])) {
    $query->nearPoint($_GET['lat'], $_GET['lng'], 5000, 'm');
}

// Execute
$results = $query->in('products')->get();
```

## Error Handling

The DSL parser provides helpful error messages:

```php
try {
    $results = $builder->searchWithDSL('articles', 'invalid >>> query');
} catch (\YetiSearch\Exceptions\InvalidArgumentException $e) {
    echo "Query parse error: " . $e->getMessage();
}
```

## Performance Considerations

1. **Field Selection**: Always specify only the fields you need using `FIELDS` or `fields()` to reduce data transfer.

2. **Pagination**: Use appropriate page sizes. Large result sets should be paginated.

3. **Fuzzy Search**: Fuzzy matching has a performance cost. Use it judiciously.

4. **Filters**: Filters are applied efficiently at the database level. Use them to narrow results before scoring.

5. **Sorting**: Sorting by indexed fields is faster than computed fields.

## Migration Guide

If you're currently using YetiSearch without the DSL:

### Before (Direct API)
```php
$results = $yeti->search('articles', 'golang', [
    'filters' => [
        ['field' => 'author', 'value' => 'John', 'operator' => '='],
        ['field' => 'status', 'value' => 'published', 'operator' => '=']
    ],
    'sort' => ['created_at' => 'desc'],
    'limit' => 10
]);
```

### After (With DSL)
```php
// Option 1: DSL String
$results = $builder->searchWithDSL('articles',
    'golang author = "John" AND status = "published" SORT -created_at LIMIT 10'
);

// Option 2: Fluent Interface
$results = $builder->query('golang')
    ->in('articles')
    ->where('author', 'John')
    ->where('status', 'published')
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();
```

Both the original API and new DSL methods are supported, so you can migrate gradually.