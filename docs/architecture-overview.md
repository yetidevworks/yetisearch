# Architecture Overview

This library exposes a small facade (`YetiSearch`) over clear, testable components. The diagram shows indexing and query paths and where extensions plug in.

```mermaid
flowchart LR
  App[Application / Client] --> YetiSearch
  YetiSearch --> Indexer
  YetiSearch --> SearchEngine
  Indexer --> Analyzer
  Analyzer --> Stemmer
  Indexer --> Storage[(SQLite FTS5, external-content)]
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
- Indexing: documents -> Analyzer/Stemmer -> chunks -> stored in docs; FTS5 content stored by `rowid = doc_id` (external-content).
- Searching: query -> analyzer -> FTS5 BM25 -> scoring/boosting -> optional fuzzy expansion -> results/Facets -> Models.

Notes
- Default schema uses external-content FTS5 (`content='<index>', content_rowid='doc_id'`) and R-tree keyed by `doc_id`.
- Legacy mode (string `id` as PK + `id_map`) remains supported for backward compatibility.
- Configuration controls field boosts, chunking, fuzzy behavior, SQLite pragmas, and schema mode.
- See `README.md` for migration commands and examples.

### Why we don’t use SQLite triggers for FTS5
- External-content FTS5 is kept in sync explicitly by application code (insert/update/delete write both the `{index}` row and `{index}_fts` row with `rowid = doc_id`). We also provide a full rebuild path.
- Triggers are possible, but we avoid them for these reasons:
  - Performance: bulk indexing would pay per-row trigger costs (multiple `json_extract` and FTS writes) vs our prepared, batched path.
  - Flexibility: FTS columns are dynamic per index; triggers would need regeneration on any field set change and complicate migrations.
  - Double-write hazards: app code and triggers could conflict unless guarded by additional config and branches.
  - Portability: relying on JSON1 in triggers isn’t guaranteed in all SQLite builds; our code path doesn’t require it.
- Net result: explicit, code-driven sync is faster, easier to evolve, and simpler to debug for this project’s needs.
