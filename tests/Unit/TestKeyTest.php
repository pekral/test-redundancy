<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Support\PestName;
use Pekral\TestRedundancy\Support\ProjectPath;
use Pekral\TestRedundancy\Support\TestKey;

it('joins and splits a test key', function (): void {
    $testKey = TestKey::make('tests/FooTest.php', 'it works › empty');

    expect(TestKey::file($testKey))->toBe('tests/FooTest.php')
        ->and(TestKey::testCase($testKey))->toBe('it works › empty')
        ->and(TestKey::isDatasetRow($testKey))->toBeTrue()
        ->and(TestKey::isDatasetRow(TestKey::make('tests/FooTest.php', 'it works')))->toBeFalse()
        ->and(TestKey::testCase('tests/FooTest.php'))->toBe('')
        ->and(TestKey::withoutDatasetRow('it works › empty'))->toBe('it works')
        ->and(TestKey::withoutDatasetRow('it works'))->toBe('it works');
});

it('builds the evaluable name Pest gives a test', function (): void {
    expect(PestName::evaluable('it adds 1_000 + 2, quickly'))->toBe('__pest_evaluable_it_adds_1__000___2__quickly')
        ->and(PestName::isEvaluable('__pest_evaluable_it_works'))->toBeTrue()
        ->and(PestName::isEvaluable('testWorks'))->toBeFalse();
});

it('makes a path relative to the project root', function (string $path, string $expected): void {
    expect(ProjectPath::relative($path, '/project'))->toBe($expected);
})->with([
    'absolute' => ['/project/tests/../tests/FooTest.php', 'tests/FooTest.php'],
    'dot prefix and trailing slash' => ['./tests/Unit/', 'tests/Unit'],
    'relative' => ['tests/FooTest.php', 'tests/FooTest.php'],
]);
