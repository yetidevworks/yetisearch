# Architecture Overview

This library exposes a small facade (`YetiSearch`) over clear, testable components. The diagram shows indexing and query paths and where extensions plug in.

```mermaid
flowchart LR
  App[Application / Client] --> YetiSearch
  YetiSearch --> Indexer
  YetiSearch --> SearchEngine
  Indexer --> Analyzer
  Analyzer --> Stemmer
  Indexer --> Storage[(SQLite FTS5)]
  SearchEngine --> Storage
  SearchEngine --> Fuzzy[Utils: Trigram / Jaro-Winkler / Levenshtein]
  Storage --> Cache[FuzzyTermCache]
  YetiSearch --> Models[Models: Query / Results]
  Geo[Geo (R*-Tree helpers)] --> Storage
```

ASCII alternative (if Mermaid isn’t rendered):

```
App -> YetiSearch -> { Indexer -> Analyzer -> Stemmer -> Storage(FTS5)
                      SearchEngine -> Storage + Fuzzy Utils }
                -> Models(Query/Results); Geo helpers -> Storage
```

## Key Components
- YetiSearch: Entry point and configuration (`src/YetiSearch.php`).
- Indexer: Builds/updates FTS5 indexes (`src/Index/Indexer.php`).
- SearchEngine: Executes queries, highlighting, faceting, de-dup (`src/Search/SearchEngine.php`).
- Storage: SQLite persistence and FTS5 operations (`src/Storage/SqliteStorage.php`).
- Analyzer/Stemmer: Tokenization, normalization, language stemming (`src/Analyzers/`, `src/Stemmer/`).
- Utils: Similarity algorithms and helpers (`src/Utils/`).
- Geo: Bounds/point utilities; integrates with R-tree tables (`src/Geo/`).
- Models: Query and result DTOs (`src/Models/`).

## Data Flow
- Indexing: documents -> Analyzer/Stemmer -> chunks -> FTS5 rows.
- Searching: query -> analyzer -> FTS5 BM25 -> scoring/boosting -> optional fuzzy expansion -> results/Facets -> Models.

Notes
- Configuration controls field boosts, chunking, fuzzy behavior, and SQLite pragmas.
- See `README.md` for advanced options and examples.
