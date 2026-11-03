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

it('builds the Pest command', function (): void {
    $directory = projectWithBinaries('pest', 'phpunit');
    touch($directory . '/phpunit.xml.dist');
    $runner = TestRunner::detect(Configuration::load($directory));

    $command = $runner->command(['tests'], coverageFile: 'coverage.php', junitFile: 'junit.xml');

    expect(fromRunner($command))
        ->toBe([
            'vendor/bin/pest',
            'tests',
            '--configuration=' . $directory . '/phpunit.xml.dist',
            '--coverage-php=coverage.php',
            '--log-junit=junit.xml',
            '--cache-directory=' . $runner->cacheDirectory(),
        ])
        ->and($command)->toContain('pcov.directory=' . $directory)
        ->and($runner->rootDirectory())->toBe($directory);

    removeDirectory($directory);
});

it('builds the PHPUnit command', function (): void {
    $directory = projectWithBinaries('phpunit');
    $runner = TestRunner::detect(Configuration::load($directory));

    expect(array_slice(fromRunner($runner->command(['tests'], coverageFile: 'coverage.php', junitFile: 'junit.xml')), 0, 3))
        ->toBe(['vendor/bin/phpunit', 'tests', '--coverage-php=coverage.php']);

    removeDirectory($directory);
});

it('fails when no test runner is installed', function (): void {
    $directory = temporaryDirectory();

    expect(fn (): TestRunner => TestRunner::detect(Configuration::load($directory)))->toThrow(
        RuntimeException::class,
        'No test runner found',
    );

    removeDirectory($directory);
});
