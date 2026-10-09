# Changelog

## [2.6.0] - 2026-10-09

### New Features
- **Stemming that search uses** ([#50](https://github.com/yetidevworks/yetisearch/issues/50)): The four language stemmers were never wired in: the indexer analyzed every field and threw the stems away, the FTS5 table indexed the raw text, and a query was never stemmed, so `connect` did not find "connected", `runs` did not find "running" and `chanson` did not find "chansons". An index created with `'stemming' => true` (and optionally `'language' => 'fr'`, the language it stems in for a document or query that names none, English by default) now keeps the stems of its text in a `_stems` FTS5 column, and a query matches a word as typed or by its stem, in the language of the document or query, else of the index. With typo correction (fuzzy search), a word that was corrected also matches the stem of the word as typed: `runs`, corrected to `rugs`, finds "rugs" and what `runs` finds with fuzzy off, such as "running". It works in both schema modes, external content and own-content with one column or a column per field. Stemming is opt-in and fixed when the index is created: `createIndex()` on an index that exists leaves its stemming alone. A field named `_stems` is refused on an index that stems, as are the FTS5 options `detail = none` and `detail = column`: a stem with a hyphen in it (a hyphenated word, or what a custom stemmer makes of one) is searched as a phrase, which needs the positions of `detail = full`, the default.
- **Exact matches rank above stem-only matches**: The `_stems` column is weighed in `bm25()` by the new `stem_weight` search option (default `0.5`, in the facade's `search` defaults and per search), so a document with the word as typed ranks above one that only has its stem. Totals, paging, facet counts, `multiSearch()` over a stemming and a plain index together (it makes its stem query only when one of the indexes stems, so a plain index never runs a stemmer), two-pass and hybrid searches all count the stem matches, and highlighting marks the document's own words that share a stem with a query term, in the language of the result.
- **Stemmers can be registered** ([#50](https://github.com/yetidevworks/yetisearch/issues/50)): `StemmerFactory::register($language, $stemmer, $aliases)` adds a stemmer for a language, or replaces a built-in one (its aliases keep pointing at it; an alias that belongs to another language moves). The stemmer is a class name or an instance of `StemmerInterface`, or a callable that returns one, called once when first needed; anything else throws an `InvalidArgumentException`. `StemmerFactory::canonical()` resolves a name, alias or locale (`en_US`, `fr-CA`, `pt_BR` resolve by the part before the underscore or hyphen unless registered as they are) to the canonical name or null, `create()`, `isSupported()` and `getSupportedLanguages()` take registrations and locale forms into account, and `reset()` returns to the four built-in stemmers, for tests.
- **Switch an existing index with `rebuildFts()`**: `rebuildFts($index, ['stemming' => true, 'language' => 'fr'])`, on the storage and on `YetiSearch`, turns stemming on or off for an index that holds documents, or changes its language, without indexing them again: it adds the `_stems` column to the content table where needed, makes the FTS table with or without stems and fills it from the stored documents. Without options a rebuild keeps the index's settings and makes the stems again with the stemmers registered now, which is what to run after registering a different stemmer. The rebuild runs in one transaction, so a stemmer that throws leaves the index as it was, and so does a write: an exception from a stemmer rolls the write back and is thrown as it is.
- **`analyzer.stop_words`**: Stop words per language, keyed by name, code or locale, replacing the built-in list of that language or giving one to a language that has none (`['it' => ['il', 'la']]`). `custom_stop_words` still adds to whichever list applies. The facade's `analyzer` defaults gain `'stop_words' => []`.
- **`SqliteStorage::setAnalyzer()` and `Contracts\ProvidesStemming`**: The storage stems with the analyzer it is given, which the facade sets to its own so `stop_words`, `custom_stop_words` and `min_word_length` apply to the stems; without one it uses a default `StandardAnalyzer`. `ProvidesStemming::stemmingFor($index)` says what language an index stems in, or null; `SearchEngine` checks for it before using it, so a custom storage without it keeps working.

### Bug Fixes
- **Stop words were looked up by name only**: `StandardAnalyzer` keyed its lists by `english`, `french`, `german` and `spanish`, so a language code (`fr`, `de`, `es`, or Grav's) fell back to the English list: a French query with `language => 'fr'` lost English words like `a` and kept French ones like `les`. Lists are now found by canonical name, so `french`, `fr` and `fr_FR` are the same list, and a language with no list (Italian, anything unknown) has no stop words instead of the English ones.
- **`stem()` English-stemmed other languages**: A language with no stemmer got English suffix stripping, so Italian text lost its endings to English rules. It is now returned as it is, and `analyze()` with such a language returns unstemmed tokens. `null` still means English.
- **A document written again in an own-content index kept its old text** (own-content schema, `external_content => false`): The FTS table keeps its own copy of the text and an insert does not replace a row of the same id, so updating a document left the old row in the table, and the old words still found it (and a query matching both versions returned it twice). A document, or a batch repeating an id, now replaces its old row, and only the last version of an id in a batch is indexed.
- **`clear()` left the old terms of an external-content index**: The documents went first, so the FTS5 table could not delete their rows one by one and kept their terms; a document indexed after `clear()` could take over a freed `doc_id` and be found by the words of the one that had it. `clear()` now empties the FTS5 table with its `delete-all` command.
- **A storage kept using what it had read about an index after another connection changed it**: `SqliteStorage` remembers an index's FTS columns, and now whether it stems, for as long as it lives. A process that had read an index before another one rebuilt it (turned stemming on with `rebuildFts()`, or created it again with other fields) went on writing the old way: an update omitted the `_stems` of the delete and the insert, so the index held stale stems (a document now containing "running" was still found by the stem of the word it replaced), or failed on a column that was gone. The storage now drops what it remembers when `PRAGMA data_version` shows that another connection has committed since, with one cheap check at the start of the methods that read or write an index.
- **`rebuildFts()` lost the FTS table's `prefix` and `detail`**: It made the table again with the `search.fts_prefix` of the storage config and no `detail`, so an index created with its own `fts.prefix` or `fts.detail` lost them on a rebuild (and stopped answering prefix queries it had been built for), and an index got the config's prefix whether it was created with one or not. The table is now made again with the options of the table being replaced, read from its `CREATE VIRTUAL TABLE` statement, which also covers indexes made by older versions, as they kept nothing about them. The statement is read argument by argument, split on the commas outside any quoting (`'..'`, `".."`, `[..]` and backticks), and only an argument of the form `name = value` is an option, so `prefix=` or `detail=` written inside a quoted tokenizer argument is not mistaken for one, and values quoted any of SQLite's ways are read. Only an index with no FTS table falls back to the config.
- **`deleteByIdPrefix()` indexed the stored JSON of an external-content index**: With its default `$rebuildFts = true` it ran FTS5's own `'rebuild'` command, which reads the content table's `content` column, the raw JSON, so every field name (`title`, `content`, `route`, ...) became a searchable term of every document in the index: after one prefix delete, searching `title` returned every document. Later deletes passed the extracted text, which no longer matched what the rebuild had indexed, so stale terms piled up, and each such delete was a pass over the whole index. It now always deletes the matched rows' FTS entries one by one, with the text (and the stems, on an index that stems) read before the content rows go, as `$rebuildFts = false` always did, and never runs the native rebuild. The `$rebuildFts` parameter stays for compatibility and no longer does anything: the vocabulary is in step either way. A site that already ran prefix deletes on an external-content index (YetiSearch Pro does one on every page reindex that has chunks or PDFs) has field names in its index already: run `rebuildFts()` once, or reindex, to clear them.
- **Results held in memory ignored the options of the search**: `SearchEngine` keyed the results it holds by the query alone, so a search with `stem_weight` 0 and then 1, or with another `min_score` or any other runtime option that is merged into the config for the search, was handed the first search's result. The key now includes the options of the search (in order of name, so the same options in another order are the same search) and the `stem_weight` in effect, and stays the same for identical searches.
- **`field_weights` were off by one column on own-content indexes with a column per field**: The FTS table there is `id UNINDEXED, <fields...>`, but the weights given to `bm25()` started at the first field, so a weight for `title` landed on `id` and every other weight on the column before its own. A `title` weight did nothing, and a `body` weight boosted the title. A weight of 1.0 for `id` now comes first, so `field_weights` apply to the fields they name. Ranking on such an index changes to what its `field_weights` asked for; an index without weights (or with equal ones) ranks as before, and external-content indexes, which have no `id` column, were not affected.
- **The indexer analyzed every field for nothing**: `Indexer` ran the analyzer over each indexed field of every document and collected tokens it never used, a cost on every write. It no longer does.
- **A document with invalid UTF-8 kept stale terms in an external-content index**: `insert()` and `insertBatch()` indexed the text of the document array but stored `json_encode($content)`, and every later update or delete (`deleteByIdPrefix()` included) rebuilds the text it must remove from the stored JSON. When `json_encode()` failed (invalid UTF-8 anywhere in the content, in a value that is not indexed as well), it stored an empty string, the delete rebuilt empty text and the old terms stayed, so a later document that reused the freed `doc_id` was found by the words of the one before it. The same mismatch came from an object value, which was not indexed when the document was written but was when it was read back from the stored JSON. Content and metadata are now encoded with invalid UTF-8 replaced by U+FFFD, a value that cannot be encoded at all (`NAN`, `INF`, nesting too deep) throws a `StorageException` and writes nothing (a batch is rolled back as a whole), and the text and stems that are indexed come from the content as stored, so they are what a delete rebuilds. This was already so on 2.5.x for an update, and for `deleteByIdPrefix()` with `$rebuildFts = false`. `getDocument()` returns the replaced text.
- **`createIndex()` on an index that exists rewrote its settings**: Calling it again (as an application does at start-up) with other options, or with none, rewrote the index's `schema_mode`, `multi_column_fts`, `fts_columns` and `fts_detail` while the FTS table, which `CREATE ... IF NOT EXISTS` leaves as it is, kept its columns. An own-content index with `title` and `body` columns created again with the defaults then failed its next insert with "table ..._fts has no column named content", and an external-content index created again as own-content read its documents the wrong way. An index whose FTS table exists now keeps all of its FTS settings, as it already did for stemming.

### Compatibility
- Nothing changes until an index opts in: an index created without `stemming` has the same tables, the same queries and the same ranking as before, and `rebuildFts()`, which gained an optional second parameter, keeps its settings when called as before.
- Stop words changed for language codes and for languages with no list, as described above. A search with `language => 'fr'` (or `de`, `es`) now removes French (German, Spanish) stop words instead of English ones, and a language such as Italian removes none; give it a list with `analyzer.stop_words`. Without a language, English is still used, except on an index that stems, where the language of the index is used.
- `StandardAnalyzer::stem()` no longer falls back to the English stemmer for a language it has no stemmer for, so such text is indexed and matched as typed. `StemmerFactory::getSupportedLanguages()` returns a plain list of canonical names.
- `StemmerFactory` registrations last for the process: register your stemmers where you build `YetiSearch`, before indexing or searching. The stems are stored in the index when a document is written, so after registering a different stemmer for a language, `rebuildFts($index)` makes them again.
- `dropIndex()` also drops the index's FTS5 vocabulary tables.
- `deleteByIdPrefix()` ignores its `$rebuildFts` parameter, which stays so that existing calls keep working: it always drops the deleted documents' FTS entries one by one. A site that ran prefix deletes before on an external-content index should run `rebuildFts()` once, or reindex, to clear the field names they put in the index. `rebuildFts()` keeps the `prefix` and `detail` of the FTS table it replaces, and ranking on an own-content index with a column per field changes to what its `field_weights` asked for.
- A custom `StorageInterface` implementation needs no change; one that implements `ProvidesStemming` receives `stem_query` and `stem_weight` in the queries it is given, and the stem query uses the `_stems : term` column filter of FTS5.

### Tests
- `tests/Unit/Stemmer/StemmerFactoryTest.php` covers registration of a new language with aliases, an instance, a lazily called callable, replacing a built-in with its aliases kept, an alias that moves, the cached instance dropped, locale forms, a registered locale winning over its language, a failed registration changing nothing, invalid stemmers, and `reset()`. `tests/Unit/Analyzers/StandardAnalyzerTest.php` covers stop words by code, name and locale, none for an unknown language, `stop_words` replacing and providing lists and following a language registered later, `custom_stop_words` merging, and `stem()` leaving a language without a stemmer alone.
- `tests/Unit/Search/StemQueryTest.php` checks the stem query the engine builds for each form of the raw query (an OR of components, an AND of terms in correction mode, expansion mode, a last-word prefix, synonyms, escaping), the language and stop words used, the same stem query for the count and the facets, and nothing for a plain index, a storage that cannot say, or a language without a stemmer.
- `tests/Integration/Search/StemmingSearchTest.php` and `tests/Integration/Storage/StemmingStorageTest.php` run in the three ways an index is stored: `connect` finds "connected" and "connection", `runs` finds "running", French found by the query language and by the index language, exact matches above stem-only ones, a plain index unchanged, correction mode, prefix, phrases, counts and facets agreeing with results, `multiSearch()` over a stemming and a plain index, a registered and a replaced stemmer used when indexing and searching, a language without a stemmer, and highlights. They also cover what a write leaves in the FTS table (checked in its vocabulary and with FTS5's integrity check): update, a batch repeating an id, delete, `deleteByIdPrefix()` (and that it does not index the stored JSON), chunks, meaning-only documents and `clear()`, an external-content row deleted after its stemmer was replaced, `rebuildFts()` switching a populated index on and off, changing its language and making stems again, a failed switch changing nothing, a legacy index migrated to external content, a second `createIndex()` changing nothing, and the typo correction vocabulary leaving out stems.
- `tests/Integration/Storage/StaleIndexSettingsTest.php` uses two connections to one database file: a connection that read an index as not stemming, or with other FTS columns, writes, searches and counts correctly after another one changed it. `RebuildFtsOptionsTest` checks that a rebuild keeps the `prefix` and `detail` of the table it replaces, whatever the connection's config says, that they are read from a hand-written table whatever the quoting (single and double quotes, brackets, backticks, bare values, spacing, uppercase names), also when the tokenizer argument holds text that looks like options, and from the tables the library creates, that an index with no FTS table falls back to the config, and that a legacy index moved to external content keeps its prefix. `DeleteByIdPrefixTest` checks, on plain and stemming indexes in all three ways of storing, that a prefix delete leaves no field names or deleted words in the index whatever `$rebuildFts` says. `Bm25FieldWeightsTest` checks that `field_weights` rank a title match first, and a body match first, on an own-content index with a column per field. `StemmingStorageTest` also covers a stemmer that throws leaving no transaction open, and the refusal of `detail = column` and `none`, and `StemmingSearchTest` the result cache key (another `stem_weight` or `min_score`), a corrected word keeping the stem of the word typed, and `multiSearch()` not stemming for plain indexes.
- `tests/Integration/Storage/StoredContentTest.php` checks, plain and stemming, in all three ways an index is stored, that a document with invalid UTF-8 in an indexed and an unindexed value is stored with the bytes replaced and can be updated, batch-updated and prefix-deleted without leaving its words behind (a document reusing the freed row is not found by them), that the text of an object value is indexed as it is stored, and that content or metadata that cannot be encoded throws and writes nothing.
- `tests/Integration/Storage/CreateIndexAgainTest.php` creates an own-content index with a column per field and an external-content index again with no options, other fields, multi-column off, the other schema and another detail, and checks that its settings are as they were and that inserts, batch inserts and searches still work, and that a new index gets its settings.
- The tests that read private members through Reflection call `setAccessible(true)` only on PHP before 8.1, where it is deprecated (and does nothing), so the suite runs clean on PHP 8.5.

## [2.5.6] - 2026-10-08

### Bug Fixes
- **A document indexed again without a location kept its old one**: Indexing a document skipped the spatial table whenever the document had no `geo` or `geo_bounds`, so a document (and its chunks) that lost its location stayed in `near()`, `within()` and distance searches at the old place. Indexing it again now removes the old location. An index that has never held a location still skips the lookup.
- **A distance sort lost its order with `unique_by_route`**: Grouping results by route ranked the routes by score afterwards, and a fuzzy search (or `distance_weight`) re-ranked its results by score after its penalties, so a search sorted by distance (or by a field with `sortBy()`) came back in relevance order whenever `unique_by_route` was on, and paging through it skipped and repeated places. Both now keep the order the query asked for.

### Improvements
- **`getDocument()` returns the document's location**: It now includes the `geo` point or `geo_bounds` the document was indexed with, so a caller that edits a document and writes it back keeps it in location searches, now that writing a document without a location removes the one it had. A point stored by R-Tree, which keeps 32-bit floats, comes back as its center rounded to 6 decimals (about 0.1 m).

### Tests
- `GeoFallbackTest` re-indexes a chunked document without its location in every geo mode and checks it is gone from location searches but still found by text, pages a distance-sorted search with `unique_by_route`, plain and fuzzy, and checks the order, and round-trips a document through `getDocument()` with its location.

## [2.5.5] - 2026-10-08

### Bug Fixes
- **k-NN failed on SQLite without math functions**: `nearest` always measured distance in SQL, and the expression meant for SQLite without math functions used `SQRT`, `POWER` and `COS`, which are math functions too. Since 2.5.4 passes `nearest` through `YetiSearch::search()`, the README's k-NN example threw `no such function` on such builds, the Windows builds of PHP among them. Without math functions, `nearest` now narrows the rows to the box around `max_distance` in SQL and measures, sorts and takes the k nearest in PHP.

### Tests
- The geo tests' "no SQL math" mode now makes every SQLite math function fail on the connection, as a build without them does, so a query that still calls one fails on every platform and not only on Windows. The k-NN and distance facet tests run in that mode too, and the modes that need math are skipped on a build without it.

## [2.5.4] - 2026-10-07

### Bug Fixes
- **Results held in memory outlived writes** ([#48](https://github.com/yetidevworks/yetisearch/issues/48)): `SearchEngine` keeps the results of recent searches in memory for `cache_ttl` seconds (300 by default), and only the semantic calls ever cleared them, so in a long-running process (a worker, a daemon, a test suite) a search after a write could return what it returned before the write, even after `clearCache()`. `SqliteStorage` now keeps a change token per index that moves on every write through it (documents, `clear()`, `dropIndex()`, `rebuildFts()`, vectors and calibrations) and on every commit to the database from another connection (`PRAGMA data_version`), and the engine drops a held result when the token has moved. A write is now seen by the next search whether it went through `YetiSearch`, an `Indexer` or the storage, in this process or another. `clearCache()` also empties the results every engine holds, and the `bypass_cache` option skips them as well as the storage's query cache. A custom storage can implement the new `YetiSearch\Contracts\TracksIndexChanges` to get the same; without it, held results last `cache_ttl` as before.
- **Geo search compared its numbers as text** ([#49](https://github.com/yetidevworks/yetisearch/issues/49)): PDO binds every value as text, and SQLite compares a computed distance or centroid with a text value as if every number were smaller. Without R-Tree, `near()` returned nothing; with R-Tree, the radius test in SQL let every document in the enclosing box through, so `total` counted the box, a `near()` with a `limit` could return an empty page when documents in the box's corners ranked first, and `max_distance` had no effect. Every geo bound is now cast to a number, and `near()`, its `total`, paging and `max_distance` are exact, with R-Tree and without.
- **Geo search without R-Tree runs the same queries**: Without R-Tree, the spatial table is a plain table with the same columns, and it now runs the same SQL as an R-Tree, so results, totals and distances are the same. The separate fallback runs only when SQLite has no math functions. It also fixes `within()` without R-Tree, which had east and west swapped and found nothing.
- **`near()` at the edges of the map**: A radius that crossed the antimeridian threw an exception with R-Tree and missed the far side without it, and a 500 km radius at 70 degrees north missed a point 246 m inside the circle. The search box now splits in two at ±180, takes every longitude when the circle holds a pole, and is the box that encloses the circle at every latitude.
- **`YetiSearch::search()` dropped geo options**: The facade ignored `nearest`, `max_distance`, top-level `units` and `within`, so the README's k-NN example returned a plain distance-sorted list. They all reach the search now, and `SearchQuery` gains `nearest()` and `maxDistance()`. k-NN results also came back with an empty `document`, since the k-NN query nested each row's content where the engine did not look, and a neighbor that was not due north raised a float-to-int deprecation on PHP 8.1+ when its bearing was named.
- **`search.geo_units` now sets the default unit of a radius**: The README has always said `geo_units` is the unit of a `near()` radius when the query names none, but it never reached the radius: only the distance facet and the `distance_units` metadata read it. It is now the default unit of a `near()` radius and of `max_distance`, through `YetiSearch::search()` and `SearchQuery` alike, and `units` on a query still wins. If you set `geo_units` to `km` or `mi` and pass radii in meters, add `'units' => 'm'` to those queries. `distance` on results stays in meters.
- **The distance facet counted documents with no location**: A document with no geo counted as 0 m away, so it landed in the first bucket. It is now left out.
- **Searches at different places shared one cached result**: `GeoPoint` and `GeoBounds` encoded as `{}` in JSON, so the cache key of a `near()` or `within()` search left out its coordinates, and a second search at another place in the same process got the first place's results for `cache_ttl` seconds. Both now encode as their coordinates.
- **`clear()` and `rebuildFts()` left the query cache stale**: With the storage's query cache on (`cache.enabled`), both left cached result rows in place, so a search right after `clear()` still returned the documents it had removed. Both now invalidate it, like every other write.
- **A facet that fails no longer fails the search** ([#47](https://github.com/yetidevworks/yetisearch/issues/47)): The facet that failed in #47, on a list field such as tags, was fixed in 2.5.3. Each facet is now also counted inside a `\Throwable` catch, so an error in one facet is logged and leaves the results and the other facets standing.

### Improvements
- **Geo search without R-Tree is documented** ([#49](https://github.com/yetidevworks/yetisearch/issues/49)): A new "Without R-Tree" section in the README's Geo Search says how to check whether your PHP has R-Tree, what works the same on the plain table (everything, with full-precision distances) and what differs (no spatial index, so a geo query checks every geo row). A "Without SQL math functions" section covers SQLite builds without `sin` and `cos`, where the radius test runs in PHP.

### Tests
- `tests/Integration/Geo/GeoFallbackTest.php` runs `near()` (exact results, totals, paging with corner documents ranking first, the antimeridian, a pole, 500 km at 70 degrees north), `within()`, `max_distance`, k-NN, the facade's geo options and the distance facet in six modes: both schemas, each with R-Tree, a plain spatial table, and a plain table without SQL math functions. `tests/Unit/Geo/GeoPointTest.php` covers the enclosing box.
- `tests/Integration/Search/ResultCacheTest.php` covers writes through the facade (the sequence from #48, without `clearCache()`), through an `Indexer` and from another connection, `clear()` with the query cache on, `clearCache()` emptying the engine's results, `bypass_cache` skipping them, and `near()` and `within()` searches at two places in one engine.

## [2.5.3] - 2026-10-07

### Bug Fixes
- **Range facets work on any numeric field** ([#46](https://github.com/yetidevworks/yetisearch/issues/46)): `ranges` was only read by the `distance` facet, so the range facets in the README and `docs/DSL.md` counted distinct values of a field named after the facet and came back empty without a word. A facet with `ranges` now counts numbers into buckets: each range has a `from` (inclusive), a `to` (exclusive) or both, and an optional `key` to label it. Buckets come back in the order given, empty ones included, as `value`, `count`, `from` and `to`. A new `field` option lets a facet named `price_range` count `price`, numeric strings count as numbers, a list of numbers counts once per bucket, and a range without a numeric bound is left out and logged as a warning.
- **`YetiSearch::search()` dropped the `facets` option**: The facade never handed `facets` to the query, so every facet requested through it, including the distance facets in the README, came back as an empty list. It now does, and also takes a plain list of fields (`'facets' => ['brand', 'tags']`).
- **Facets on a list field failed the whole search**: A facet on a metadata field holding a list, such as tags, threw a `TypeError` that no catch stopped. Each value in the list now counts once per document.
- **Float facet values were cut to integers**: A facet on a rating of 4.5 came back as 4, with a PHP 8.1+ deprecation notice for every row. Float values now come back as they are, and the distance facet no longer puts a 1.5 km threshold in the same bucket as 1 km.
- **A facet with nothing to count says so**: When no matching document has a facet's field, the search logs a notice naming the field, and a distance facet without a `from` point or `ranges` logs a warning instead of disappearing.

### Tests
- `tests/Integration/Search/FacetsTest.php` covers range facets in both schemas (bounds, keys, labels, empty buckets, a facet named after its field, numeric strings, lists of numbers), unusable ranges and the notice for a missing field, float and list values in value facets, and facets passed through `YetiSearch::search()`. The distance facet test now checks its buckets, with a 1.5 km threshold.

## [2.5.2] - 2026-10-06

### Bug Fixes
- **`scripts/check_sqlite_features.php` ships again**: Leaving development files out of the Packagist dist in 2.3.5 also took out the SQLite feature check, the one script site owners are told to run to see whether their PHP has FTS5 and R-tree support. It is back in the dist; the benchmark, coverage and migration scripts still stay out.

## [2.5.1] - 2026-09-23

### Improvements
- **Probes that keywords find are left out of calibration**: A probe the index finds by keyword is not noise there (`just testing` on a site full of test pages), and a search that keywords answer does not depend on the gate. `calibrate()` now leaves those probes out of the measurement and lists them in `NoiseCalibration::excluded()`, unless fewer than 44 probes would be left. On a 1,013-document documentation site this left out 13 probes and moved the cutoff from 0.244 to 0.231, so `logo branding` (0.236) finds its page again while `asdf`, `qwerty`, `xyzzy`, `zxcv` and `hjkl` still find nothing; on two product stores it left out 5 and barely moved the cutoff. The change in method makes calibrations stored by 2.5.0 stale, so the next `embedPending()` run that finishes an index measures it again.
- **`semantic.calibration_strictness`**: How many standard deviations above the mean probe margin a calibrated gate sits (default `2.0`, from 0 to 5). Lower keeps more borderline real searches and lets more nonsense through. The gate works it out from the stored probe margins with `NoiseCalibration::minMarginAt()`, so changing it needs no new calibration. The median plus a multiple of the median absolute deviation and the 90th percentile were tried as the rule on three indexes, and neither did as well as the mean plus two deviations.

### Bug Fixes
- **Calibration failed with every real provider**: `probeVectors()` built its list of texts from array keys, and PHP turns the key of the probe `1234567` into an integer, so the request sent a number inside `input`. OpenAI and OpenRouter reject that with HTTP 400, `embedPending()` reported a `calibration_error`, and every index stayed on the configured `min_margin`. Probe texts are now strings, and `OpenAICompatibleEmbeddingProvider::embed()` casts every input to a string before the request, so no caller can send a number.

### Tests
- A strict mode on the fake provider rejects non-string input as the real APIs do; calibration runs against it, and a unit test checks the provider sends numbers as strings.
- New tests cover a probe left out because a page contains it, and a looser strictness moving the gate without a new measurement.

## [2.5.0] - 2026-09-23

### New Features
- **Noise calibration per index**: The semantic noise gate (`min_margin`) is now measured on each index instead of being one number for every index. What nonsense scores against the gate grows with the number of documents: with text-embedding-3-small at 512 dimensions, nonsense stood at most 0.060 above the median over 17 product cards and 0.124 to 0.196 over 4,415, while real searches stood at least 0.141 and 0.200 above it. `calibrate($index)` embeds a fixed set of 88 nonsense probes exactly as a search is embedded, asks each the gate's question, and sets `min_margin` at the mean probe margin plus two standard deviations (0.119 and 0.200 on those two indexes). Only the margin is calibrated; gating on the best similarity turned real queries away. `YetiSearch\Semantic\NoiseCalibration` holds the probe set, the statistics and the rule, and is the value `calibrate()` and `calibration($index)` return (model, dimensions, documents, probe set version, frame, gate filters, `minMargin()`, `measuredAt()`, `stats()` and each probe's numbers). `semanticGate($index)` says which margin the gate uses and why. See "Noise calibration" in the README.
- **Calibration applies itself**: With `semantic.calibration` at `'auto'` (the default), the gate uses the stored calibration while it was measured with the current model id, dimensions, query frame, gate filters and probe set, over a number of documents within 25% of today's; otherwise it uses the configured `min_margin`. The `embedPending()` run that leaves nothing pending measures the index again when the stored calibration no longer applies, and says so in `'calibrated'` (a failure is reported in `'calibration_error'` and logged, and never fails the run). Changing the model, `clearEmbeddings()` and `dropIndex()` invalidate a calibration. `'off'` always uses the configured `min_margin`.
- **Probe vectors are cached**: Probe embeddings are kept per model and query frame in `yetisearch_probe_embeddings`, apart from the query cache so its eviction never drops them, and survive `dropIndex()`. Only the first calibration with a model calls the provider, once, for 88 short strings. Calibrations are kept per index in `yetisearch_calibration`. Both tables live in the index's database and are created on first use; `YetiSearch\Semantic\CalibrationStore` (implemented by `SqliteStorage`) lets a caller keep them elsewhere with `semantic.calibration_store`.
- **Gate over a subset**: `semantic.gate_filters` has the noise gate take its best match and median over the documents that pass those filters (product cards, article summaries) while every document is still ranked. `SqliteStorage::nearestVectors()` works out both in the same pass that ranks, and reports `best` and `total` beside `median` and `count`. With a subset, its best match must also clear `min_similarity`.
- **Meaning-only documents**: A document indexed with `'meaning_only' => true` is embedded and ranked by meaning and never found by keywords: it gets no full-text entry, takes no part in BM25 statistics and matches no word. The flag is stored as `_meaning_only` in its metadata, is inherited by its chunks, survives `rebuildFts()` and `deleteByIdPrefix()`, and can be turned off by indexing the document again without it. A store can now index short product cards for meaning beside its products without running the keyword and vector sides of a search separately.
- **Query frame**: `semantic.query_frame` puts every query in a sentence before it is embedded (`'a {query}'` sends `hat` as `a hat`, which moved the right product from fifth to first on a 17-product store). Only queries are framed, so changing the frame never re-embeds a document; the query cache, the probe cache and the calibration are keyed on it.

### Compatibility
- Additive. With `calibration: 'off'`, or while an index has no calibration that applies, the gate behaves exactly as in 2.4.0, with the configured `min_margin`, and the gate works over every document while `gate_filters` is empty. A calibrated `min_margin` is the noise ceiling, so a query must stand above it; the configured one is still a floor to reach. Upgrading callers get a calibration from their next `embedPending()` run that finishes an index of 10 documents or more. The new tables are created on first write, `nearestVectors()` and `countVectors()` gain optional trailing parameters, and `embedPending()` returns two more keys.

### Tests
- `tests/Unit/Semantic/NoiseCalibrationTest.php` covers the statistics, the rule, the gate's question per probe, matching, drift and the JSON round trip. `tests/Integration/Semantic/CalibrationTest.php` covers calibration at the end of `embedPending()`, auto against off, auto with no calibration searching exactly as off, the probe cache (one provider call, surviving query-cache eviction and `dropIndex()`), the 10-document floor, invalidation by model, `clearEmbeddings()` and drift, the query frame, the subset median and count from one pass, gating on a subset, a custom store and the storage round trip. `tests/Integration/Semantic/MeaningOnlyDocumentsTest.php` covers keyword exclusion and meaning ranking in both schemas, turning the flag on and off through single and batch writes, deletes, rebuilds, chunks, and product cards with a card-only gate. A new `NoisyEmbeddingProvider` fixture spreads text without concept words over hashed dimensions, so probe margins have a spread to measure.

## [2.4.0] - 2026-09-23

### New Features
- **Semantic search (hybrid)**: Search can now rank by meaning as well as keywords. Give YetiSearch an embedding provider (`setEmbeddingProvider()`, or `'semantic' => ['provider' => ...]` in the config), run `embedPending()` after indexing, and text queries run a BM25 search and a vector search over the same filters and language, merged with reciprocal rank fusion (`weight`, default `0.5`). The vector share is scaled by each document's similarity relative to the best match, so a weak semantic match scores visibly lower than a strong one. A search for `automobile` now finds a page about cars. Without a provider nothing changes: same queries, same results, no network calls. See "Semantic Search (Hybrid)" in the README.
- **Embedding providers**: `YetiSearch\Contracts\EmbeddingProviderInterface` (`embed()`, `dimensions()`, `modelId()`) lets any embedding service plug in. `YetiSearch\Semantic\OpenAICompatibleEmbeddingProvider` covers OpenAI and everything that speaks its `/embeddings` API (OpenRouter, Ollama, LM Studio, Voyage, Mistral, Together), with optional `dimensions`, query/document prefixes for models such as nomic-embed-text, Voyage's `input_type`, batching, retries for 429/5xx on document batches, and a separate short `query_timeout`.
- **Incremental embedding**: Vectors are stored per document row in `{index}_vectors`, in the index's own SQLite file, as normalized float32 with a hash of the embedded text. `embedPending($index, $limit)` embeds only rows that are new or whose text or model changed, keeps finished batches when a later one fails, returns the error instead of throwing, and prunes vectors of deleted documents. A chunk deleted and re-inserted with the same text keeps its vector. Chunked documents embed their chunks, not the parent row. `embeddingStats()` and `clearEmbeddings()` report on and reset an index.
- **One result per page with `unique_by_route`**: When results are deduplicated by route, hybrid search ranks each page by its best chunk on each side before fusion, so a long page with many loosely related chunks cannot fill the vector ranking or outrank a better match by length. A document marked `chunked` with zero chunks (how a caller that pre-chunks by heading marks a short page) is embedded as its own row.
- **Noise control**: Similarity alone does not separate a real query from noise: with text-embedding-3-small, `asdf` scored 0.37 against the closest page while a real match on another site scored 0.28. What does separate them is how far the best match stands above the median document (0.23 to 0.47 for real queries, 0.12 to 0.14 for noise on a test site), and that carries across models better than raw similarity. Meaning now adds results only when the best match clears the median by `min_margin` (0.15), and keeps only documents at least `relative_similarity` (0.6) as similar as the best one.
- **Graceful fallback**: A provider error or timeout at query time falls back to keyword search and logs a warning; the result carries `'semantic' => false`. Changing the model marks every document pending, and searches on that index stay keyword-only until they are re-embedded, so vectors from two models are never compared. Query embeddings are cached in `yetisearch_query_embeddings` (5000 entries), so repeated searches make no provider call. `'semantic' => false` and `'semantic_weight'` in the search options override per query.

### Compatibility
- Fully optional. With no embedding provider, search, indexing and storage behave exactly as in 2.3.6 and nothing is sent anywhere. The only visible difference is a `'semantic' => false` key in the array `search()` returns. `dropIndex()` also drops the index's `{index}_vectors` table when one exists.

### Performance
- The vector side compares the query with every candidate vector in PHP: about 110 ms for 10,000 chunks at 512 dimensions on PHP 8.4, linear in documents times dimensions, and proportionally less with filters. Keyword-only searches are unaffected.

### Tests
- `tests/Integration/Semantic/HybridSearchTest.php` covers the unchanged no-provider path, incremental embedding, meaning-only matches, fusion order, filters on the vector side, provider failure at query and embed time, the query-embedding cache, per-query opt-out, model changes, pruning, chunked documents, `dropIndex()`, the external-content schema, config-supplied providers, page-level ranking with `unique_by_route`, zero-chunk documents, the noise margin and the relative cutoff, using a deterministic fake provider. Verified against a real model (`openai/text-embedding-3-small` at 512 dimensions via OpenRouter): "can't log in" finds the password-reset article and "automobile" the car article with no shared keyword, while an unrelated query returns nothing. `tests/Unit/Semantic/SemanticUnitTest.php` covers fusion, vector packing and the OpenAI-compatible request and response handling.

## [2.3.6] - 2026-08-31

### Bug Fixes
- **Typo correction did nothing on a small corpus**: `min_term_frequency` is the number of *documents* a term must appear in before fuzzy search will correct towards it, and it defaulted to `2` — not as a declared default, but as a bare `?? 2` repeated at five call sites in `SearchEngine` and in the `getIndexedTerms()` signature. Two is a floor that removes exactly the vocabulary a small corpus is made of: a product name, a brand or a person's name appears in one document by its nature, so on a twelve-document catalogue the correction dictionary fell from 156 terms to 39 and every one of those names became unreachable by a misspelling of it. Searching `licence` for a product called Licenses, `molie` for Mollie or `subscription` for Subscriptions returned nothing at all, while a typo of a word that happened to appear twice corrected normally — which made the feature look intermittent rather than misconfigured. The default is now `1`, declared alongside the other fuzzy tuning knobs, and documented: a term that is in the index is a term somebody indexed on purpose. Raise it on a large, noisy corpus, where a term appearing once is more likely to be a typo than a word.

### Tests
- **The default configuration is now tested**: every existing fuzzy test set `min_term_frequency => 1` in its own setup, so the suite never exercised the values a consumer gets out of the box and the floor above went unnoticed through several releases. `tests/Integration/Search/DefaultConfigFuzzySearchTest.php` builds a ten-document catalogue in which every term it corrects towards appears in exactly one document, overrides no search setting at all, and covers a substituted letter, a transposed pair, a dropped letter, a British spelling, a truncated word and a singular typed for a plural — plus the negative case, that a word with no near neighbour still matches nothing. It fails on all seven typos against the old default.

## [2.3.5] - 2026-08-31

### Packaging
- **Development files no longer ship to consumers**: `composer require yetidevworks/yetisearch` unpacked the entire repository into a consumer's `vendor/` — the test suite, the benchmarks, the examples, the CI configuration, and the SQLite databases a development run leaves behind (`yetisearch.db` at the repo root, `examples/typeahead.db`, `benchmarks/fuzzy-eval.db`). Those three databases were arriving in production installs of anything depending on this library. A `.gitattributes` `export-ignore` set now limits the dist to `src/`, `bin/`, `docs/`, the composer metadata, the licence, the readme, the performance guide and the changelog. The lockfile is excluded as well, since Composer ignores it outside the root package. The published archive drops from roughly 2.0 MB to 0.7 MB. No library code changed in this release.

## [2.3.4] - 2026-08-30

### Bug Fixes
- **Unescaped FTS5 MATCH on the `multiSearch()` path**: `multiSearch()`/`searchMultiple()` handed the raw query string to `SqliteStorage::search()` without going through `SearchEngine`, so none of the FTS5 escaping applied there. A hyphenated order number like `BENCH-100821` parsed as a column filter, SQLite rejected the MATCH, and the per-index `catch` swallowed the error into zero results. The escaper is now extracted into `YetiSearch\Utils\Fts5Escaper` (shared with `SearchEngine`), and `multiSearch()` tokenizes with the analyzer, removes stop words, and escapes each term before storage. A termless but non-blank query returns no results instead of matching the entire index; an explicitly blank query keeps its match-all meaning for geo-only and facet-only searches. The dedicated `query` argument is authoritative — an options entry can no longer bypass analysis and escaping. See `tests/Integration/Search/MultiSearchSpecialCharacterTest.php`.
- **Silently skipped indices in `searchMultiple()`**: The per-index `catch` continued without any trace, so a caller searching three indices could have one skipped invisibly. `SqliteStorage` now accepts an optional PSR-3 logger (wired from `YetiSearch`) and logs a warning naming the index and the error before continuing.
- **PHP 7.4 compatibility**: Replaced a nullsafe operator (`?->`) introduced on the multiSearch path with an explicit null check — `YetiSearch` still supports PHP 7.4.

## [2.3.3] - 2026-08-30

### Bug Fixes
- **Stale FTS5 terms on external-content deletes**: An FTS5 `content=` table stores no copy of the indexed text, so its `'delete'` command removes exactly the terms it is handed — and every delete site was handing it an empty string, which deletes nothing. The old terms stayed in the vocabulary pointing at a `doc_id`, and since `doc_id` is a plain `INTEGER PRIMARY KEY`, SQLite hands that rowid to the next document inserted, which then answered for words only the deleted document ever contained. All paths (`delete()`, `insert()`, `insertBatch()`, `deleteByIdPrefix()` without rebuild) now read the `doc_id` and the text the row was indexed with before anything is overwritten and hand that text to the `'delete'` command. `insertBatch()` also drops `INSERT OR REPLACE` (which cannot work for external content) in favor of a plain insert paired with an explicit delete. Callers no longer need a `rebuildFts()` after every write batch to keep results correct. See `tests/Integration/Storage/ExternalContentFtsDeleteTest.php`.
- **Remaining query-operand leaks in FTS5 MATCH**: A token that escapes to an empty string (empty, or nothing but the prefix operator) was joined into the MATCH expression as a bare operand, so `widget *` built `widget OR ` and SQLite answered with an fts5 syntax error. Escaping now goes through `escapeFtsTokens()`, which drops the empties, and phrase and `NEAR()` components are only emitted while they still have operands. Separately, a query that reduces to no searchable term (`:`, `...`, `**`, `()`) now returns no results from `search()` and `count()` instead of matching the entire index. An explicitly blank query is unchanged and still means "no text query" for geo-only and facet-only searches.

## [2.3.2] - 2026-08-30

### Bug Fixes
- **FTS5 query escaping**: User-typed terms containing characters that are operators in the FTS5 `MATCH` grammar (hyphens in order numbers or SKUs, colons, quotes, parentheses) no longer throw a `StorageException` — instead they are wrapped in double quotes so FTS5 treats them as literal phrases and re-tokenizes them with the table's own tokenizer. Terms colliding with the `AND`/`OR`/`NOT`/`NEAR` keywords are quoted as well, and a trailing `*` from `prefix_last_token` stays outside the quotes so it remains a prefix operator. Intentional DSL and advanced-query syntax is unaffected. See `tests/Integration/Search/SpecialCharacterQueryTest.php`.
- **Indexer `fields` option validation**: A flat list like `['fields' => ['title', 'sku']]` used to be accepted silently and then corrupt the index (the integer list keys became the FTS column names, followed by insert failures or silently missing documents). The option is now normalized in the `Indexer` constructor: the flat form is accepted as shorthand for the default boost, the documented associative form passes through, and the two may be mixed. Malformed shapes throw `InvalidArgumentException`, and field names are validated as plain SQL identifiers since they become FTS5 column names. See `tests/Integration/Indexer/FieldsConfigNormalizationTest.php`.
- **Packagist package name**: Restored the canonical `yetidevworks/yetisearch` package name in `composer.json` (a rename to `yetisearch/yetisearch` pointed at a package that has never existed on Packagist and would have broken the next release sync).

## [2.3.1] - 2026-03-09

### Improvements
- **SQLite backward compatibility**: The `RETURNING` clause (requires SQLite 3.35.0+) is now optional. On older SQLite versions (3.24.0+), a fallback `SELECT` query is used to retrieve `doc_id` after upsert operations. This lowers the minimum SQLite requirement from 3.35.0 to 3.24.0 while preserving optimal performance on newer versions.

## [2.3.0] - 2026-02-13

### Security Fixes
- **Cache table identifier hardening**: Added strict validation for cache table names in `QueryCache` (`/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`) before SQL interpolation. Invalid names now throw `CacheException`.

### Performance Improvements
- **`searchMultiple()` merge efficiency**: Removed repeated `array_merge` copying in result aggregation and eliminated duplicate `indexExists()` checks by tracking validated indices in one pass.
- **Levenshtein prefilter optimization**: In `SearchEngine::generateLevenshteinVariations()`, query-term bigrams are now computed once per query term instead of once per indexed-term candidate.
- **Suggestion query roundtrip reduction**: Added per-call memoization for `count()` checks in `generateSuggestion()` and `generateSuggestions()` to avoid repeated database checks for identical candidate queries.
- **Field-weight candidate pool tuning**: Reduced default overfetch for field-weight scoring and made candidate sizing configurable:
  - `field_weight_candidate_multiplier` (default `20`)
  - `field_weight_candidate_min` (default `200`)
  - `field_weight_candidate_max` (default `2000`)
  - `field_weight_candidate_cap` (optional per-query/config cap)

### Internationalization
- **UTF-8-safe hot paths**: Switched key text-processing paths to UTF-8-safe handling:
  - Snippet extraction in `SearchEngine::extractSnippet()`
  - Suggest title normalization/matching in `SearchEngine::suggest()`
  - Tokenization/term-position extraction in `SqliteStorage`
  - Field-weighted phrase/term matching in `SqliteStorage::calculateFieldWeightedScore()`

### Bug Fixes
- **Filter operator correctness in storage queries**: Fixed inconsistent operator handling between direct columns and JSON fields in `SqliteStorage::buildFilterClause()` / `buildJsonFilterClause()`. Added proper support for `between` and `is not null` semantics, and corrected `in`/`not in` empty-array behavior to avoid invalid SQL or silent filter bypass.
- **Multi-index ranking in `searchMultiple()`**: Fixed merged result ordering to sort by actual `score` output (with fallback keys), instead of relying on missing `rank`/`_score` keys that could produce non-relevance ordering.
- **Cache wiring and bypass propagation**: Top-level `cache` config is now passed into storage connection config, and `bypass_cache` set on `SearchQuery` options is now propagated from `SearchEngine` to storage queries.

### Tests
- Added integration coverage for metadata and direct-column filter operators:
  - `between` and `is not null` regression assertions in `tests/Integration/Storage/MetadataFilterTest.php`
- Added multi-index ranking regression test:
  - `test_search_multiple_indices_sorts_by_score` in `tests/Integration/Storage/SearchMultipleTest.php`
- Added query cache integration coverage:
  - `tests/Integration/Storage/QueryCacheIntegrationTest.php` verifies top-level cache config wiring and `bypass_cache` behavior
- Added `tests/Integration/Storage/FieldWeightCandidateLimitTest.php` for default and explicit candidate cap behavior.
- Added `tests/Integration/Search/Utf8HighlightTest.php` for UTF-8 highlight/snippet behavior.
- Extended `tests/Integration/Storage/QueryCacheIntegrationTest.php` with invalid cache table name rejection coverage.  

## [2.2.0] - 2026-02-08

### Security Fixes
- **SQL injection via index name interpolation**: Added `validateIndexName()` enforcing `/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/` at all 18 public entry points in `SqliteStorage`. Index names are now validated before being interpolated into SQL.
- **SQL injection via filter operator pass-through**: Added operator whitelist (`ALLOWED_FILTER_OPERATORS`) with `validateOperator()`. The `buildFilterClause()` and `buildJsonFilterClause()` default case no longer passes arbitrary operators into SQL.
- **SQL injection via JSON field path**: Added `validateFieldName()` enforcing `/^[a-zA-Z_][a-zA-Z0-9_.]*$/` for all field names used in `json_extract()` calls and ORDER BY clauses.
- **SQL injection via sort field in ORDER BY**: Metadata sort fields and arbitrary field values in ORDER BY are now validated before interpolation.
- **Second-order SQL injection in `listIndices()`**: Table names from `sqlite_master` are now validated with regex and the FTS existence check uses a parameterized query instead of string interpolation.
- **PRAGMA synchronous value validation**: The configurable `synchronous` pragma value is now whitelisted to `OFF`, `NORMAL`, `FULL`, or `EXTRA`.

### Bug Fixes
- **`PRAGMA synchronous = OFF` risked data corruption**: Changed default from `OFF` to `NORMAL` (safe with WAL mode, minimal performance impact). Users can opt-in to `OFF` via `'synchronous' => 'OFF'` config for bulk-load scenarios.
- **CRC32 hash collision for spatial IDs**: Replaced `abs(crc32($id))` (~50% collision at 77k entries) with a 60-bit SHA-256 hash using `hexdec()` for PHP 7.4 compatibility.
- **`deleteByIdPrefix()` orphaned spatial data**: Fixed bug where `getDocId()` was called AFTER deleting from the main table (always returned null). Now collects `doc_id` values BEFORE main table deletion.
- **`extractKeywords()` passed wrong type**: Fixed `StandardAnalyzer::extractKeywords()` passing the full `analyze()` result array to `array_count_values()` instead of just the `tokens` key.
- **`searchMultiple()` returned paginated count as total**: Fixed `count($allResults)` being called after `array_slice()`. Now captures total count before pagination.
- **External content FTS deletion rebuilt entire index**: Single document deletion no longer triggers a full FTS rebuild (O(N)). Now uses FTS5's targeted delete command (O(1)), matching the pattern used in `insert()`.
- **`CacheException` not in exception hierarchy**: Changed `CacheException` to extend `YetiSearchException` instead of `\RuntimeException`, so `catch (YetiSearchException $e)` now catches cache errors.
- **`FuzzyTermCache` had no file locking**: Added `LOCK_EX` flag to `file_put_contents()` to prevent concurrent process corruption of the JSON cache file.
- **Config mutation not exception-safe in `SearchEngine`**: Replaced dual config restoration (normal flow + catch block) with a single `try/finally` block to guarantee config is always restored.
- **`strpos` return value bug in snippet extraction**: Fixed `strpos() ?: $start` treating position 0 as falsy. Now uses proper `!== false` check.
- **Static `$logged` variable only logged once per process**: Removed the `static $logged` pattern in `processResults()` that prevented debug logging after the first call.
- **`uniqid()` not unique under concurrency**: Replaced `uniqid()` with `bin2hex(random_bytes(8))` for fallback document IDs in `Indexer`.
- **No validation on `SearchQuery` limit/offset**: Added `max(0, ...)` clamping in constructor and setter methods to reject negative values.

### Documentation Fixes
- Fixed 4 PHP syntax errors in README code examples (missing closing `]`)
- Replaced non-existent `getAnalyzerInstance()` example with config-based approach
- Fixed `count()` API signature to match actual `count(string $indexName)` (1 param, not 3)
- Replaced non-existent `insert()`/`insertBatch()` facade methods with actual names (`index()`, `indexDocument()`, `indexBatch()`)
- Added undocumented public methods to API reference: `deleteByIdPrefix()`, `rebuildFts()`, `close()`, `query()`, `execute()`, `listIndices()`, `generateSuggestions()`
- Removed 6 unimplemented stemmer languages from feature list (Italian, Portuguese, Dutch, Swedish, Norwegian, Danish)
- Fixed broken link: `examples/dsl-examples.php` to `examples/dsl-metadata-example.php`
- Replaced non-existent `YetiSearch\Tools\FuzzyBenchmark` class reference with config-based comparison example
- Removed non-existent `Models/Document.php` from architecture diagram, added `SearchResults.php`

### Test Configuration
- Added DSL test suite to `phpunit.xml.dist` pointing to `tests/DSL` directory (was previously unreachable)

### Tests
- Added 60 comprehensive DSL tests across 3 new test files:
  - `QueryParserDetailedTest` (46 tests): query text parsing, all filter operators, IN/NOT IN, LIKE, grouped conditions, FIELDS/SORT keywords, field aliases, dot-notation fields, edge cases
  - `URLQueryParserTest` (51 tests): all 14 filter operator mappings, value coercion, sorting, fields, pagination modes, fuzzy/highlight/facets/geo/language/boost, `parseFromQueryString()`
  - `FluentQueryTest` (43 tests): all where variants, ordering, pagination, fuzzy/highlight/boost/language/facets, geo queries, `toSearchQuery()`/`toArray()`, `QueryBuilder.parse()` auto-detection, exception cases

## [2.1.2] - 2026-02-04

### Bug Fixes
- **External content FTS vocabulary sync**: Fixed critical bug where updating documents in external content FTS tables left stale terms in the vocabulary. The FTS `INSERT OR REPLACE` doesn't properly update the vocabulary for external content tables. Now uses explicit delete-before-insert and provides `rebuildFts()` method for ensuring vocabulary sync after updates.
- **Pre-chunked document content handling**: Fixed bug where pre-chunked documents with array content were incorrectly merged with parent content. When plugins provide chunks with their own content arrays (title, content, excerpt, etc.), the Indexer now uses that content directly instead of nesting it inside the parent's content structure. This caused all chunks to match searches for terms that only existed in one chunk.
- **Chunk cleanup on document update**: Added `deleteByIdPrefix()` method to properly clean up orphaned chunks when a document is updated with fewer chunks than before.
- **Fixed suggestions logic**: Was not showing suggestions properly. Fixed and added updates tto the README.md to explain the setup required.

### New Methods
- `YetiSearch::deleteByIdPrefix(string $indexName, string $prefix): int` - Delete all documents whose ID starts with a given prefix. Useful for cleaning up chunks when a parent document is updated.
- `YetiSearch::rebuildFts(string $indexName): void` - Rebuild the FTS index to ensure vocabulary is in sync with content table. Essential for external content FTS tables after document updates.
- `SqliteStorage::deleteByIdPrefix(string $index, string $prefix): int` - Storage-level implementation of prefix-based deletion with proper cleanup of all related tables (FTS, terms, spatial).

## [2.1.1] - 2026-01-03

### Bug Fixes
- **Fuzzy penalty calculation with external content**: Fixed `calculateFuzzyPenalty()` not extracting document content when using `external_content => true` storage mode. The method now properly reads content from `$result['document']` for external content schemas, ensuring fuzzy matches are correctly penalized based on actual document content.
- **Valid term over-correction**: Fixed `findBestCorrection()` incorrectly "correcting" valid terms that exist in the index. Previously, valid terms with low frequency (< 3) could be incorrectly replaced with similar higher-frequency terms. Now any term that exists in the index is preserved, preventing proper nouns and domain-specific terms from being changed.

### Tests Added
- **Comprehensive fuzzy search test suite**: Added `tests/Integration/Fuzzy/MoviesFuzzySearchTest.php` with 32 tests covering:
  - Exact match baseline verification
  - Longer word typo handling (transpositions, keyboard errors, common misspellings)
  - Double/missing letter typos
  - Multi-word search scenarios
  - Prefix suggestions with various lengths
  - Ranking verification (exact matches rank first)
  - Performance benchmarks for fuzzy search and suggestions
  - Edge cases (empty queries, special characters, case insensitivity, numbers)
  - Genre search with and without typos
  - Algorithm comparison tests (trigram vs jaro-winkler)

## [2.1.0] - 2025-12-24

### New Features

#### Query Result Caching
- **Built-in query caching system**: Dramatically improves performance for repeated searches
  - SQLite-based persistent cache storage survives PHP restarts
  - Automatic cache invalidation on insert/update/delete operations
  - LRU (Least Recently Used) eviction when cache size limit is reached
  - Configurable TTL (Time To Live) for cache entries
  - Hit tracking and statistics for monitoring cache effectiveness

- **Performance improvements**: 10-100x faster for cached queries
  - First query: 5-30ms (depending on complexity)
  - Cached query: 0.1-0.5ms
  - No impact on indexing performance
  - Minimal memory overhead (cache stored in SQLite table)

- **Cache management API**:
  - `getCacheStats()` - Get hit rate, total entries, and other metrics
  - `clearCache()` - Manually clear cache for an index
  - `warmUpCache()` - Pre-populate cache with common queries
  - `getCacheInfo()` - Detailed cache entry information

- **Configuration options**:
  ```php
  'cache' => [
      'enabled' => false,    // Disabled by default for backward compatibility
      'ttl' => 300,         // 5 minutes default TTL
      'max_size' => 1000    // Maximum cached queries per index
  ]
  ```

#### Pre-chunked Document Support
- **Custom document chunking**: Documents can now provide their own chunks instead of relying on automatic chunking
  - Simple mode: Provide an array of string chunks
  - Structured mode: Provide chunks with content and custom metadata
  - Enables semantic chunking at paragraph/section boundaries
  - Preserves document structure (headings, subsections)
  - Each chunk can have its own metadata (section, heading level, etc.)
- **Mixed chunking modes**: Pre-chunked and auto-chunked documents can coexist in the same index
- **Better search relevance**: Keep related content together for improved search accuracy

#### Enhanced Fuzzy Search with Modern Typo Correction
- **Modern search engine behavior**: Automatic typo correction like Google and Elasticsearch
  - Multi-algorithm consensus scoring using 5 different similarity algorithms
  - Phonetic matching for sound-alike typos (fone→phone, thier→their)
  - Keyboard proximity analysis for fat-finger errors (qyick→quick)
  - Configurable sensitivity and precision thresholds
  - Enabled by default for improved user experience

- **Multi-algorithm consensus scoring**:
  - Trigram similarity (25% weight) - Good for overall similarity
  - Levenshtein distance (20% weight) - Good for edit distance
  - Jaro-Winkler similarity (25% weight) - Good for short strings and prefixes
  - Phonetic matching (15% weight) - Good for sound-alike typos
  - Keyboard proximity (15% weight) - Good for fat-finger errors

- **Enhanced "Did You Mean?" features**:
  - Improved suggestion generation with confidence scores
  - Multiple suggestions with detailed metadata (confidence, type, original_token, correction)
  - Automatic suggestion inclusion in search results when no matches found
  - `generateSuggestions()` method returning confidence scores

- **New utility classes**:
  - `PhoneticMatcher` - Handles phonetic similarity using Metaphone and Double Metaphone
  - `KeyboardProximity` - Analyzes QWERTY keyboard layout for proximity-based typos

- **Updated default configuration**:
  ```php
  'search' => [
      'fuzzy_correction_mode' => true,    // Enable modern typo correction
      'correction_threshold' => 0.6,      // Balance sensitivity and precision
      'trigram_threshold' => 0.35,        // Improved matching for partial words
      'fuzzy_score_penalty' => 0.25,      // Reduced penalty for fuzzy matches
  ]
  ```

- **Performance improvements**:
  - Consensus scoring with early validation for faster processing
  - Improved frequency weighting for better correction accuracy
  - Cached indexed terms for reduced database queries

#### DSL (Domain Specific Language) Support
- **Natural language query syntax**: Write queries using SQL-like syntax for intuitive query construction
  - Example: `author = "John" AND status IN [published] SORT -created_at LIMIT 10`
  - Supports complex conditions with AND/OR logic, grouped conditions, and negation
  - Keywords: FIELDS, SORT, PAGE, LIMIT, OFFSET, FUZZY, NEAR, WITHIN

- **JSON API-compliant URL parameters**: Parse standard REST API query patterns
  - Filter syntax: `filter[field][operator]=value`
  - Pagination: `page[limit]=10&page[offset]=20` or `page[number]=2&page[size]=10`
  - Sorting: `sort=-created_at,title` with `-` prefix for descending
  - Full compliance with JSON API specification

- **Fluent query builder interface**: Build queries programmatically with chainable methods
  - Methods like `where()`, `whereIn()`, `whereBetween()`, `orderBy()`, `fuzzy()`, etc.
  - Support for geo queries: `nearPoint()`, `withinBounds()`, `sortByDistance()`
  - Get results, first item, or count with `get()`, `first()`, `count()` methods

- **Metadata fields configuration**: Configurable metadata field recognition for DSL
  - Auto-prefixing of recognized metadata fields with 'metadata.' in queries
  - Customizable field lists for third-party applications
  - Support for both content fields and metadata fields in queries

- **Field aliasing**: Map user-friendly names to actual database field names
- **CLI integration**: New commands `search-dsl` and `search-url` for testing DSL queries

#### Advanced Content Field Filtering
- **Generic content field filtering**: Filter on any field within the document content using `content.fieldname` syntax
  - Example: `content.version = "v3"` or `content.category = "guides"`
  - Backward compatible: bare field names (like `version`) automatically map to content fields

- **New `=?` operator**: "Equals OR empty/null" operator for optional taxonomy fields
  - Matches documents where field equals the value OR where the field is empty/null
  - Perfect for versioned content where some documents don't have the field set
  - DSL syntax: `version =? "v3"` (matches version v3 OR unversioned documents)
  - URL syntax: `filter[version][eqor]=v3`
  - Works with any content or metadata field, not just `version`

- **Refactored filter processing**: All filter logic consolidated into reusable `buildFilterClause()` method
  - Cleaner, more maintainable codebase
  - Consistent operator support across all filter locations
  - Support for `content.*` fields alongside existing `metadata.*` fields

#### Search Result Quality & Consistency
- **Fixed fuzzy search inconsistency**: Exact matches now consistently rank higher than fuzzy matches
  - Restructured query building to use NEAR queries and parentheses for exact match prioritization
  - Query structure: `(exact_phrase OR NEAR(exact_terms)) OR (fuzzy_terms)`
  - Enhanced fuzzy penalty calculation with graduated penalties based on match quality
  - Added configuration options: `exact_match_boost` (default: 2.0), `exact_terms_boost` (default: 1.5)

#### Field Weighting Effectiveness
- **Significantly improved field weighting**: Documents with matches in high-weight fields (title, h1) now rank much higher
  - Enhanced `calculateFieldWeightedScore()` with exact match detection
  - Added exponential scaling for more pronounced score differences
  - Increased candidate pool size from 200-500 to 1000-5000 documents
  - Primary field detection gives extra boost to title, h1, name fields
  - Perfect field match: 100+ point boost
  - Exact phrase in field: 50+ point boost
  - Proximity bonuses for terms close together

#### Multi-Column FTS (Default Enabled)
- **Native BM25 field weighting**: Now enabled by default for better performance
  - Separate FTS columns per field for native SQLite BM25 weighting
  - ~5% faster than single-column mode (6.76ms vs 7.09ms average)
  - Toggle available via `multi_column_fts` configuration
  - Automatic fallback to post-processing weights in single-column mode
  - Backward compatible with existing indexes

#### Two-Pass Search Strategy (Optional)
- **Primary field prioritization**: Optional two-pass search for maximum precision
  - First pass searches primary fields (title, h1) with doubled weights
  - Second pass searches all fields and merges results
  - Toggle via `two_pass_search` configuration (default: false for performance)
  - Configuration: `primary_fields` and `primary_field_limit`

### Components Added
- `src/Cache/QueryCache.php` - Query result caching implementation with SQLite storage
- `src/Storage/PreparedStatementCache.php` - PDO prepared statement caching for reuse
- `src/DSL/QueryParser.php` - Natural language DSL parser with tokenization and AST building
- `src/DSL/URLQueryParser.php` - JSON API-compliant URL parameter parser
- `src/DSL/QueryBuilder.php` - Main DSL interface with three query methods
- `src/Search/PhoneticMatcher.php` - Phonetic similarity using Metaphone algorithms
- `src/Search/KeyboardProximity.php` - QWERTY keyboard layout analysis for typo detection
- `tests/DSL/QueryParserTest.php` - Comprehensive test coverage for all parsers
- `tests/PreChunkedDocumentTest.php` - Tests for pre-chunked document indexing
- `docs/DSL.md` - Complete documentation with examples and migration guide
- `examples/apartment-search-simple.php` - Comprehensive tutorial demonstrating all YetiSearch features
- `examples/apartment-search-tutorial.php` - Extended version with custom fields configuration
- `examples/pre-chunked-indexing.php` - Demonstrates custom document chunking with semantic boundaries

### Configuration
- Multi-column FTS now **enabled by default** for new indexes
- New default search configuration options:
  ```php
  'multi_column_fts' => true,      // Use separate FTS columns (better performance)
  'exact_match_boost' => 2.0,      // Multiplier for exact phrase matches
  'exact_terms_boost' => 1.5,      // Multiplier for all exact terms present
  'fuzzy_score_penalty' => 0.5,    // Penalty factor for fuzzy-only matches
  'two_pass_search' => false,      // Enable two-pass search (optional)
  'primary_fields' => ['title', 'h1', 'name', 'label'],
  'primary_field_limit' => 100
  ```

### Bug Fixes
- **FTS5 external content deletion**: Fixed critical bug where deleting documents with external content tables failed
  - Now uses FTS5 'rebuild' command to properly sync deletions
  - Automatically disables multi-column FTS when using external content with JSON storage
- **DSL filter parsing**: Fixed IN operator being incorrectly parsed as field name
- **Metadata sorting**: Fixed ORDER BY for metadata fields using proper JSON extraction
- **insertBatch validation**: Added column name sanitization to prevent SQL errors with invalid field names
- **Search result structure**: Fixed content field access in search results
- **Fuzzy highlighting**: Fixed support for fuzzy term highlighting in search results
- **Apostrophe handling**: Improved handling of quotes and apostrophes in exact matches
- **Chunk scoring**: Improved scoring for pages with more matching chunks
- **PHP 7.4 compatibility**: Removed mixed type hints for broader PHP version support

### Performance Improvements
- A/B testing results show multi-column FTS provides best performance:
  - Baseline: 7.09ms average
  - Multi-column FTS: **6.76ms average**
  - Two-pass search: 16.36ms average (higher precision, lower speed)
  - Combined: 16.86ms average

### Improvements
- **Enhanced CLI**: Added examples for DSL usage in help output
- **Documentation**: Updated README with DSL section and links to example files
- **Geo search display**: Fixed distance units conversion from meters to miles in examples

### Migration Notes
- Existing indexes continue to work with single-column mode
- To upgrade existing indexes to multi-column FTS:
  1. Recreate the index with `multi_column_fts => true`
  2. Re-index your documents
- No code changes required for basic usage

### Dependencies Updated
- phpstan/phpstan: 2.1.18 → 2.1.33
- squizlabs/php_codesniffer: 3.13.2 → 4.0.1
- actions/checkout: 4 → 6
- actions/cache: 4 → 5

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
