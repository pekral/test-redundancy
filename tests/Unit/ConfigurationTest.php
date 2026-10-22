<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Configuration;

it('uses the defaults without a configuration file', function (): void {
    $directory = temporaryDirectory();

    $configuration = Configuration::load($directory);

    expect($configuration->sourcePaths)->toBe(['src/', 'app/'])
        ->and($configuration->ignoredSourcePattern)->toBeNull()
        ->and($configuration->environment)->toBe([])
        ->and($configuration->parallelEnvironment)->toBe([])
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
        'env' => ['TEST_TOKEN' => 7, 'NESTED' => ['ignored'], 0 => 'ignored'],
        'parallelEnv' => 'not a map',
        'processes' => 0,
        'map' => 'storage/map.json.gz',
        'runner' => 'vendor/bin/phpunit',
    ]));

    $configuration = Configuration::load($directory);

    expect($configuration->sourcePaths)->toBe(['app/'])
        ->and($configuration->ignoredSourcePattern)->toBe('#^app/Providers/#')
        ->and($configuration->environment)->toBe(['TEST_TOKEN' => '7'])
        ->and($configuration->parallelEnvironment)->toBe([])
        ->and($configuration->processes)->toBe(1)
        ->and($configuration->mapPath)->toBe('storage/map.json.gz')
        ->and($configuration->runner)->toBe('vendor/bin/phpunit')
        ->and($configuration->isSource('app/Models/User.php'))->toBeTrue()
        ->and($configuration->isSource('app/Providers/AppServiceProvider.php'))->toBeFalse()
        ->and($configuration->isSource('tests/FooTest.php'))->toBeFalse();

    removeDirectory($directory);
});

it('rejects a configuration file that holds no JSON object', function (): void {
    $directory = temporaryDirectory();
    file_put_contents($directory . '/test-redundancy.json', '"text"');

    expect(fn (): \Pekral\TestRedundancy\Configuration => Configuration::load($directory))->toThrow(JsonException::class);

    removeDirectory($directory);
});
