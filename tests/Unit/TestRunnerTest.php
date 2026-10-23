<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Configuration;
use Pekral\TestRedundancy\Coverage\CoverageDriver;
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
    touch($directory . '/phpunit.xml.dist');
    $runner = TestRunner::detect(Configuration::load($directory));

    $command = $runner->command(['tests'], parallel: true, processes: 3, coverageFile: 'coverage.php', junitFile: 'junit.xml');

    expect(fromRunner($command))
        ->toBe([
            'vendor/bin/pest',
            'tests',
            '--configuration=' . $directory . '/phpunit.xml.dist',
            '--parallel',
            '--processes=3',
            '--passthru-php=\'' . implode('\' \'', CoverageDriver::iniArguments($directory, Configuration::load($directory)->sourcePaths)) . '\'',
            '--coverage-php=coverage.php',
            '--log-junit=junit.xml',
            '--cache-directory=' . $runner->cacheDirectory(),
        ])
        ->and($command)->toContain('pcov.directory=' . $directory)
        ->and($runner->rootDirectory())->toBe($directory);

    removeDirectory($directory);
});

it('builds the PHPUnit command', function (bool $parallel, array $expected): void {
    $directory = projectWithBinaries('phpunit', 'paratest');
    $runner = TestRunner::detect(Configuration::load($directory));

    expect(array_slice(fromRunner($runner->command(['tests'], $parallel, 3, 'coverage.php', 'junit.xml')), 0, 3))->toBe($expected);

    removeDirectory($directory);
})->with([
    'a concurrent group' => [false, ['vendor/bin/phpunit', 'tests', '--coverage-php=coverage.php']],
    'one path through paratest' => [true, ['vendor/bin/paratest', 'tests', '--processes=3']],
]);

it('refuses PHPUnit without paratest', function (): void {
    $directory = projectWithBinaries('phpunit');

    $runner = TestRunner::detect(Configuration::load($directory));

    expect(fn (): array => $runner->command(['tests'], parallel: true, processes: 2, coverageFile: 'coverage.php', junitFile: 'junit.xml'))
        ->toThrow(RuntimeException::class, 'No paratest found next to vendor/bin/phpunit');

    removeDirectory($directory);
});

it('fails when no test runner is installed', function (): void {
    $directory = temporaryDirectory();

    expect(fn (): \Pekral\TestRedundancy\Coverage\TestRunner => TestRunner::detect(Configuration::load($directory)))->toThrow(
        RuntimeException::class,
        'No test runner found',
    );

    removeDirectory($directory);
});
