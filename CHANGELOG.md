# Changelog

## [2.0.0] - 2025-09-01

### Fuzzy Search UX & Performance
- Added `fuzzy_last_token_only` to focus typo tolerance on the final term (ideal for as‑you‑type search).
- Adaptive n‑gram: trigram search now uses bigrams for short tokens to improve recall on short typos.
- Levenshtein prefiltering: added length/edge‑char/bigram gating to cut false positives and speed up distance checks.
- Similarity‑aware scoring maintained: fuzzy penalty scales by similarity/distance.

### Type‑Ahead & Prefix Support
- Optional `prefix_last_token` to apply `*` to the last token (requires FTS5 prefix indexes).
- New migration script `scripts/migrate_fts.php` to rebuild an index with multi‑column FTS and optional prefix settings.

### Storage & Ranking
- Optional multi-column FTS5: `indexer.fts.multi_column=true` stores per-field text and enables weighted `bm25(fts, w_title, w_content, ...)` from field boosts.
- Optional FTS5 prefix indexing: `indexer.fts.prefix=[2,3]` for strict prefix matches.
- Backward compatible: single-column `content` remains default; schema only changes when opting in.

### External-Content Schema (Doc ID)
- Added first-class support for an external-content schema with integer `doc_id` primary keys and `id TEXT UNIQUE` mapping.
- FTS5 tables now support `content='<index>'` and `content_rowid='doc_id'` modes for better performance and clarity.
- Migration helper: `SqliteStorage::migrateToExternalContent()` converts legacy indices, recreates spatial tables, and rebuilds FTS.
- Tests cover external-content schema creation, migration, and geo queries.

### Geo Search
- Accurate distances: Haversine great‑circle distance (meters) when SQLite math functions are available; fallback to planar approximation otherwise.
- SQL radius filtering: `near` now filters by radius in SQL using the computed distance.
- Dateline handling: bounds crossing the antimeridian (west > east) correctly include both sides.
- Tests: added integration tests for Haversine accuracy and dateline‑crossing bounds.
- k‑Nearest Neighbors (k‑NN): `geoFilters.nearest` returns the k closest documents by distance, with optional `max_distance` clamp and units.
- Distance facets: request `facets.distance` with `from`, `ranges`, and optional `units` to get bucketed counts (e.g., `<= 1 km`, `<= 5 km`, ...).
- Candidate cap: `geoFilters.candidate_cap` limits R-tree candidates for PHP-side distance sorting.
- Result metadata: add `distance_units`, `bearing`, and `bearing_cardinal` (when distance context is available).
 - Fix: R-tree availability probe corrected (valid 2D table) so environments with R-tree are detected properly.
 - Fix: post-filter handling of `near.radius` respects `geoFilters.units` (km/mi/meters) in PHP-side filtering.

### Docs & Examples
- README: Type‑Ahead Setup, Weighted FTS + Prefix sections with examples.
- Added `docs/architecture-overview.md`.
- Added `AGENTS.md` contributor guide.
- README (Geo): Units, composite scoring, distance facets, and k‑NN usage.
- Examples: `examples/geo-facets-knn.php` (distance facets + nearest demo).
- Benchmarks: `benchmarks/geo-benchmark.php` now supports units and `iters`; optional facets output via extra arg.

### Suggestions
- Smarter ranking for `suggest()`: aggregates across variants, boosts titles that contain or start with the variant.
- New options: `limit`, `per_variant`, `title_boost`, `prefix_boost`.

### Synonyms
- Query‑time synonyms expansion: enable via `search.enable_synonyms` and provide a map in `search.synonyms` (array or JSON file).
- Supports multi‑word synonyms (added as quoted phrases), case‑insensitive by default.
- Limits expansions with `search.synonyms_max_expansions` to protect performance.

### Tests & Benchmarks
- Integration tests for fuzzy algorithms and as‑you‑type mode.
- New coverage for geo Haversine accuracy and dateline-crossing bounds (no longer skipped when R-tree is available).
- External-content tests: schema verification, migration, geo distance, and mixed-mode behaviors.
- Indexer tests: chunking, stored-only fields, queued inserts with manual flush, update/delete in legacy and external schemas, rebuild and stats.
- Storage tests: metadata JSON filters for `=, !=, >, <, >=, <=, in, contains, exists`; multi-index merged search.
- SearchEngine tests: distance weighting influence, route de-duplication, suggestions path, distance facets path.

### Tooling & Dev Experience
- Deep-merge configuration in `YetiSearch` so nested options override safely without dropping defaults.
- Makefile targets for coverage: `test-coverage`, `test-coverage-html`, `test-coverage-clover`, `coverage-top`, and `coverage-info`.
- Helper script `scripts/coverage_top_gaps.php` to print lowest-covered files from Clover.
- Local evaluation script: `benchmarks/fuzzy-eval.php`.

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
