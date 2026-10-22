<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Configuration;
use Pekral\TestRedundancy\Coverage\TestRunner;

function projectWithBinaries(string ...$binaries): string
{
    $directory = temporaryDirectory();
    mkdir($directory . '/vendor/bin', recursive: true);

    foreach ($binaries as $binary) {
        touch($directory . '/vendor/bin/' . $binary);
    }

    return $directory;
}

/**
 * @param list<string> $command
 * @return list<string>
 */
function fromRunner(array $command): array
{
    $runnerIndex = (int) array_key_first(array_filter($command, static fn (string $argument): bool => str_starts_with($argument, 'vendor/bin/')));

    return array_slice($command, $runnerIndex);
}

it('runs Pest in parallel through its own option', function (): void {
    $directory = projectWithBinaries('pest', 'phpunit', 'paratest');
    $runner = TestRunner::detect(Configuration::load($directory));

    $command = $runner->command(['tests'], parallel: true, processes: 3, coverageFile: 'coverage.php', junitFile: 'junit.xml');

    expect(fromRunner($command))
        ->toBe([
            'vendor/bin/pest',
            'tests',
            '--parallel',
            '--processes=3',
            '--coverage-php=coverage.php',
            '--log-junit=junit.xml',
            '--cache-directory=' . $runner->cacheDirectory(),
        ])
        ->and($command)->toContain('pcov.directory=' . $directory)
        ->and($runner->rootDirectory())->toBe($directory)
        ->and($runner->runsInParallel(parallel: true, processes: 3))->toBeTrue();

    removeDirectory($directory);
});

it('builds the PHPUnit command', function (string $binaries, bool $parallel, int $processes, array $expected): void {
    $directory = projectWithBinaries(...explode(' ', $binaries));
    $runner = TestRunner::detect(Configuration::load($directory));

    expect(array_slice(fromRunner($runner->command(['tests'], $parallel, $processes, 'coverage.php', 'junit.xml')), 0, 3))->toBe($expected)
        ->and($runner->runsInParallel($parallel, $processes))->toBe($expected[0] === 'vendor/bin/paratest');

    removeDirectory($directory);
})->with([
    'paratest in parallel' => ['phpunit paratest', true, 3, ['vendor/bin/paratest', 'tests', '--processes=3']],
    'sequential on request' => ['phpunit paratest', false, 3, ['vendor/bin/phpunit', 'tests', '--coverage-php=coverage.php']],
    'sequential with one process' => ['phpunit paratest', true, 1, ['vendor/bin/phpunit', 'tests', '--coverage-php=coverage.php']],
    'sequential without paratest' => ['phpunit', true, 3, ['vendor/bin/phpunit', 'tests', '--coverage-php=coverage.php']],
]);

it('fails when no test runner is installed', function (): void {
    $directory = temporaryDirectory();

    expect(fn (): \Pekral\TestRedundancy\Coverage\TestRunner => TestRunner::detect(Configuration::load($directory)))->toThrow(
        RuntimeException::class,
        'No test runner found',
    );

    removeDirectory($directory);
});
