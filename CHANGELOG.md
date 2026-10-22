# Changelog

All notable changes to `pekral/test-redundancy` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- `vendor/bin/test-redundancy [paths]` — finds PHPUnit and Pest test cases whose covered lines other test cases
  already cover, from one run with per-test coverage. A folder runs in parallel.
- JSON output for AI agents with an `action` per candidate (`delete`, `merge-into-dataset`, `review-dataset-row`,
  `review`), the smallest covering set, and what the candidate asserts beyond it. `--format=text` for people.
- `map` and `--across` — a per-test coverage map of the whole suite, to compare tests across files.
- `test-redundancy.json` for source paths, ignored sources, environment, processes, map path and runner.
- Support for PHP 8.4–8.5, Pest 3–5 and PHPUnit 10–13.
- `testsWithoutSourceLines` and `summary.staleMap` in the JSON report.
- `verify` — fails and lists every source line the last `analyse` covered that no test covers any more.
- `steps` per candidate and a `workflow` for AI agents; compact JSON by default, `--pretty` to indent it.
- `--processes` for `analyse` and `map`; several paths run as concurrent groups, each with its own `TEST_TOKEN`.

### Changed

- The package requires PHP 8.4 or newer; the quality checks run on PHP 8.4 and 8.5.
- The tests run only in parallel: `processes` must be at least 2, PHPUnit needs `paratest`, and the one `env` map
  replaces `env` and `parallelEnv`.

### Fixed

- Parallel workers get the coverage settings through `--passthru-php` and the project configuration through
  `--configuration`; before, a worker that guessed another `pcov.directory` recorded no coverage.
- Pest tests in a namespace, inside `describe()` blocks and with shared `dataset()` rows get a contract fingerprint.
- `pekral/ai-olympus` as a development dependency, with its Claude Code and Codex rules, skills and agents.
