.PHONY: test test-verbose test-coverage test-watch help

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