# Migration Guide: 2.0.0

This release focuses on stability, performance, and clearer defaults. Most apps upgrade without code changes; a few options are renamed but backward-compatible aliases are provided.

## Highlights
- External-content schema is the default (FTS5 with `content='<index>'`, `content_rowid='doc_id'`). Legacy mode remains supported.
- Windows-friendly geo fallback: when SQLite lacks RTree, geo is stored in metadata (`_geo`, `_geo_bounds`) and distance/near work via JSON expressions.
- No FTS5 triggers: indexing sync remains explicit in code for performance and flexibility. See Architecture notes.

## Behavior Changes
- Default storage: `storage.external_content = true`. If you require legacy layout, set `storage.external_content = false` when constructing `YetiSearch`.
- Scoring: minor normalization; scores continue in the same numeric ranges but may differ slightly due to distance handling improvements.

## Option Renames and Aliases
YetiSearch accepts both old and new names to ease migration:

- Geo options
  - Prefer: `options['geo']` over `options['geoFilters']` (both are accepted).
  - Units: `m`, `km`, or `mi`.

- Fuzzy options
  - New nested form (optional):
    ```php
    $options['fuzzy'] = [
      'enabled' => true,                 // same as options['fuzzy'] = true
      'algorithm' => 'trigram',          // maps to fuzzy_algorithm
      'last_token_only' => true,         // maps to fuzzy_last_token_only
      'prefix_last_token' => true,       // maps to prefix_last_token
      'penalty' => 0.3,                  // maps to fuzzy_score_penalty
      'jaro_winkler' => [
        'threshold' => 0.92,
        'prefix_scale' => 0.1,
      ],
      'levenshtein' => [ 'threshold' => 2 ],
      'trigram' => [ 'threshold' => 0.5, 'size' => 3 ],
    ];
    ```
  - The legacy flat keys (e.g., `fuzzy_algorithm`, `fuzzy_last_token_only`) remain supported.

## Verifying Your Index
Use the CLI helpers in 2.0:
- `bin/yetisearch index:verify --index=IDX` – checks core tables and row counts.
- `bin/yetisearch index:rebuild --index=IDX` – drops and repopulates FTS from stored docs.
- `bin/yetisearch debug:query --index=IDX --query='...'` – prints the SQL and parameters.

## Benchmarks
`make bench-compare` now also writes `benchmarks/benchmark-compare.json` with indexing metrics and per-query diffs for automation.

