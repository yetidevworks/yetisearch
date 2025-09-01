.PHONY: test test-verbose test-coverage test-watch help rg rg-files sd sd-preview sd-in
 .PHONY: bench-after

# Default target
help:
	@echo "YetiSearch Test Commands:"
	@echo ""
	@echo "  make test           Run all tests with simple output"
	@echo "  make test-verbose   Run tests with descriptive output"
	@echo "  make test-coverage  Run tests with coverage report"
	@echo "  make test-watch     Watch for changes and re-run tests"
	@echo "  make test-unit      Run only unit tests"
	@echo "  make test-filter    Run specific test (use TEST=TestName)"
	@echo ""
	@echo "Developer Shortcuts:"
	@echo "  make rg PATTERN=... [PATHS=\"src tests\"]             # ripgrep search"
	@echo "  make rg-files PATTERN=... [PATHS=\"src tests\"]        # list files matching pattern"
	@echo "  make sd FROM=... TO=... FILES=\"file1 file2\" [MODE=literal|regex]  # replace"
	@echo "  make sd-preview FROM=... TO=... FILES=\"...\" [MODE=...]           # preview replace"
	@echo "  make sd-in PATTERN=... FROM=... TO=... [PATHS=\"src tests\"] [MODE=...] # replace in files matching PATTERN"
	@echo ""
	@echo "Benchmarks:"
	@echo "  make bench-after     Run benchmark and save to benchmarks/benchmark-after.txt"
	@echo "  make bench-before    Run legacy benchmark and save to benchmarks/benchmark-before.txt"
	@echo "  make bench-compare   Run both and print delta summary"
		@echo "  make bench-throughput  Run with throughput preset and save to benchmarks/benchmark-throughput.txt"

bench-after:
	@echo "Running benchmark (external-content default) ..."
	@rm -f benchmarks/benchmark.db benchmarks/benchmark.db-shm benchmarks/benchmark.db-wal
	@php benchmarks/benchmark.php --external=1 | tee benchmarks/benchmark-after.txt

bench-before:
	@echo "Running benchmark (legacy schema) ..."
	@rm -f benchmarks/benchmark.db benchmarks/benchmark.db-shm benchmarks/benchmark.db-wal
	@php benchmarks/benchmark.php --external=0 | tee benchmarks/benchmark-before.txt

# Run all tests
test:
	@vendor/bin/phpunit

# Run tests with verbose output
test-verbose:
	@vendor/bin/phpunit --testdox --colors=always

# Run tests with coverage
test-coverage:
	@XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text --colors=always

# Watch for changes
test-watch:
	@echo "Watching for changes... (requires phpunit-watcher)"
	@vendor/bin/phpunit-watcher watch

# Run only unit tests
test-unit:
	@vendor/bin/phpunit --testsuite=Unit --testdox

# Run filtered tests
test-filter:
	@vendor/bin/phpunit --testdox --filter="$(TEST)"

# Pretty output
test-pretty:
	@php test-runner.php

# ripgrep helpers
RG_PATHS?=src tests

rg:
	@rg -n "$(PATTERN)" $(PATHS)

rg-files:
	@rg -l "$(PATTERN)" $(PATHS)

# sd helpers (MODE=literal|regex; default literal)
sd:
	@if [ -z "$(FROM)" ] || [ -z "$(TO)" ] || [ -z "$(FILES)" ]; then \
		echo "Usage: make sd FROM=... TO=... FILES=\"file1 file2\" [MODE=literal|regex]"; exit 1; fi; \
	flag="-s"; if [ "$(MODE)" = "regex" ]; then flag=""; fi; \
	for f in $(FILES); do sd $$flag "$(FROM)" "$(TO)" "$$f"; done

sd-preview:
	@if [ -z "$(FROM)" ] || [ -z "$(TO)" ] || [ -z "$(FILES)" ]; then \
		echo "Usage: make sd-preview FROM=... TO=... FILES=\"file1 file2\" [MODE=literal|regex]"; exit 1; fi; \
	flag="-s"; if [ "$(MODE)" = "regex" ]; then flag=""; fi; \
	for f in $(FILES); do sd -p $$flag "$(FROM)" "$(TO)" "$$f"; done

sd-in:
	@if [ -z "$(PATTERN)" ] || [ -z "$(FROM)" ] || [ -z "$(TO)" ]; then \
		echo "Usage: make sd-in PATTERN=... FROM=... TO=... [PATHS=\"src tests\"] [MODE=literal|regex]"; exit 1; fi; \
	files=`rg -l "$(PATTERN)" $(PATHS)`; \
	if [ -z "$$files" ]; then echo "No files matched"; exit 0; fi; \
	flag="-s"; if [ "$(MODE)" = "regex" ]; then flag=""; fi; \
	for f in $$files; do sd $$flag "$(FROM)" "$(TO)" "$$f"; done



bench-compare:
	@$(MAKE) bench-before
	@$(MAKE) bench-after
	@echo ""
	@php scripts/bench_compare.php


bench-throughput:
	@echo "Running benchmark (throughput preset) ..."
	@rm -f benchmarks/benchmark.db benchmarks/benchmark.db-shm benchmarks/benchmark.db-wal
	@php benchmarks/benchmark.php --external=1 --multi-column=0 --prefix= --spatial=0 --fts-detail=full | tee benchmarks/benchmark-throughput.txt


