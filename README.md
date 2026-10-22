<div align="left">
    <img alt="pekral/test-redundancy — two overlapping test coverage circles, one kept, one removed" src="assets/logo.svg" width="480">

<h1>Fewer tests, the same coverage</h1>

[![MIT Licensed](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
![Checks](https://github.com/pekral/test-redundancy/workflows/Checks/badge.svg)

</div>

`test-redundancy` finds the test cases in a **PHPUnit** or **Pest** suite whose covered lines other test cases
already cover, and tells you — or an AI agent — what each of them asserts beyond those tests. Point it at a test folder
or file. It runs the tests once with per-test coverage and returns a JSON list of candidates to delete, merge into a
dataset, or review, so the suite keeps 100% coverage with fewer and simpler tests.

---

## Requirements

| | Version |
| --- | --- |
| PHP | `^8.2` |
| `phpunit/php-code-coverage` | `^10.1`, `^11`, `^12`, `^13` or `^14` — the one your PHPUnit or Pest installs |
| Coverage driver | `pcov` (the tool sets `pcov.directory` to the project root) or `xdebug` |
| Test runner | `vendor/bin/pest`, or `vendor/bin/phpunit` (and `vendor/bin/paratest` for parallel runs) |

Contributing to this repository needs PHP 8.4+, because the development dependencies require it.

---

## Installation

```bash
composer require --dev pekral/test-redundancy
```

---

## Configuration

Optional. Without a file the tool reads `src/` and `app/`, runs 4 parallel processes, and picks `vendor/bin/pest`
when it exists, otherwise `vendor/bin/phpunit`. To change that, add `test-redundancy.json` to the project root:

```json
{
    "source": ["app"],
    "ignore": "#^app/Providers/#",
    "env": { "TEST_TOKEN": "7" },
    "parallelEnv": {},
    "processes": 6,
    "map": "storage/test-redundancy/coverage-map.json.gz",
    "runner": "vendor/bin/pest"
}
```

| Key | Default | Meaning |
| --- | --- | --- |
| `source` | `["src", "app"]` | Directories whose lines count. |
| `ignore` | none | A regex of source paths to leave out, e.g. code every test boots. |
| `env` | `{}` | Environment variables for a sequential run: `processes: 1`, or one path with PHPUnit and no `paratest`. |
| `parallelEnv` | `{}` | Environment variables for a parallel run. Concurrent groups also get `TEST_TOKEN` 1…N, as `paratest` workers do. |
| `processes` | `4` | Parallel processes; `--processes` overrides it. `1` runs sequentially. |
| `map` | `.test-redundancy/coverage-map.json.gz` | Where the `map` command stores the coverage map. |
| `runner` | auto | Path to `pest` or `phpunit`, relative to the project root or absolute. |

---

## Usage

```bash
# A test folder or a single test file
vendor/bin/test-redundancy tests/Unit/Billing
vendor/bin/test-redundancy tests/Unit/Billing/InvoiceTest.php

# No path: the test files the branch changes against origin/HEAD, or against --base
vendor/bin/test-redundancy
vendor/bin/test-redundancy --base=master

# A report for people instead of JSON
vendor/bin/test-redundancy tests/Unit --format=text

# More or fewer parallel processes than the configuration says
vendor/bin/test-redundancy tests/Unit --processes=8
```

One folder or file runs in the runner's own parallel mode — `pest --parallel`, or `paratest` next to `phpunit`.
Several paths, such as the files a branch changes, are split into up to `processes` groups that run concurrently,
each with its own `TEST_TOKEN`. Every run passes `--cache-directory`, so the coverage analysis cache survives between
runs. `vendor/bin/test-redundancy analyse --help` lists every option.

### The JSON report

This is the report for the Pest fixture in [`tests/Fixtures/pest`](tests/Fixtures/pest), shortened to one candidate:

```json
{
  "files": [
    {
      "candidates": [
        {
          "action": "merge-into-dataset",
          "coveredBy": ["it adds two numbers › negative numbers"],
          "differsBy": ["value:0", "value:5"],
          "reason": "Differs from the covering test only in data. Merge both into one test with a dataset row per case.",
          "test": "it adds zero"
        }
      ],
      "coveredLines": 4,
      "file": "tests/CalculatorTest.php",
      "testCases": 6,
      "testsWithoutSourceLines": []
    }
  ],
  "instructions": ["Goal: keep 100% line coverage with the fewest and simplest tests.", "…"],
  "summary": {
    "byAction": { "delete": 0, "merge-into-dataset": 2, "review-dataset-row": 1, "review": 0 },
    "candidates": 3,
    "seconds": 0,
    "staleMap": null,
    "testCases": 7,
    "testFiles": 2,
    "testsWithoutSourceLines": 0
  },
  "tool": "pekral/test-redundancy"
}
```

| Field | Meaning |
| --- | --- |
| `files` | Only the files with a candidate or a test without source lines. `summary.testFiles` counts every analysed file. |
| `testCases`, `coveredLines` | Test cases of the file, and the source lines they cover. |
| `coveredBy` | A small set of kept tests, picked greedily, that covers every line of the candidate. A test from another file carries its path. |
| `differsBy` | The literals, constants, functions, calls and `new` expressions the candidate has and its covering tests lack. `null` when the test or its dataset row cannot be parsed; `reason` then says so. |
| `action` | `delete` — nothing new; `merge-into-dataset` — differs only in data from one test of the same file; `review-dataset-row` — a row of the same dataset runs the same lines; `review` — asserts something the covering tests do not, or could not be parsed. |
| `testsWithoutSourceLines` | Tests that run no line under `source`. They are never candidates; check what they test, or the `source` setting. |
| `summary.staleMap` | With `--across`: `null`, or the map commit and the source files changed since it was built. |
| `summary.seconds` | How long the analysis took. |

### How to read a candidate

A candidate runs no line the other tests do not run. Coverage cannot see what a test asserts, so a candidate is not
proof that a test is useless:

1. **Delete** it only when a test under `coveredBy` asserts the same input and the same result.
2. **Merge it into a dataset** when the two differ only in data.
3. **Keep** a test that asserts a different result, a different input class (empty, boundary, another tenant,
   invalid), an ordering, or a rejection. When in doubt, keep it.

The candidates of one file can be removed together without losing a covered line. Re-run the tool after each change.

### Comparing across files

```bash
# Per-test coverage of the given tests (default: tests)
vendor/bin/test-redundancy map tests

# Compare a folder or a file with every mapped test
vendor/bin/test-redundancy tests/Unit/Billing --across
```

`--across` reads unchanged test files from the map and re-runs only the changed ones. It warns when source files changed
since the map was built. Of two tests that cover each other, it proposes at most one.

---

## How it works

1. One test run with `--coverage-php` and `--log-junit` records which test case — every dataset row separately —
   covers which source line. Test descriptions come from the JUnit log.
2. A greedy pass removes test cases whose lines the remaining test cases still cover. On a tie it removes standalone
   tests before dataset rows.
3. For each candidate it computes the smallest covering set and compares contract fingerprints built with
   `nikic/php-parser`: Pest `it()` / `test()` with `->with()` datasets and chained modifiers such as `->throws()`, and
   PHPUnit `test*` / `#[Test]` / `@test` methods with `#[DataProvider]` / `@dataProvider` rows. Pest tests inside a
   namespace or `describe()` blocks, and shared `dataset('name', …)` rows of the same file, are parsed too.

---

## Testing

```bash
composer install
composer test
```

| Command | What it does |
| --- | --- |
| `composer check` | Composer normalize, PHPCS, Pint, Rector, PHPStan, security audit, and the tests with 100% coverage |
| `composer fix` | Composer normalize, Rector, Pint and PHPCS fixes |
| `composer test` | The tests without coverage |
| `composer test:coverage` | The tests with a 100% coverage minimum |
| `composer ai-olympus:install` | Reinstall the [pekral/ai-olympus](https://github.com/pekral/ai-olympus) agent rules and skills |

PHPCS, PHPStan and Rector use [pekral/phpcs-rules](https://github.com/pekral/phpcs-rules),
[pekral/phpstan-rules](https://github.com/pekral/phpstan-rules) and [pekral/rector-rules](https://github.com/pekral/rector-rules).

### CI

Pull requests and pushes to `master` run `composer check` on PHP 8.5, then the tool on its own tests. A matrix runs
the tests on PHP 8.2, 8.3, 8.4 and 8.5 with the lowest and the highest dependencies, and a second matrix runs the tool
on the PHPUnit fixture with PHPUnit 10.5, 11.5, 12.5 and 13.0 and no Pest. A security audit runs every
Monday, and Dependabot proposes Composer and GitHub Actions updates weekly.

---

## Changelog

See [CHANGELOG](CHANGELOG.md).

## Contributing

See [CONTRIBUTING](CONTRIBUTING.md).

## Security

See [the security policy](SECURITY.md).

## License

MIT — see [LICENSE](LICENSE).
