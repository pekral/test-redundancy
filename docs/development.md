# Development

Contributing to this repository needs PHP 8.4+ with `pcov`.

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
| `composer ai-olympus:install` | Reinstall the [pekral/ai-olympus](https://github.com/pekral/ai-olympus) agent rules and skills with `vendor/bin/ai-olympus install --force --prune` |

PHPCS, PHPStan and Rector use [pekral/phpcs-rules](https://github.com/pekral/phpcs-rules),
[pekral/phpstan-rules](https://github.com/pekral/phpstan-rules) and [pekral/rector-rules](https://github.com/pekral/rector-rules).

## CI

Pull requests and pushes to `master` run `composer check` on PHP 8.4 and 8.5, then the tool on its own tests. A matrix
runs the tests on PHP 8.4 and 8.5 with the lowest and the highest dependencies, and a second matrix runs the tool on
the PHPUnit fixture with PHPUnit 10.5, 11.5, 12.5 and 13.4 without Pest and `paratest`, on PHP 8.4. A security audit runs every
Monday, and Dependabot proposes Composer and GitHub Actions updates weekly.
