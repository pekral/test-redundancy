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
- Support for PHP 8.2–8.5, Pest 3–5 and PHPUnit 10–13.
- `pekral/ai-olympus` as a development dependency, with its Claude Code and Codex rules, skills and agents.
