<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Support\TestFiles;

function testTree(): string
{
    $root = temporaryDirectory();

    $files = [
        'tests/Unit/A/OneTest.php',
        'tests/Unit/A/TwoTest.php',
        'tests/Unit/B/ThreeTest.php',
        'tests/Unit/RootTest.php',
        'tests/Unit/helper.php',
    ];

    foreach ($files as $file) {
        if (!is_dir(dirname($root . '/' . $file))) {
            mkdir(dirname($root . '/' . $file), recursive: true);
        }

        touch($root . '/' . $file);
    }

    return $root;
}

it('expands directories into test files', function (): void {
    $root = testTree();

    expect(TestFiles::expand(['./tests/Unit/A/', 'tests/Unit/RootTest.php', 'tests/Unit/A/OneTest.php'], $root))
        ->toBe(['tests/Unit/A/OneTest.php', 'tests/Unit/A/TwoTest.php', 'tests/Unit/RootTest.php'])
        ->and(TestFiles::onlyTests(['tests/Unit/RootTest.php', 'src/Foo.php']))->toBe(['tests/Unit/RootTest.php']);

    removeDirectory($root);
});

it('splits large directories into runs of limited size', function (): void {
    $root = testTree();

    expect(TestFiles::chunks(['tests/Unit', 'tests/Unit/RootTest.php'], $root, 2))
        ->toBe([['tests/Unit/A'], ['tests/Unit/B'], ['tests/Unit/RootTest.php'], ['tests/Unit/RootTest.php']])
        ->and(TestFiles::chunks(['tests/Unit/A'], $root, 2))->toBe([['tests/Unit/A']])
        ->and(TestFiles::chunks(['tests/Unit/A'], $root, 1))->toBe([['tests/Unit/A/OneTest.php', 'tests/Unit/A/TwoTest.php']]);

    removeDirectory($root);
});

it('lists the files beside the test files that the tests may depend on', function (): void {
    $root = testTree();
    mkdir($root . '/tests/Datasets');
    touch($root . '/tests/Pest.php');
    touch($root . '/tests/Datasets/numbers.php');
    touch($root . '/tests/Unit/loose.php');
    touch($root . '/OutsideTest.php');

    expect(TestFiles::supportFiles(['tests/Unit/A/OneTest.php', 'tests/Unit/loose.php', 'OutsideTest.php', 'gone/GoneTest.php'], $root))
        ->toBe(['tests/Datasets/numbers.php', 'tests/Pest.php', 'tests/Unit/helper.php']);

    removeDirectory($root);
});
