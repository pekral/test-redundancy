<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Configuration;

it('uses the defaults without a configuration file', function (): void {
    $directory = temporaryDirectory();

    $configuration = Configuration::load($directory);

    expect($configuration->sourcePaths)->toBe(['src/', 'app/'])
        ->and($configuration->ignoredSourcePattern)->toBeNull()
        ->and($configuration->environment)->toBe([])
        ->and($configuration->processes)->toBe(4)
        ->and($configuration->absoluteMapPath())->toBe($directory . '/.test-redundancy/coverage-map.json.gz')
        ->and($configuration->runner)->toBeNull();

    removeDirectory($directory);
});

it('reads the configuration file', function (): void {
    $directory = temporaryDirectory();
    file_put_contents($directory . '/test-redundancy.json', json_encode([
        'source' => ['./app/', 42],
        'ignore' => '#^app/Providers/#',
        'env' => ['TELESCOPE_ENABLED' => false, 'DEPTH' => 2, 'NESTED' => ['ignored'], 0 => 'ignored'],
        'processes' => 6,
        'map' => 'storage/map.json.gz',
        'runner' => 'vendor/bin/phpunit',
    ]));

    $configuration = Configuration::load($directory);

    expect($configuration->sourcePaths)->toBe(['app/'])
        ->and($configuration->ignoredSourcePattern)->toBe('#^app/Providers/#')
        ->and($configuration->environment)->toBe(['TELESCOPE_ENABLED' => '', 'DEPTH' => '2'])
        ->and($configuration->processes)->toBe(6)
        ->and($configuration->withProcesses(3)->processes)->toBe(3)
        ->and($configuration->mapPath)->toBe('storage/map.json.gz')
        ->and($configuration->runner)->toBe('vendor/bin/phpunit')
        ->and($configuration->isSource('app/Models/User.php'))->toBeTrue()
        ->and($configuration->isSource('app/Providers/AppServiceProvider.php'))->toBeFalse()
        ->and($configuration->isSource('tests/FooTest.php'))->toBeFalse();

    removeDirectory($directory);
});

it('ignores an environment that is not a map', function (): void {
    $directory = temporaryDirectory();
    file_put_contents($directory . '/test-redundancy.json', '{"env": "not a map"}');

    expect(Configuration::load($directory)->environment)->toBe([]);

    removeDirectory($directory);
});

it('rejects fewer than two processes', function (): void {
    $directory = temporaryDirectory();
    file_put_contents($directory . '/test-redundancy.json', '{"processes": 1}');

    expect(fn (): Configuration => Configuration::load($directory))->toThrow(InvalidArgumentException::class, '"processes" must be at least 2')
        ->and(fn (): Configuration => Configuration::load(temporaryDirectory())->withProcesses(1))->toThrow(InvalidArgumentException::class);

    removeDirectory($directory);
});

it('rejects a configuration file that holds no JSON object', function (): void {
    $directory = temporaryDirectory();
    file_put_contents($directory . '/test-redundancy.json', '"text"');

    expect(fn (): \Pekral\TestRedundancy\Configuration => Configuration::load($directory))->toThrow(JsonException::class);

    removeDirectory($directory);
});
