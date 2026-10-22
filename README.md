<div align="center">
  <img src="assets/logo.svg" alt="pekral/test-redundancy" width="480">

  <p><strong>Fewer tests. Same coverage.</strong></p>

  <a href="https://packagist.org/packages/pekral/test-redundancy"><img src="https://img.shields.io/packagist/v/pekral/test-redundancy" alt="Packagist Version"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-brightgreen.svg" alt="MIT Licensed"></a>
  <a href="https://github.com/pekral/test-redundancy/actions/workflows/pr.yml"><img src="https://github.com/pekral/test-redundancy/actions/workflows/pr.yml/badge.svg" alt="Checks"></a>
  <a href="https://packagist.org/packages/pekral/test-redundancy"><img src="https://img.shields.io/packagist/dt/pekral/test-redundancy" alt="Total Downloads"></a>
</div>

---

Test suites grow, and nobody removes tests. AI agents make it worse: they add a test for every change, often one
that runs exactly the lines another test already runs.

`test-redundancy` finds those tests in a **PHPUnit** or **Pest** suite. It runs a test file or folder once with
per-test coverage, finds the test cases whose covered lines other test cases already cover, and tells you — or an AI
agent — what each one asserts beyond them. The goal is **100% coverage with the fewest and simplest tests**.

---

## Requirements

| | Version |
| --- | --- |
| PHP | `^8.2` |
| Test runner | Pest 3–5 or PHPUnit 10–13 (`phpunit/php-code-coverage` 10.1–14) |
| Coverage driver | `pcov` (recommended) or `xdebug` |

Contributing to this repository needs **PHP 8.4+**, because the quality tooling requires it. Using the package
needs 8.2.

---

## Installation

```bash
composer require --dev pekral/test-redundancy
```

---

## Usage

```bash
# A folder or a single test file
vendor/bin/test-redundancy tests/Unit/Billing
vendor/bin/test-redundancy tests/Unit/Billing/InvoiceTest.php

# Without a path: the test files the branch changes against origin/HEAD (or --base=<ref>)
vendor/bin/test-redundancy

# Human-readable output instead of JSON
vendor/bin/test-redundancy tests/Unit --format=text
```

A folder runs **once**, in parallel (`pest --parallel`, or `paratest` for PHPUnit). A single file takes seconds;
a folder of 300 test files takes about as long as running it with coverage.

### Output for AI agents

The default output is JSON. It carries the rules an agent applies, a summary, and one entry per candidate:

```json
{
  "tool": "pekral/test-redundancy",
  "instructions": ["Goal: keep 100% line coverage with the fewest and simplest tests.", "…"],
  "summary": { "testFiles": 2, "testCases": 7, "candidates": 3, "byAction": { "delete": 0, "merge-into-dataset": 2, "review-dataset-row": 1, "review": 0 } },
  "files": [
    {
      "file": "tests/CalculatorTest.php",
      "candidates": [
        {
          "test": "it adds zero",
          "action": "merge-into-dataset",
          "reason": "Differs from the covering test only in data. Merge both into one test with a dataset row per case.",
          "coveredBy": ["it adds two numbers › negative numbers"],
          "differsBy": ["value:0", "value:5"]
        }
      ]
    }
  ]
}
```

| Field | Meaning |
| --- | --- |
| `coveredBy` | The smallest set of kept tests that covers every line the candidate covers. A test from another file carries its path. |
| `differsBy` | The literals, constants, functions, calls and `new` expressions the candidate has and its covering tests lack. `null` when a test or a dataset row cannot be parsed. |
| `action` | `delete` — nothing new; `merge-into-dataset` — differs only in data from one test of the same file; `review-dataset-row` — a sibling row runs the same lines; `review` — asserts something the others do not. |

### How to read a candidate

**A candidate is a test that covers no line the other tests do not cover. It is not proof that the test is useless.**
Coverage shows which lines a test runs, never what it asserts. Two tests can run the same lines with different input
and expect different results. So:

1. **Delete** a candidate only when a test under `coveredBy` asserts the same input and the same result.
2. **Merge into a dataset** when the two differ only in data — nothing is lost, the file gets shorter.
3. **Keep** a test that asserts a different result, a different input class (empty, boundary, another tenant,
   invalid), an ordering, or a rejection. **When in doubt, keep it.**

Candidates of one file can be removed together without losing a covered line. Re-run the tool after each change.

### Comparing across files

```bash
# Once, or on a schedule: per-test coverage of the whole suite
vendor/bin/test-redundancy map tests

# Then compare any file or folder with every mapped test
vendor/bin/test-redundancy tests/Unit/Billing --across
```

`--across` reads unchanged test files straight from the map and re-runs only the changed ones. It warns when source
files changed since the map was built. Of two tests that cover each other, it never proposes both.

---

## Configuration

Optional. Put a `test-redundancy.json` in the project root:

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
| `ignore` | none | A regex of source paths to leave out, e.g. service providers every test boots. |
| `env` | `{}` | Environment variables for a single-file run, e.g. a spare database token. |
| `parallelEnv` | `{}` | Environment variables for a parallel run. |
| `processes` | `4` | Parallel processes for a folder. `1` runs sequentially. |
| `map` | `.test-redundancy/coverage-map.json.gz` | Where `map` stores the coverage map. |
| `runner` | auto | Path to `pest` or `phpunit`, when not in `vendor/bin`. |

---

## How it works

1. One test run with `--coverage-php` and `--log-junit` records which test case — every dataset row separately —
   covers which source line. Real test descriptions come from the JUnit log, so they match the test file.
2. A greedy pass removes test cases whose lines the remaining test cases still cover. On a tie it removes standalone
   tests before dataset rows, because one parametrised test is simpler than several copies.
3. For every candidate it computes the smallest covering set and parses the test files with `nikic/php-parser` to
   build a contract fingerprint per test case — Pest `it()`/`test()` with `->with()` datasets and chained modifiers
   such as `->throws()`, and PHPUnit `test*` / `#[Test]` methods with `#[DataProvider]` / `@dataProvider` rows.

There is no mutation testing: it took minutes per file and still could not see contracts its mutators cannot break.

---

## Development

```bash
composer install
```

| Command | What it does |
| --- | --- |
| `composer check` | Composer normalize, PHPCS, Pint, Rector, PHPStan (max level), audit, tests with 100% coverage |
| `composer fix` | Apply Rector, Pint and PHPCS fixes |
| `composer test` | Tests without coverage |
| `php bin/test-redundancy tests` | Run the tool on its own tests |

### CI

Pull requests and pushes to `master` run `composer check` on PHP 8.5 and the tool on its own tests. A matrix runs the
tests on PHP 8.2, 8.3, 8.4 and 8.5 with the lowest and the highest dependencies. Dependabot proposes dependency updates
weekly, and a security audit runs every Monday.

---

## Links

* [Packagist](https://packagist.org/packages/pekral/test-redundancy)
* [Changelog](CHANGELOG.md) · [Contributing](CONTRIBUTING.md) · [Security policy](SECURITY.md)
* [Discussions](https://github.com/pekral/test-redundancy/discussions)

---

## License

MIT — see [LICENSE](LICENSE).

Maintained by [Petr Král](https://github.com/pekral).
