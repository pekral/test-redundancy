<div align="left">
    <picture>
        <img alt="pekral/test-redundancy — a coverage circle with a check mark in a frame" src="assets/logo.svg" width="120">
    </picture>

<h1>test-redundancy</h1>

[![Latest Version on Packagist](https://img.shields.io/packagist/v/pekral/test-redundancy.svg?style=flat-square)](https://packagist.org/packages/pekral/test-redundancy)
[![MIT Licensed](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
![Checks](https://github.com/pekral/test-redundancy/workflows/Checks/badge.svg)
[![Total Downloads](https://img.shields.io/packagist/dt/pekral/test-redundancy.svg?style=flat-square)](https://packagist.org/packages/pekral/test-redundancy)

</div>

`test-redundancy` finds the tests in a PHPUnit or Pest suite whose covered lines other tests already cover, and shows
what each of them asserts beyond those tests. It runs the tests once with per-test coverage and gives every candidate an
action: delete it, merge it into a dataset, review it, or keep it as the only test of an error path. The JSON output is
built for AI agents; `--format=text` is built for people.

## Requirements

| | Version |
| --- | --- |
| PHP | `^8.4` with `ext-libxml`, `ext-simplexml` and `ext-zlib` |
| `phpunit/php-code-coverage` | `^10.1`, `^11`, `^12`, `^13` or `^14.5.1` — the one your PHPUnit or Pest installs |
| Coverage driver | `pcov` (the tool limits `pcov.directory` to the `source` directories) or `xdebug` |
| Test runner | `vendor/bin/pest` or `vendor/bin/phpunit` |

## Installation

```bash
composer require --dev pekral/test-redundancy
```

The tool keeps its local state (the coverage map, the baseline, the file durations and the runner cache) in
`.test-redundancy/`. Add it to `.gitignore`:

```bash
echo ".test-redundancy/" >> .gitignore
```

## Configuration

Optional. Without a `test-redundancy.json` in the project root, the tool reads `src/` and `app/`, runs 2 parallel
processes, and picks `vendor/bin/pest` when it exists, otherwise `vendor/bin/phpunit`. The keys `source`, `ignore`,
`env`, `processes`, `map` and `runner` are described in [Configuration](docs/usage.md#configuration).

## Quick start

1. Analyse a test folder and read the report:

   ```bash
   vendor/bin/test-redundancy tests/Unit/Billing --format=text
   ```

   > [!WARNING]
   > The tests always run in parallel, on the test tokens 1…`processes`. Never start the tool next to another run of
   > the same suite.

2. Act on each candidate, from the top: the report puts the slowest candidates first. Follow its `steps`, see
   [How to read a candidate](docs/report.md#how-to-read-a-candidate).

3. Verify that every source line the analysed tests covered is still covered:

   ```bash
   vendor/bin/test-redundancy verify
   ```

   `verify` exits with `1` and lists the lost lines when a change dropped coverage.

Without a path, `analyse` takes the test files the branch changes against `origin/HEAD`, or against `--base`.
`vendor/bin/test-redundancy analyse --help` lists every option.

## What it does

- **Finds candidates**: test cases whose covered lines other test cases already cover, every dataset row separately.
- **Compares contracts, not only lines**: `differsBy` lists the literals, calls and keys a candidate has and its
  covering test lacks.
- **Says what to do**: every candidate has an `action` and numbered `steps`; a test of an error path that no covering
  test asserts is marked `keep`.
- **Starts where it pays**: files and candidates are ordered by the time each candidate took, so the slowest redundant
  tests come first.
- **Proves nothing was lost**: `verify` fails when a source line the last analysis covered is no longer covered.
- **Compares across files**: `map` builds a per-test coverage map, and `analyse --across` compares with every mapped
  test.

## Documentation

- [Usage](docs/usage.md): commands, options, how the tool runs your tests, configuration, `verify` and `--across`.
- [Reading the report](docs/report.md): the actions, the JSON fields, error paths, input combinations, failed tests and
  skipped files.
- [Reference](docs/reference.md): the exact rules for `delete` and `merge-into-dataset`, how `differsBy` names a
  difference, and how the analysis works.
- [Development](docs/development.md): the Composer scripts and the CI matrix.

## Testing

Contributing needs PHP 8.4+ with `pcov`.

```bash
composer install
composer test
```

`composer check` runs the full quality gate: Composer normalize, PHPCS, Pint, Rector, PHPStan, the security audit and
the tests with 100% coverage.

## Changelog

See [CHANGELOG](CHANGELOG.md).

## Contributing

See [CONTRIBUTING](CONTRIBUTING.md).

## Security

See [the security policy](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
