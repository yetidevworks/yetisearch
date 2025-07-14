# Changelog

## [1.1.0] - 2025-06-14

#### Enhanced Fuzzy Search Capabilities
- **New default algorithm:** Changed default fuzzy matching to trigram algorithm for better accuracy
- **Multiple fuzzy algorithms:** Added support for various matching algorithms including:
  - Trigram matching (now default)
  - Jaro-Winkler distance
  - Levenshtein distance
- **Flexible fuzzy toggle:** Added ability to easily enable/disable fuzzy matching for standard searches
- **Algorithm benchmarking:** Added performance testing tools to compare different fuzzy algorithms

#### Search Quality Improvements
- **Better multi-word matching:** Enhanced handling of multi-word queries for more accurate results
- **Short text matching:** Improved flexibility for matching short text queries
- **Match preference:** Added logic to prefer shorter, more exact matches over longer partial matches
- **Regular vs fuzzy priority:** Implemented result ranking that prioritizes exact matches over fuzzy matches

#### Performance Optimizations
- **Weight application:** Fixed and improved weight calculation for better relevance scoring
- **Performance enhancements:** Various optimizations to fuzzy search performance
- **Refactored fuzzy implementation:** Major refactor to improve fuzzy search capability and maintainability
- **Switched to local UTF-8 helper and stemmer:** Improved performance and PHP 8.4 compatibility by using local classes instead of external libraries

#### Technical Updates
- **API clarity:** Changed method name from ->index() to ->insert() for better API clarity
- **Test improvements:** Enhanced test coverage and fixed existing tests
- **Documentation updates:** Updated documentation to reflect new fuzzy search capabilities and performance improvements

## [1.0.2] - 2025-06-11

- **LICENSE file added**: Forgot to include the LICENSE file in the initial release. This has now been added to clarify the licensing terms for YetiSearch.

## [1.0.1] - 2025-06-11

- **More Coverage Tests**: Added additional tests to cover more edge cases and ensure robustness.

## [1.0.0] - 2025-06-11

### Summary

YetiSearch is a powerful, pure-PHP search engine library designed for modern PHP applications. This initial release provides a complete full-text search solution with advanced features typically found only in dedicated search servers, all while maintaining the simplicity of a PHP library with zero external service dependencies.

### Core Features

#### Search Capabilities
- **Full-text search** powered by SQLite FTS5 with BM25 relevance scoring
- **Multi-index search** - Search across multiple indexes simultaneously with pattern matching
- **Smart result deduplication** - Shows best match per document by default
- **Search highlighting** with customizable tags
- **Fuzzy matching** for typo-tolerant searches
- **Faceted search** and aggregations support
- **Advanced filtering** with multiple operators (=, !=, <, >, <=, >=, in, contains, exists)

#### Document Processing
- **Automatic document chunking** for indexing large documents
- **Configurable chunk sizes and overlap** for optimal search results
- **Field-specific boosting** to prioritize important content
- **Metadata support** for non-indexed document properties

#### Language Support
- **Multi-language stemming** for 11 languages including English, French, German, Spanish, Italian, Portuguese, Dutch, Swedish, Norwegian, Danish, and Russian
- **Custom stop words** configuration in addition to language defaults
- **Language-aware text analysis** with proper tokenization

#### Geographic Search
- **Geo-spatial search** capabilities using SQLite R-tree indexing
- **Radius search** - Find documents within a specified distance
- **Bounding box search** - Search within geographic boundaries
- **Distance-based sorting** for location-aware results
- **Support for both point and area indexing**

#### Architecture & Performance
- **Zero external dependencies** - No separate search server required
- **SQLite-based storage** with optimized schema design
- **Batch indexing** support for efficient bulk operations
- **Configurable caching** for improved query performance
- **Transaction support** for data integrity
- **Index optimization** capabilities

### Technical Specifications

#### Requirements
- PHP 7.4 or higher (tested up to PHP 8.3)
- SQLite3 PHP extension
- PDO PHP extension with SQLite driver
- Mbstring PHP extension
- JSON PHP extension

#### Storage Configuration
- SQLite with Write-Ahead Logging (WAL) for better concurrency
- Configurable connection and busy timeouts
- Memory-based temporary tables option
- Automatic database management

#### API Design
- **PSR-4 autoloading** compliant
- **PSR-3 logging** support
- Clean interface-based architecture for extensibility
- Comprehensive exception handling
- Fluent query builder interface

[1.0.0]: https://github.com/yetidevworks/yetisearch/releases/tag/v1.0.0