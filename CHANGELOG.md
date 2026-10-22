# Changelog

All notable changes to `pekral/test-redundancy` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed

- `verify` no longer lists a line in `lostLines` and says to restore a test when no covering test file changed. `analyse`
  stores a hash of each analysed test file in the baseline. A lost line whose covering test files are all unchanged is
  reported in the new `unstableLines`, with an instruction not to restore a test, and `verify` exits with `0`. A baseline
  written by an earlier version reports every lost line as before.
- A call made through Pest's negation `->not` or `->not()` now carries a `not:` prefix in the contract fingerprint
  (`not:call:toBeEmpty`), so a test that asserts the opposite result is reported with a non-empty `differsBy`
  and the action `review` instead of `delete` (#10).
- `verify` reports a lost line in a source file that changed since `analyse` in `lostLines`, not in `unstableLines`.
  `analyse` stores a hash of each covered source file in the baseline. A baseline written before this change has no
  source hashes, so `verify` reports every lost line of it in `lostLines`.

## [0.1] - 2026-10-22

### Added

- `vendor/bin/test-redundancy [paths]` — finds PHPUnit and Pest test cases whose covered lines other test cases
  already cover, from one run with per-test coverage. A folder runs in parallel.
- A logo: one coverage circle with a check mark in a frame, also used in the social preview.
- JSON output for AI agents with an `action` per candidate (`delete`, `merge-into-dataset`, `review-dataset-row`,
  `review`), the smallest covering set, and what the candidate asserts beyond it. `--format=text` for people.
- `map` and `--across` — a per-test coverage map of the whole suite, to compare tests across files.
- `test-redundancy.json` for source paths, ignored sources, environment, processes, map path and runner.
- Support for PHP 8.4–8.5, Pest 3–5 and PHPUnit 10–13.
- `testsWithoutSourceLines` and `summary.staleMap` in the JSON report.
- `verify` — fails and lists every source line the last `analyse` covered that no test covers any more.
- `steps` per candidate and a `workflow` for AI agents; compact JSON by default, `--pretty` to indent it.
- `--processes` for `analyse` and `map`; several paths run as concurrent groups, each with its own `TEST_TOKEN`.
- Pest tests in a namespace, inside `describe()` blocks and with shared `dataset()` rows get a contract fingerprint.
- `pekral/ai-olympus` as a development dependency, with its Claude Code and Codex rules, skills and agents.

### Changed

- The Composer keywords name Pest, paratest, code and test coverage, duplicate tests and AI agents, so Packagist search
  finds the package.
- The package requires PHP 8.4 or newer; the quality checks run on PHP 8.4 and 8.5.
- The tests run only in parallel: `processes` must be at least 2, PHPUnit needs `paratest`, and the one `env` map
  replaces `env` and `parallelEnv`.

### Fixed

- Parallel workers get the coverage settings through `--passthru-php` and the project configuration through
  `--configuration`; before, a worker that guessed another `pcov.directory` recorded no coverage.

[Unreleased]: https://github.com/pekral/test-redundancy/compare/0.1...HEAD
[0.1]: https://github.com/pekral/test-redundancy/releases/tag/0.1
