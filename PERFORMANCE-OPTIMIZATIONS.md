# Performance Optimizations

This document summarizes practical ways to improve YetiSearch performance and where the code supports them.

## Quick Wins

- Candidate cap (geo + FTS): when sorting by distance with FTS filtering, set a candidate cap to limit the PHP‑side resort.
  - Dynamic default: ~10–20× `limit` (capped). Configure via `geoFilters.candidate_cap`.
- Minimize payload: avoid `SELECT d.*` when possible; fetch only needed columns for a given path (score, id, content, metadata, distance).
- Reuse computed distance: compute the Haversine expression once and reuse for WHERE + ORDER BY (subquery/alias) to avoid recomputation.
- SQLite pragmas: WAL, cache size, temp_store, and mmap are enabled. `PRAGMA optimize` runs to improve plans.

## Fuzzy & Synonyms Shaping

- Token budget: cap total expansions to avoid explosion (`synonyms_max_expansions` + per‑query total cap). Prefer discriminative tokens.
- As‑you‑type: use `fuzzy_last_token_only` + `prefix_last_token` and keep earlier tokens exact. Disable synonyms on the last token where appropriate.

## Geo Pipeline

- Haversine: accurate distances when math functions are available; fallback to planar approximation otherwise.
- k‑NN fast path: use nearest without FTS when `nearest` is requested; fetch only required columns.
- Antimeridian: bounds split into two ranges when crossing ±180°.

## Storage & Indexing

- Contentless FTS5 (optional): for large datasets, consider an external-content FTS5 schema to reduce I/O. Requires a migration.
- Post‑index optimize: `Indexer::optimize()` triggers FTS optimize + VACUUM + ANALYZE.

## Benchmarks & Tooling

- Geo benchmark supports units and iterations (`iters`) for stable averages; optional facets mode prints distance buckets.
- Use `rg` (ripgrep) and `sd` (fast sed) + Makefile helpers for quick code changes.

