# Usage

Commands, options, configuration and the `verify` and `--across` workflows of `vendor/bin/test-redundancy`.

## Commands and options

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

| Command | What it does |
| --- | --- |
| `analyse` (default) | Lists the tests whose covered lines other tests already cover, as candidates to review. |
| `verify` | Fails when a source line the last `analyse` run covered is no longer covered. |
| `map` | Builds the per-test coverage map that `analyse --across` compares with. |

| Option | Commands | Meaning |
| --- | --- | --- |
| `--format=json\|text` | `analyse` | `json` (default, for AI agents) or `text` (for people). |
| `--pretty` | `analyse`, `verify` | Indents the JSON, which is compact by default. |
| `--processes=N` | all | Parallel processes; overrides `processes` in the configuration. |
| `--base=<ref>` | `analyse` | Git ref for the changed test files when no path is given; default `origin/HEAD`. |
| `--across` | `analyse` | Compares with every test in the coverage map — see [Comparing across files](#comparing-across-files). |

`vendor/bin/test-redundancy analyse --help` lists every option.

> [!WARNING]
> The tests always run in parallel, on the test tokens 1…`processes`. Never start the tool next to another run of the
> same suite.

### How the tool runs your tests

A folder is expanded to its test files first. The files of every run, whether you name a folder, one file or
several paths, are split into up to `processes` groups that run concurrently, each with its own `TEST_TOKEN`. Each group
is one non-parallel `pest` or `phpunit` process with the coverage settings passed through `-d` options and the project's
`phpunit.xml` or `phpunit.xml.dist` passed through `--configuration`. A folder without test files runs nothing, and a
`*Test.php` file that only declares an enum, an interface, a trait, a function or a class without a test method or a
trait, and runs no other statement, is left out of the run. A file whose classes have a parent class is left out only
when none of them extends `PHPUnit\Framework\TestCase`: a child process loads the file through the runner's
`vendor/autoload.php` to find that out, and a file that cannot be loaded stays in the run.
Every run stores the time of each test file from its JUnit log in
`.test-redundancy/durations.json` (next to the map). The next run puts the longest file first and every file into the
group with the smallest total, so no group collects the slowest files; a file without a stored time goes first. Without any
stored time the files are split round-robin. The split changes how long the run takes. For tests that share no state
inside one process it never changes the report or the baseline; a test whose coverage depends on what an earlier test
file in the same group already ran, such as a static cache, can cover other lines in another group.
Every run passes `--cache-directory=.test-redundancy/cache`, so the coverage analysis cache survives
between runs. `vendor/bin/test-redundancy analyse --help` lists every option.

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
| `env` | `{}` | Environment variables for every test run. Concurrent groups also get `TEST_TOKEN` 1…N, as `paratest` workers do, and `LARAVEL_PARALLEL_TESTING=1`, so a Laravel project gives each group its own test database; set it in `env` to change it. |
| `processes` | `2` | Parallel processes, at least `2`; `--processes` overrides it. The tool never runs the tests sequentially. |
| `map` | `.test-redundancy/coverage-map.json.gz` | Where the `map` command stores the coverage map. |
| `runner` | auto | Path to `pest` or `phpunit`, relative to the project root or absolute. |

## Verifying that coverage stayed

`analyse` stores the source lines the analysed tests cover in `.test-redundancy/baseline.json` (next to the map). Add
`.test-redundancy/` to `.gitignore`; the map, the baseline, the file durations and the runner cache are local state. After
the tests change, `verify` runs the same test files again and compares:

```json
{"baselineLines":4,"instruction":"Every source line the analysed tests covered before is still covered.","lostLines":[],"missingTestFiles":[],"ok":true,"tool":"pekral/test-redundancy","unstableLines":[]}
```

A test file that `analyse` skipped stays in the baseline without lines, so `verify` runs it and reports its deletion in
`missingTestFiles`. `verify` cannot know the lines that only a deleted skipped file covered, because `analyse` never ran it.

It exits with `1` and lists every line in `lostLines` when a change dropped coverage. After `--across`, a line another
mapped test file still covers does not count as lost.

### Lost lines vs. unstable lines

`analyse` also stores a hash of every analysed test file, of every covered source file and of the test support files.
The support files are the other files, not named `*Test.php`, in the top-level directory of a test file, such as
`tests/`: Pest datasets, `tests/Pest.php`, base test cases, traits and fixtures. When a line is no longer covered, none
of the test files that covered it changed, its source file did not change and no support file changed, `verify` lists it
in `unstableLines` instead of `lostLines`, exits with `0` and tells the agent not to restore a test. The coverage of such
a test depends on state outside the test run, such as a cache or a database. A line that a changed or deleted test file
also covered, a line of a source file that changed since `analyse`, and every lost line after a support file was
changed, added or deleted stay in `lostLines`, and `verify` exits with `1`. When a changed support file is the reason and no
baseline test file is missing, the `instruction` tells the agent to check that file or to run `analyse` again, not to
restore a test. When a baseline test file is missing too, the `instruction` still tells the agent to restore the test and
adds that a changed support file may also be the reason. A baseline written before source file hashes
or support file hashes existed reports every lost line in `lostLines`; run `analyse` again to restore the distinction.

## Comparing across files

```bash
# Per-test coverage of the given tests (default: tests)
vendor/bin/test-redundancy map tests

# Compare a folder or a file with every mapped test
vendor/bin/test-redundancy tests/Unit/Billing --across
```

`--across` reads unchanged test files from the map and re-runs only the changed ones. It warns when source files changed
since the map was built. Of two tests that cover each other, it proposes at most one.
