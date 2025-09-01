# Repository Guidelines

## Project Structure & Module Organization
- `src/`: Core library (PSR-4 `YetiSearch\`). Key areas: `Index/`, `Search/`, `Storage/` (SQLite), `Analyzers/`, `Stemmer/`, `Utils/`, `Geo/`, `Models/`, `Contracts/`.
- `tests/`: PHPUnit tests — `Unit/`, `Integration/`, optional `Functional/`; shared `TestCase.php`, fixtures in `Fixtures/`.
- `examples/`: Runnable examples (e.g., `php examples/levenshtein-fuzzy-search.php`).
- `docs/`, `benchmarks/`: Documentation and performance artifacts.
- Tooling: `composer.json`, `phpunit*.xml`, `phpstan.neon`, `Makefile`.

See `docs/architecture-overview.md` for a diagram and component flow.

## Build, Test, and Development Commands
- Install deps: `composer install`
- Run tests: `make test` (or `composer test`)
- Verbose tests: `make test-verbose` or `composer test:pretty`
- Unit-only: `make test-unit`
- Coverage: `make test-coverage` or `composer test:coverage`
- Static analysis: `composer phpstan` (level 2)
- Lint: `composer cs` (PSR-12); Auto-fix: `composer cs-fix`
- Filter a test: `make test-filter TEST=SearchEngineTest`

## Coding Style & Naming Conventions
- Standard: PSR-12 (4-space indent, one class per file, imports at top).
- Namespace: PSR-4 `YetiSearch\...` mapped to `src/` (filenames match class names).
- Methods/variables: `camelCase`; constants: `UPPER_SNAKE_CASE`.
- Add type hints and return types where possible; prefer exceptions in `src/Exceptions/`.

## Testing Guidelines
- Framework: PHPUnit 9; suites in `phpunit.xml.dist` (`Unit`, `Integration`, `Functional`).
- Naming: place tests under matching folders; classes end with `...Test.php`.
- Base: extend `tests/TestCase.php`; use `tests/Fixtures/` for generators/data.
- Coverage: includes `src/` (excludes `Contracts/`, `Exceptions/`). HTML/text/clover reports write to `build/`.
- Commands: `XDEBUG_MODE=coverage composer test:coverage` or `make test-coverage`.

## Commit & Pull Request Guidelines
- Commits: concise, imperative mood (e.g., "fix stemmer edge cases"). Optionally scope (e.g., `SearchEngine:`).
- PRs: clear description, rationale, and linked issues (`Closes #123`). Include tests/docs, update benchmarks if performance-related.
- Quality gate: run `composer phpstan` and `composer cs` locally; ensure CI passes.

## Security & Configuration Tips
- Required extensions: `pdo`, `sqlite3`, `mbstring`, `json` (see `composer.json`).
- SQLite: choose a stable file path; enable WAL/journal settings via config if needed; avoid committing DB files.
- Testing env: phpunit sets SQLite `:memory:`; do not rely on external state.
