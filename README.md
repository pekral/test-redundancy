<h1>
<img align="left" alt="pekral/test-redundancy — a coverage circle with a check mark in a frame" src="assets/logo.svg" width="120">
Fewer tests, the same coverage
<br>
<a href="LICENSE"><img alt="MIT Licensed" src="https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square"></a>
<img alt="Checks" src="https://github.com/pekral/test-redundancy/workflows/Checks/badge.svg">
<br clear="left">
</h1>

`test-redundancy` finds the test cases in a **PHPUnit** or **Pest** suite whose covered lines other test cases
already cover, and tells you — or an AI agent — what each of them asserts beyond those tests. Point it at a test folder
or file. It runs the tests once with per-test coverage and returns a JSON list of candidates to delete, merge into a
dataset, or review, so the suite keeps 100% coverage with fewer and simpler tests.

---

## Requirements

| | Version |
| --- | --- |
| PHP | `^8.4` |
| `phpunit/php-code-coverage` | `^10.1`, `^11`, `^12`, `^13` or `^14` — the one your PHPUnit or Pest installs |
| Coverage driver | `pcov` (the tool sets `pcov.directory` to the project root) or `xdebug` |
| Test runner | `vendor/bin/pest`, or `vendor/bin/phpunit` with `vendor/bin/paratest` — the tool runs the tests only in parallel, so PHPUnit 13 needs 13.4+, the oldest version `paratest` supports |

Contributing to this repository needs PHP 8.4+ with `pcov`.

---

## Installation

```bash
composer require --dev pekral/test-redundancy
```

---

## Configuration

Optional. Without a file the tool reads `src/` and `app/`, runs 2 parallel processes, and picks `vendor/bin/pest`
when it exists, otherwise `vendor/bin/phpunit`. To change that, add `test-redundancy.json` to the project root:

```json
{
    "source": ["app"],
    "ignore": "#^app/Providers/#",
    "env": { "TELESCOPE_ENABLED": "false" },
    "processes": 6,
    "map": "storage/test-redundancy/coverage-map.json.gz",
    "runner": "vendor/bin/pest"
}
```

| Key | Default | Meaning |
| --- | --- | --- |
| `source` | `["src", "app"]` | Directories whose lines count. |
| `ignore` | none | A regex of source paths to leave out, e.g. code every test boots. |
| `env` | `{}` | Environment variables for every test run. Concurrent groups also get `TEST_TOKEN` 1…N, as `paratest` workers do. |
| `processes` | `2` | Parallel processes, at least `2`; `--processes` overrides it. The tool never runs the tests sequentially. |
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

# After changing the tests: fail when a source line the last analyse covered is no longer covered
vendor/bin/test-redundancy verify
```

The JSON is compact; `--pretty` indents it.

The tests always run in parallel, on the test tokens 1…`processes`, so never start the tool next to another run of the
same suite. One folder or file runs in the runner's own parallel mode — `pest --parallel`, or `paratest` next to
`phpunit` — with the coverage settings passed to every worker through `--passthru-php` and the project's
`phpunit.xml` or `phpunit.xml.dist` passed through `--configuration`.
Several paths, such as the files a branch changes, are split into up to `processes` groups that run concurrently,
each with its own `TEST_TOKEN`. Every run passes `--cache-directory=.test-redundancy/cache`, so the coverage analysis cache survives
between runs. `vendor/bin/test-redundancy analyse --help` lists every option.

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
          "coveringDiffersBy": [],
          "differsBy": ["value:0", "value:5"],
          "steps": [
            "Turn \"it adds two numbers › negative numbers\" into one test with a dataset and add a row with the values in differsBy.",
            "Delete this test.",
            "Run vendor/bin/test-redundancy verify; it must report \"ok\": true."
          ],
          "test": "it adds zero"
        }
      ],
      "coveredLines": 4,
      "file": "tests/CalculatorTest.php",
      "testCases": 6,
      "testsWithoutSourceLines": []
    }
  ],
  "summary": {
    "byAction": { "delete": 0, "merge-into-dataset": 2, "review-dataset-row": 1, "review": 0 },
    "candidates": 3,
    "seconds": 0,
    "staleMap": null,
    "testCases": 7,
    "testFiles": 2,
    "testsWithoutSourceLines": 0
  },
  "tool": "pekral/test-redundancy",
  "workflow": ["Goal: 100% coverage with the fewest and simplest tests. Coverage cannot see assertions, so judge every candidate.", "…"]
}
```

| Field | Meaning |
| --- | --- |
| `files` | Only the files with a candidate or a test without source lines. `summary.testFiles` counts every analysed file. |
| `testCases`, `coveredLines` | Test cases of the file, and the source lines they cover. |
| `coveredBy` | A small set of kept tests, picked greedily, that covers every line of the candidate. A test from another file carries its path. |
| `differsBy` | The literals, constants, functions, calls and `new` expressions the candidate has and the covering test that lacks the fewest of them lacks. The fingerprint counts each token, so a token the candidate uses more times than the covering test is listed too. The count is the number of places the token appears in the test source, not the number of runs. A call, function call or `new` expression in the body of a `foreach`, `for`, `while` or `do` loop therefore carries a `loop:` prefix, e.g. `loop:call:Batch::import`: a test that makes the call in a loop and a test that makes it outside a loop differ in that token, and the action is `review`. Two loops differ only in the tokens they carry, never in the number of runs, and a callback passed to a function such as `array_map()` is not a loop body. With several covering tests, `differsBy` is the smallest difference against one of them, never against their union. A call made through Pest's `->not` or `->not()` carries a `not:` prefix, e.g. `not:call:toBeEmpty`. A Pest assertion written as a property, e.g. `->toBeEmpty` or `->not->toBeEmpty`, carries the token of its call form. An array key carries a `key:` prefix, e.g. `key:value:'x'`. A literal under an array key carries the key as a path prefix, e.g. `in:value:'enabled'/value:false` for `['enabled' => false]`, so the same literal under another key, or in a list without a key, is a different token. An empty array literal `[]` carries the token `value:[]`, and under an array key it carries the key path as well, e.g. `in:value:'tags'/value:[]` for `['tags' => []]`. `null` when the test or its dataset row cannot be parsed; the first step then says so. |
| `coveringDiffersBy` | The calls, functions and `new` expressions that made the action `review` although `differsBy` holds only data: the candidate differs only in data from one test of the same file, and that test has them, or has them more times, while the candidate lacks them. The `review` steps name them. Empty for every other candidate. |
| `steps` | What to do with the candidate, naming every covering test, or the sibling dataset row. The last step always runs `verify`. |
| `action` | `delete` — nothing new; `merge-into-dataset` — differs only in data from one test of the same file, and that test has no call, function or `new` expression this test lacks or uses fewer times; `review-dataset-row` — a row of the same dataset runs the same lines; `review` — asserts something the covering tests do not, differs from one test of the same file in more than data, or could not be parsed. |
| `testsWithoutSourceLines` | Tests that run no line under `source`. They are never candidates; check what they test, or the `source` setting. |
| `summary.staleMap` | With `--across`: `null`, or the map commit and the source files changed since it was built. |
| `summary.seconds` | How long the analysis took. |
| `workflow` | The rules an agent follows for the whole report. |

### Verifying that coverage stayed

`analyse` stores the source lines the analysed tests cover in `.test-redundancy/baseline.json` (next to the map). Add
`.test-redundancy/` to `.gitignore`; the map, the baseline and the runner cache are local state. After
the tests change, `verify` runs the same test files again and compares:

```json
{"baselineLines":4,"instruction":"Every source line the analysed tests covered before is still covered.","lostLines":[],"missingTestFiles":[],"ok":true,"tool":"pekral/test-redundancy","unstableLines":[]}
```

It exits with `1` and lists every line in `lostLines` when a change dropped coverage. After `--across`, a line another
mapped test file still covers does not count as lost.

`analyse` also stores a hash of every analysed test file, of every covered source file and of the test support files.
The support files are the other files, not named `*Test.php`, in the top-level directory of a test file, such as
`tests/`: Pest datasets, `tests/Pest.php`, base test cases, traits and fixtures. When a line is no longer covered, none
of the test files that covered it changed, its source file did not change and no support file changed, `verify` lists it
in `unstableLines` instead of `lostLines`, exits with `0` and tells the agent not to restore a test. The coverage of such
a test depends on state outside the test run, such as a cache or a database. A line that a changed or deleted test file
also covered, a line of a source file that changed since `analyse`, and every lost line after a support file was
changed, added or deleted stay in `lostLines`, and `verify` exits with `1`. A baseline written before source file hashes
or support file hashes existed reports every lost line in `lostLines`; run `analyse` again to restore the distinction.

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
3. For each candidate it picks a small covering set greedily and compares contract fingerprints built with
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

Pull requests and pushes to `master` run `composer check` on PHP 8.4 and 8.5, then the tool on its own tests. A matrix
runs the tests on PHP 8.4 and 8.5 with the lowest and the highest dependencies, and a second matrix runs the tool on
the PHPUnit fixture with PHPUnit 10.5, 11.5, 12.5 and 13.4 with `paratest` and no Pest, on PHP 8.4. A security audit runs every
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
