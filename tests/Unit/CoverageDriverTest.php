<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Configuration;
use Pekral\TestRedundancy\Coverage\CoverageDriver;
use Symfony\Component\Process\Process;

/**
 * @param list<string> $directories
 */
function projectWithDirectories(array $directories): string
{
    $project = temporaryDirectory();

    foreach ($directories as $directory) {
        mkdir($project . '/' . $directory, recursive: true);
    }

    return $project;
}

/**
 * @param list<string> $sourcePaths
 * @return list<string>
 */
function pcovArguments(string $project, array $sourcePaths): array
{
    return CoverageDriver::argumentsFor(hasPcov: true, hasXdebug: false, projectRoot: $project, sourcePaths: $sourcePaths);
}

it('enables the available coverage driver', function (bool $hasPcov, bool $hasXdebug, array $arguments): void {
    expect(CoverageDriver::argumentsFor($hasPcov, $hasXdebug, '/project', ['src/']))->toBe($arguments);
})->with([
    'pcov without the source directory on disk' => [true, true, ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=/project', '-d', 'pcov.exclude=~/vendor/~']],
    'xdebug' => [false, true, ['-d', 'xdebug.mode=coverage']],
]);

it('fails without a coverage driver', function (): void {
    CoverageDriver::argumentsFor(hasPcov: false, hasXdebug: false, projectRoot: '/project', sourcePaths: ['src/']);
})->throws(RuntimeException::class, 'pcov or the xdebug extension');

it('points pcov at the one source directory', function (): void {
    $project = projectWithDirectories(['src', 'tests']);

    expect(pcovArguments($project, ['src/']))->toBe(
        ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $project . '/src', '-d', 'pcov.exclude=~/vendor/~'],
    );
});

it('points pcov at the common parent of several source directories and excludes the rest of it', function (): void {
    $project = projectWithDirectories(['src', 'app', 'tests', 'config']);
    $exclude = str_replace('\\', '\\\\', '~/vendor/|^' . preg_quote($project, '~') . '/(?!(?:src|app)/)~');

    expect(pcovArguments($project, ['src/', 'app/']))
        ->toBe(['-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $project, '-d', 'pcov.exclude=' . $exclude]);
});

it('uses the deepest common parent of source directories', function (): void {
    $project = projectWithDirectories(['app/Models', 'app/Http', 'app/Console']);
    $exclude = str_replace('\\', '\\\\', '~/vendor/|^' . preg_quote($project . '/app', '~') . '/(?!(?:Models|Http)/)~');

    expect(pcovArguments($project, ['app/Models/', 'app/Http/']))
        ->toBe(['-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $project . '/app', '-d', 'pcov.exclude=' . $exclude]);
});

it('skips a source directory that does not exist', function (): void {
    $project = projectWithDirectories(['app', 'tests']);

    expect(pcovArguments($project, ['src/', 'app/']))
        ->toBe(['-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $project . '/app', '-d', 'pcov.exclude=~/vendor/~']);
});

it('keeps a source directory that another one contains out of the exclusion', function (): void {
    $project = projectWithDirectories(['app/Models']);

    expect(pcovArguments($project, ['app/Models/', 'app/']))
        ->toBe(['-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $project . '/app', '-d', 'pcov.exclude=~/vendor/~']);
});

it('points pcov at the project when no source directory can narrow it', function (bool $isConfigured): void {
    $project = projectWithDirectories(['tests']);

    expect(pcovArguments($project, $isConfigured ? ['src/', 'app/'] : []))
        ->toBe(['-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $project, '-d', 'pcov.exclude=~/vendor/~']);
})->with([
    'no source' => [false],
    'no source directory on disk' => [true],
]);

it('leaves xdebug unchanged by the source directories', function (): void {
    $project = projectWithDirectories(['src']);

    expect(CoverageDriver::argumentsFor(hasPcov: false, hasXdebug: true, projectRoot: $project, sourcePaths: ['src/']))->toBe(
        ['-d', 'xdebug.mode=coverage'],
    );
});

it('passes the driver of the running PHP', function (): void {
    $project = projectWithDirectories(['src']);

    expect(CoverageDriver::iniArguments($project, ['src/']))->toBe(
        ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $project . '/src', '-d', 'pcov.exclude=~/vendor/~'],
    )
        ->and(CoverageDriver::environment())->toBe([]);
});

/**
 * @param list<string> $arguments
 * @return list<string>
 */
function filesPcovCollects(string $project, array $arguments): array
{
    $files = json_decode(
        new Process([PHP_BINARY, ...$arguments, $project . '/collect.php'], $project)->mustRun()->getOutput(),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    $relative = array_map(
        static fn (mixed $file): string => substr(is_string($file) ? $file : '', strlen($project) + 1),
        is_array($files) ? $files : [],
    );
    sort($relative);

    return array_values(array_filter($relative, static fn (string $file): bool => $file !== 'collect.php'));
}

it('collects the same source files with the narrowed pcov directory as with the whole project', function (): void {
    $directories = ['src', 'app-v2.x', 'app-v2-x', 'app$v2', 'tests', 'config', 'database', 'vendor/package', 'src/vendor'];
    $project = projectWithDirectories($directories);
    $files = array_map(static fn (string $directory): string => $directory . '/unit.php', $directories);
    $files[] = 'unit.php';

    foreach ($files as $index => $file) {
        file_put_contents($project . '/' . $file, sprintf("<?php\nfunction unit%d(): int\n{\n    return %d;\n}\n", $index, $index));
    }

    file_put_contents($project . '/collect.php', sprintf(
        "<?php\n\\pcov\\start();\nforeach (%s as \$index => \$file) {\n    require __DIR__ . '/' . \$file;\n    ('unit' . \$index)();\n}\n\\pcov\\stop();\n"
        . "echo json_encode(array_keys(\\pcov\\collect(\\pcov\\all)));\n",
        var_export($files, return: true),
    ));
    file_put_contents($project . '/' . Configuration::FILE_NAME, (string) json_encode(['source' => ['src', 'app-v2.x', 'app$v2']]));
    $configuration = Configuration::load($project);
    $isSource = static fn (string $file): bool => $configuration->isSource($file);

    $before = filesPcovCollects($project, ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $project, '-d', 'pcov.exclude=~/vendor/~']);
    $after = filesPcovCollects(
        $project,
        CoverageDriver::argumentsFor(hasPcov: true, hasXdebug: false, projectRoot: $project, sourcePaths: $configuration->sourcePaths),
    );

    expect(array_values(array_filter($before, $isSource)))->toBe(['app$v2/unit.php', 'app-v2.x/unit.php', 'src/unit.php'])
        ->and($after)->toBe(['app$v2/unit.php', 'app-v2.x/unit.php', 'src/unit.php'])
        ->and($before)->toContain('tests/unit.php', 'config/unit.php', 'database/unit.php', 'app-v2-x/unit.php', 'unit.php');
});
