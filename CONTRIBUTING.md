# Contributing

Thanks for helping. Issues and pull requests are welcome.

## Setup

Contributing needs PHP 8.4+ with `pcov`, because the quality tooling requires it.

```bash
composer install
```

## Before you open a pull request

1. `composer fix` applies Rector, Pint and PHPCS fixes.
2. `composer check` must pass. It includes PHPStan at max level and tests with 100% coverage.
3. `php bin/test-redundancy tests/Unit tests/Feature` — run the tool on its own tests. Resolve every candidate your new tests produce:
   delete it, merge it into a dataset, or keep it because it asserts something the others do not.
4. Add an entry under `## [Unreleased]` in `CHANGELOG.md`.

## Rules

- The package supports PHP 8.2. Do not use syntax or functions newer than 8.2 in `src/` (typed class constants,
  `new Foo()->bar()`, `#[\Override]`, `array_find()` …). The CI matrix runs the tests on PHP 8.2–8.5.
- Commit subjects follow Conventional Commits, in English, without AI attribution trailers.
- Test fixtures live in `tests/Fixtures` and are excluded from the tooling.
