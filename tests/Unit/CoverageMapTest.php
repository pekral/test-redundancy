<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Coverage\CoverageBaseline;
use Pekral\TestRedundancy\Coverage\CoverageMap;

function sampleMap(): CoverageMap
{
    return CoverageMap::fromLinesByTest('abc123', [
        'tests/ATest.php :: it a' => ['src/A.php:1' => true, 'src/A.php:2' => true],
        'tests/BTest.php :: it b' => ['src/A.php:2' => true, 'src/B.php:1' => true],
        'tests/CTest.php :: it c' => [],
    ]);
}

it('returns the tests of one file', function (): void {
    expect(sampleMap()->testsOfFile('tests/BTest.php'))->toBe(['tests/BTest.php :: it b' => ['src/A.php:2' => true, 'src/B.php:1' => true]])
        ->and(sampleMap()->testCount())->toBe(3);
});

it('returns the tests of other files that cover the given lines', function (): void {
    $map = sampleMap();

    expect($map->testsCovering(['src/A.php:2' => true, 'src/X.php:9' => true], ['tests/ATest.php' => true]))
        ->toBe(['tests/BTest.php :: it b' => ['src/A.php:2' => true]])
        ->and($map->testsCovering(['src/A.php:1' => true], []))
        ->toBe(['tests/ATest.php :: it a' => ['src/A.php:1' => true]]);
});

it('saves and loads a map', function (): void {
    $directory = temporaryDirectory();
    sampleMap()->save($directory . '/nested/map.json.gz');

    $loaded = CoverageMap::load($directory . '/nested/map.json.gz');

    expect($loaded->commit)->toBe('abc123')
        ->and($loaded->testsOfFile('tests/ATest.php'))->toBe(sampleMap()->testsOfFile('tests/ATest.php'));

    removeDirectory($directory);
});

it('fails on a missing or broken map', function (): void {
    $directory = temporaryDirectory();
    file_put_contents($directory . '/broken.json.gz', gzencode('{"commit": 1}'));

    expect(fn (): \Pekral\TestRedundancy\Coverage\CoverageMap => CoverageMap::load($directory . '/missing.json.gz'))->toThrow(
        RuntimeException::class,
        'No coverage map',
    )
        ->and(fn (): \Pekral\TestRedundancy\Coverage\CoverageMap => CoverageMap::load($directory . '/broken.json.gz'))->toThrow(
            JsonException::class,
            'is not a coverage map',
        );

    removeDirectory($directory);
});

it('fails on a broken coverage baseline', function (): void {
    $directory = temporaryDirectory();
    file_put_contents($directory . '/baseline.json', '{"lines": 1}');

    expect(fn (): CoverageBaseline => CoverageBaseline::load($directory . '/baseline.json'))->toThrow(JsonException::class, 'is not a coverage baseline');

    removeDirectory($directory);
});

it('saves a baseline into a directory that does not exist yet', function (): void {
    $directory = temporaryDirectory();
    $baseline = CoverageBaseline::fromTestsByFile(
        ['tests/ATest.php' => ['tests/ATest.php :: it a' => ['src/A.php:1' => true]]],
        across: false,
        projectRoot: $directory,
    );

    $baseline->save($directory . '/nested/baseline.json');

    expect(CoverageBaseline::load($directory . '/nested/baseline.json')->lines)->toBe(['src/A.php:1']);

    removeDirectory($directory);
});

function filesystemAcceptsRawBytesInNames(): bool
{
    $root = temporaryDirectory();
    set_error_handler(static fn (): bool => true);
    $created = touch($root . "/caf\xE9.txt");
    restore_error_handler();
    removeDirectory($root);

    return $created;
}

it('hashes a support file whose name is not valid UTF-8', function (): void {
    $root = temporaryDirectory();
    mkdir($root . '/tests/fixtures', recursive: true);
    touch($root . '/tests/ATest.php');
    touch($root . "/tests/fixtures/caf\xE9.txt");

    $testsByFile = ['tests/ATest.php' => ['tests/ATest.php :: it a' => ['src/A.php:1' => true]]];
    $baseline = CoverageBaseline::fromTestsByFile($testsByFile, across: false, projectRoot: $root);
    file_put_contents($root . "/tests/fixtures/caf\xE9.txt", 'changed');
    $changed = CoverageBaseline::fromTestsByFile($testsByFile, across: false, projectRoot: $root);

    expect($baseline->supportHash)->not->toBe('')
        ->and($changed->supportHash)->not->toBe($baseline->supportHash);

    removeDirectory($root);
})->skip(fn (): bool => !filesystemAcceptsRawBytesInNames(), 'The filesystem rejects a file name that is not valid UTF-8.');
