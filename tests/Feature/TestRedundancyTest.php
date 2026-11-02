<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Analysis\FileReport;
use Pekral\TestRedundancy\Coverage\CoverageBaseline;
use Pekral\TestRedundancy\TestRedundancy;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * @param list<\Pekral\TestRedundancy\Analysis\FileReport> $reports
 * @return array<string, string>
 */
function candidatesOf(array $reports): array
{
    $candidates = [];

    foreach ($reports as $report) {
        foreach ($report->candidates as $candidate) {
            $candidates[$candidate->testKey] = $candidate->action()->value;
        }
    }

    return $candidates;
}

it('analyses a Pest test folder', function (): void {
    $testRedundancy = TestRedundancy::forProject(fixturePath('pest'), processes: null);

    $reports = $testRedundancy->analyse($testRedundancy->testFiles(['tests'], baseRef: null));

    expect(candidatesOf($reports))->toBe([
        'tests/CalculatorTest.php :: it adds one and two' => 'delete',
        'tests/CalculatorTest.php :: it adds zero' => 'merge-into-dataset',
        'tests/CalculatorTest.php :: it adds two numbers › small numbers' => 'review-dataset-row',
    ]);
});

it('reads every test case of a run whose test name is not valid UTF-8', function (array $testCases, string ...$paths): void {
    $testRedundancy = TestRedundancy::forProject(fixturePath('invalid-utf8'), processes: null);

    $reports = $testRedundancy->analyse($testRedundancy->testFiles(array_values($paths), baseRef: null));

    expect(array_column(array_map(fn (FileReport $report): array => [$report->testFile, $report->testCases], $reports), 1, 0))->toBe($testCases);
})->with([
    'a file' => [['tests/PhoneTest.php' => 3], 'tests/PhoneTest.php'],
    'a folder' => [['tests/DescribedPhoneTest.php' => 2, 'tests/OtherPhoneTest.php' => 2, 'tests/PhoneTest.php' => 3], 'tests'],
    'a test whose description is not valid UTF-8' => [['tests/DescribedPhoneTest.php' => 2], 'tests/DescribedPhoneTest.php'],
    'several files' => [['tests/PhoneTest.php' => 3, 'tests/OtherPhoneTest.php' => 2], 'tests/PhoneTest.php', 'tests/OtherPhoneTest.php'],
]);

it('analyses a single PHPUnit test file', function (): void {
    $testRedundancy = TestRedundancy::forProject(fixturePath('phpunit'), processes: null);

    $reports = $testRedundancy->analyse(['tests/GreeterTest.php']);

    expect(candidatesOf($reports))->toBe([
        'tests/GreeterTest.php :: testGreetsJane' => 'delete',
        'tests/GreeterTest.php :: testGreetsByName › jane' => 'review-dataset-row',
    ]);
});

it('runs from a project path with an apostrophe', function (): void {
    $project = temporaryDirectory() . '/o\'brien';
    new Filesystem()->mirror(fixturePath('phpunit'), $project);
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'source' => ['src'],
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/phpunit',
    ]));

    $reports = TestRedundancy::forProject($project, processes: null)->analyse(['tests/GreeterTest.php']);

    expect(candidatesOf($reports))->toHaveCount(2);
});

it('compares test files with each other through the coverage map', function (): void {
    $project = gitRepository();
    new Filesystem()->mirror(fixturePath('pest'), $project, options: ['override' => true]);
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'source' => ['src'],
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/pest',
        'map' => 'build/coverage-map.json.gz',
    ]));
    runIn($project, ['git', 'add', '.']);
    runIn($project, ['git', 'commit', '--quiet', '-m', 'fixture']);
    $testRedundancy = TestRedundancy::forProject($project, processes: null);
    $chunks = new ArrayObject();

    $map = $testRedundancy->buildMap(['tests'], function (array $chunk) use ($chunks): void {
        $chunks->append($chunk);
    });
    $fromMap = $testRedundancy->analyseAcross(['tests/CalculatorTest.php', 'tests/OtherCalculatorTest.php'], $testRedundancy->loadMap());
    file_put_contents($project . '/src/Calculator.php', file_get_contents($project . '/src/Calculator.php') . "\n");
    $rerun = $testRedundancy->analyseAcross(['tests/OtherCalculatorTest.php'], $map);

    expect($chunks->getArrayCopy())->toBe([['tests']])
        ->and($map->testCount())->toBe(7)
        ->and(candidatesOf($fromMap))->toBe([
            'tests/CalculatorTest.php :: it adds one and two' => 'delete',
            'tests/CalculatorTest.php :: it adds zero' => 'merge-into-dataset',
            'tests/CalculatorTest.php :: it adds two numbers › small numbers' => 'review-dataset-row',
            'tests/CalculatorTest.php :: it divides two numbers' => 'delete',
        ])
        ->and($testRedundancy->changedSourceFiles($map))->toBe(['src/Calculator.php'])
        ->and($testRedundancy->sourceChangedSince($map))->toBeTrue()
        ->and(candidatesOf($rerun))->toBe(['tests/OtherCalculatorTest.php :: divide returns a float' => 'delete']);

    removeDirectory($project);
});

it('saves a coverage map of a folder with a test whose name is not valid UTF-8', function (): void {
    $project = gitRepository();
    new Filesystem()->mirror(fixturePath('invalid-utf8'), $project, options: ['override' => true]);
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'source' => ['src'],
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/pest',
        'map' => 'build/coverage-map.json.gz',
    ]));
    runIn($project, ['git', 'add', '.']);
    runIn($project, ['git', 'commit', '--quiet', '-m', 'fixture']);
    $testRedundancy = TestRedundancy::forProject($project, processes: null);
    $chunks = new ArrayObject();

    $testRedundancy->buildMap(['tests'], function (array $chunk) use ($chunks): void {
        $chunks->append($chunk);
    });

    $mappedTests = array_keys($testRedundancy->loadMap()->testsOfFile('tests/PhoneTest.php'));
    $describedTests = array_keys($testRedundancy->loadMap()->testsOfFile('tests/DescribedPhoneTest.php'));
    sort($describedTests);

    expect($chunks->getArrayCopy())->toBe([['tests']])
        ->and($mappedTests)->toContain('tests/PhoneTest.php :: it normalizes a phone number › (\'733?382412\', \'733382412\')')
        ->and($describedTests)->toBe([
            'tests/DescribedPhoneTest.php :: it keeps an empty phone number',
            'tests/DescribedPhoneTest.php :: it normalizes 733?382412',
        ]);

    removeDirectory($project);
});

function projectWithoutTestCases(string $runner): string
{
    $project = gitRepository();
    new Filesystem()->mirror(fixturePath('no-test-case'), $project, options: ['override' => true]);
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'map' => 'build/coverage-map.json.gz',
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/' . $runner,
        'source' => ['src'],
    ]));
    runIn($project, ['git', 'add', '.']);
    runIn($project, ['git', 'commit', '--quiet', '-m', 'fixture']);

    return $project;
}

/**
 * @return array<string, int>
 */
function testFilesWithoutTestCase(): array
{
    return [
        'tests/Support/ContractTest.php' => 0,
        'tests/Support/HelpersTest.php' => 0,
        'tests/Support/PlainValueTest.php' => 0,
        'tests/Support/PureEnumTest.php' => 0,
        'tests/Support/RecordingListTest.php' => 0,
        'tests/Support/SharedSetUpTest.php' => 0,
    ];
}

it('maps a folder whose test files declare no test case', function (string $runner, int $testCount, string ...$paths): void {
    $project = projectWithoutTestCases($runner);
    $chunks = new ArrayObject();

    $map = TestRedundancy::forProject($project, processes: null)->buildMap(array_values($paths), function (array $chunk) use ($chunks): void {
        $chunks->append($chunk);
    });

    expect($chunks->getArrayCopy())->toBe([array_values($paths)])
        ->and($map->testCount())->toBe($testCount);

    removeDirectory($project);
})->with([
    'a file without a test case beside a Pest test file' => ['pest', 2, 'tests/Support/PureEnumTest.php', 'tests/PestGreeterTest.php'],
    'a file without a test case beside a PHPUnit test file' => ['phpunit', 2, 'tests/Support/PureEnumTest.php', 'tests/GreeterTest.php'],
    'a helper class with a non-test parent beside a class that inherits a test method for Pest' => [
        'pest',
        1,
        'tests/Support/RecordingListTest.php',
        'tests/InheritedNameTest.php',
    ],
    'a helper class with a non-test parent beside a class that inherits a test method for PHPUnit' => [
        'phpunit',
        1,
        'tests/Support/RecordingListTest.php',
        'tests/InheritedNameTest.php',
    ],
    'only files without a test case for Pest' => ['pest', 0, 'tests/Support'],
    'only files without a test case for PHPUnit' => ['phpunit', 0, 'tests/Support'],
]);

it('leaves a helper class with a non-test parent out of the run when the runner autoloader has no PHPUnit', function (): void {
    $project = projectWithoutTestCases('phpunit');
    mkdir($project . '/tools/vendor/bin', recursive: true);
    touch($project . '/tools/vendor/bin/phpunit');
    file_put_contents($project . '/tools/vendor/autoload.php', '<?php');
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'map' => 'build/coverage-map.json.gz',
        'runner' => $project . '/tools/vendor/bin/phpunit',
        'source' => ['src'],
    ]));
    $chunks = new ArrayObject();

    $map = TestRedundancy::forProject($project, processes: null)->buildMap(['tests/Support/RecordingListTest.php'], function (array $chunk) use ($chunks): void {
        $chunks->append($chunk);
    });

    expect($chunks->getArrayCopy())->toBe([['tests/Support/RecordingListTest.php']])
        ->and($map->testCount())->toBe(0);

    removeDirectory($project);
});

it('analyses test files that declare no test case', function (string $runner, array $testCases, string ...$paths): void {
    $project = projectWithoutTestCases($runner);
    $testRedundancy = TestRedundancy::forProject($project, processes: 2);

    $reports = $testRedundancy->analyse($testRedundancy->testFiles(array_values($paths), baseRef: null));

    expect(array_column(array_map(fn (FileReport $report): array => [$report->testFile, $report->testCases], $reports), 1, 0))->toBe($testCases)
        ->and(array_filter($reports, static fn (FileReport $report): bool => $report->failedTests !== []))->toBe([]);

    removeDirectory($project);
})->with([
    'a file without a test case beside a Pest test file' => [
        'pest',
        ['tests/Support/PureEnumTest.php' => 0, 'tests/PestGreeterTest.php' => 2],
        'tests/Support/PureEnumTest.php',
        'tests/PestGreeterTest.php',
    ],
    'a file without a test case beside a PHPUnit test file' => [
        'phpunit',
        ['tests/Support/PureEnumTest.php' => 0, 'tests/GreeterTest.php' => 2],
        'tests/Support/PureEnumTest.php',
        'tests/GreeterTest.php',
    ],
    'only files without a test case for Pest' => ['pest', testFilesWithoutTestCase(), 'tests/Support'],
    'only files without a test case for PHPUnit' => ['phpunit', testFilesWithoutTestCase(), 'tests/Support'],
]);

it('maps a folder without test files as an empty map', function (): void {
    $project = gitRepository();
    new Filesystem()->mirror(fixturePath('pest'), $project, options: ['override' => true]);
    mkdir($project . '/tests/empty');
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'source' => ['src'],
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/pest',
        'map' => 'build/coverage-map.json.gz',
    ]));

    $chunks = new ArrayObject();

    $map = TestRedundancy::forProject($project, processes: null)->buildMap(['tests/empty'], function (array $chunk) use ($chunks): void {
        $chunks->append($chunk);
    });

    expect($chunks->getArrayCopy())->toBe([['tests/empty']])
        ->and($map->testCount())->toBe(0);

    removeDirectory($project);
});

it('takes the changed test files from git', function (): void {
    $project = gitRepository();
    mkdir($project . '/tests');
    file_put_contents($project . '/tests/NewTest.php', '<?php');
    file_put_contents($project . '/tests/helper.php', '<?php');
    mkdir($project . '/vendor/bin', recursive: true);
    touch($project . '/vendor/bin/phpunit');

    expect(TestRedundancy::forProject($project, processes: null)->testFiles([], 'master'))->toBe(['tests/NewTest.php'])
        ->and(TestRedundancy::forProject($project, processes: null)->testFiles([], baseRef: null))->toBe(['tests/NewTest.php']);

    removeDirectory($project);
});

it('ignores lines outside the configured source paths', function (): void {
    $project = temporaryDirectory();
    new Filesystem()->mirror(fixturePath('pest'), $project);
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'source' => ['lib'],
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/pest',
    ]));

    $reports = TestRedundancy::forProject($project, processes: null)->analyse(['tests/CalculatorTest.php']);

    expect($reports[0]->testCases)->toBe(5)
        ->and($reports[0]->coveredLines)->toBe(0)
        ->and($reports[0]->candidates)->toBe([])
        ->and($reports[0]->testsWithoutSourceLines)->toBe([
            'it adds two numbers',
            'it adds one and two',
            'it divides two numbers',
            'it rejects a division by zero',
            'it adds zero',
        ]);

    removeDirectory($project);
});

it('runs several test files and a folder as the same concurrent groups', function (): void {
    $project = temporaryDirectory();
    new Filesystem()->mirror(fixturePath('pest'), $project);
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'source' => ['src'],
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/pest',
    ]));
    $testFiles = ['tests/CalculatorTest.php', 'tests/OtherCalculatorTest.php'];
    file_put_contents($project . '/tests/OtherCalculatorTest.php', <<<'PHP'


        test('divide returns another float', function (): void {
            expect((new Calculator())->divide(9, 3))->toBe(3.0);
        });
        PHP, FILE_APPEND);

    $testRedundancy = TestRedundancy::forProject($project, processes: 2);

    $concurrent = $testRedundancy->analyse($testFiles);
    $folder = $testRedundancy->analyse($testRedundancy->testFiles(['tests'], baseRef: null));

    expect(candidatesOf($concurrent))->toBe(candidatesOf($folder))
        ->and(array_map(fn (FileReport $report): int => $report->testCases, $concurrent))->toBe([6, 2]);

    removeDirectory($project);
});

/**
 * @return array<string, string>
 */
function takeWorkerTokens(string $project): array
{
    $tokens = [];

    foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
        $tokens[$name] = (string) file_get_contents($project . '/tokens/' . $name);
        unlink($project . '/tokens/' . $name);
    }

    return $tokens;
}

it('starts every concurrent group with the Laravel testing flag unless the project sets it', function (array $environment, string $expected): void {
    $project = temporaryDirectory();
    new Filesystem()->mirror(fixturePath('pest'), $project);
    new Filesystem()->remove($project . '/tests');
    mkdir($project . '/tests');
    mkdir($project . '/tokens');
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'source' => ['src'],
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/pest',
        'env' => $environment,
    ]));
    file_put_contents($project . '/tests/WorkerTest.php', <<<'PHP'
        <?php

        declare(strict_types = 1);

        test('reads the worker environment', function (): void {
            file_put_contents(__DIR__ . '/../tokens/worker', getenv('LARAVEL_PARALLEL_TESTING') . ':' . getenv('TEST_TOKEN'));

            expect(true)->toBeTrue();
        });

        test('runs a second test in the same file', function (): void {
            expect(true)->toBeTrue();
        });
        PHP);

    TestRedundancy::forProject($project, processes: 2)->analyse(['tests/WorkerTest.php']);

    expect(file_get_contents($project . '/tokens/worker'))->toBe($expected);

    removeDirectory($project);
})->with([
    'the default' => [[], '1:1'],
    'the project overrides it' => [['LARAVEL_PARALLEL_TESTING' => '0'], '0:1'],
]);

it('balances the concurrent groups by the durations of the last run without changing the result', function (): void {
    $project = temporaryDirectory();
    new Filesystem()->mirror(fixturePath('pest'), $project);
    new Filesystem()->remove($project . '/tests');
    mkdir($project . '/tests');
    mkdir($project . '/tokens');
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'source' => ['src'],
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/pest',
    ]));
    $testFiles = [];

    foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
        $testFiles[] = 'tests/' . $name . 'Test.php';
        file_put_contents($project . '/tests/' . $name . 'Test.php', <<<PHP
            <?php

            declare(strict_types = 1);

            require_once __DIR__ . '/../src/Calculator.php';

            use Fixture\Pest\Calculator;

            test('adds in {$name}', function (): void {
                file_put_contents(__DIR__ . '/../tokens/{$name}', (string) getenv('TEST_TOKEN'));

                expect((new Calculator())->add(1, 2))->toBe(3);
            });

            test('adds again in {$name}', function (): void {
                expect((new Calculator())->add(1, 2))->toBe(3);
            });
            PHP);
    }

    $roundRobin = TestRedundancy::forProject($project, processes: 2)->analyse($testFiles);
    $roundRobinTokens = takeWorkerTokens($project);
    $roundRobinBaseline = (string) file_get_contents($project . '/.test-redundancy/baseline.json');
    $recorded = json_decode((string) file_get_contents($project . '/.test-redundancy/durations.json'), associative: true);
    file_put_contents($project . '/.test-redundancy/durations.json', (string) json_encode([
        $testFiles[0] => 10.0,
        $testFiles[1] => 5.0,
        $testFiles[2] => 5.0,
    ]));

    $balanced = TestRedundancy::forProject($project, processes: 2)->analyse($testFiles);
    $balancedTokens = takeWorkerTokens($project);

    expect(array_keys(is_array($recorded) ? $recorded : []))->toEqualCanonicalizing($testFiles)
        ->and($roundRobinTokens)->toBe(['Alpha' => '1', 'Beta' => '2', 'Gamma' => '1'])
        ->and($balancedTokens)->toBe(['Alpha' => '1', 'Beta' => '2', 'Gamma' => '2'])
        ->and($balanced)->toEqual($roundRobin)
        ->and((string) file_get_contents($project . '/.test-redundancy/baseline.json'))->toBe($roundRobinBaseline);

    removeDirectory($project);
});

/**
 * @param array<string, string> $source
 */
function phpunitProjectWith(array $source): string
{
    $project = temporaryDirectory();
    new Filesystem()->mirror(fixturePath('phpunit'), $project);
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/phpunit',
        'source' => ['src'],
    ]));
    $test = $project . '/tests/GreeterTest.php';
    file_put_contents($test, strtr((string) file_get_contents($test), $source));

    return $project;
}

it('leaves a failing test case out of the analysis and lists it with the failure message', function (): void {
    $project = phpunitProjectWith(['assertSame(\'Hello, Jane!\', (new Greeter())' => 'assertSame(\'Hello, nobody!\', (new Greeter())']);

    $reports = TestRedundancy::forProject($project, processes: null)->analyse(['tests/GreeterTest.php']);

    expect(candidatesOf($reports))->toBe(['tests/GreeterTest.php :: testGreetsByName › jane' => 'review-dataset-row'])
        ->and($reports[0]->testCases)->toBe(3)
        ->and(array_keys($reports[0]->failedTests))->toBe(['testGreetsJane'])
        ->and($reports[0]->failedTests['testGreetsJane'])->toContain('Failed asserting that two strings are identical')
        ->and($reports[0]->hasFindings())->toBeTrue()
        ->and((string) file_get_contents($project . '/.test-redundancy/baseline.json'))->not->toContain('testGreetsJane')
        ->and(array_keys(decodedJson((string) file_get_contents($project . '/.test-redundancy/durations.json'))))
        ->toBe(['tests/GreeterTest.php']);
});

it('leaves a failing row of a dataset with integer keys out of the analysis', function (): void {
    $project = phpunitProjectWith([
        'yield \'jane\' => ' => 'yield ',
        'yield \'john\' => [\'John\', \'Hello, John!\']' => 'yield [\'John\', \'Hello, nobody!\']',
    ]);

    $reports = TestRedundancy::forProject($project, processes: null)->analyse(['tests/GreeterTest.php']);

    expect(array_keys($reports[0]->failedTests))->toBe(['testGreetsByName › 1'])
        ->and($reports[0]->testCases)->toBe(3)
        ->and(array_keys(candidatesOf($reports)))->toBe(['tests/GreeterTest.php :: testGreetsJane'])
        ->and($reports[0]->candidates[0]->coveredBy)->toBe(['testGreetsByName › 0']);
});

it('keeps the coverage of every worker group and records every duration when a test case fails in one group', function (): void {
    $project = temporaryDirectory();
    new Filesystem()->mirror(fixturePath('pest'), $project);
    new Filesystem()->remove($project . '/tests');
    mkdir($project . '/tests');
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'source' => ['src'],
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/pest',
    ]));
    $testFiles = [];

    foreach (['Alpha' => 3, 'Beta' => 4] as $name => $sum) {
        $testFiles[] = 'tests/' . $name . 'Test.php';
        file_put_contents($project . '/tests/' . $name . 'Test.php', <<<PHP
            <?php

            declare(strict_types = 1);

            require_once __DIR__ . '/../src/Calculator.php';

            use Fixture\Pest\Calculator;

            test('adds in {$name}', function (): void {
                expect((new Calculator())->add(1, 2))->toBe({$sum});
            });

            test('adds again in {$name}', function (): void {
                expect((new Calculator())->add(1, 2))->toBe(3);
            });
            PHP);
    }

    $reports = TestRedundancy::forProject($project, processes: 2)->analyse($testFiles);

    expect(array_map(static fn (FileReport $report): int => $report->testCases, $reports))->toBe([2, 1])
        ->and(array_map(static fn (FileReport $report): array => array_keys($report->failedTests), $reports))
        ->toBe([[], ['adds in Beta']])
        ->and(array_keys(decodedJson((string) file_get_contents($project . '/.test-redundancy/durations.json'))))
        ->toEqualCanonicalizing($testFiles);
});

it('aborts the run when a worker ends without a JUnit log', function (): void {
    $project = temporaryDirectory();
    new Filesystem()->mirror(fixturePath('pest'), $project);
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'source' => ['src'],
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/pest',
    ]));
    file_put_contents($project . '/tests/OtherCalculatorTest.php', <<<'PHP'
        <?php

        declare(strict_types = 1);

        test('ends the process', function (): void {
            exit(3);
        });

        test('never runs', function (): void {
            expect(true)->toBeTrue();
        });
        PHP);

    expect(
        fn (): array => TestRedundancy::forProject($project, processes: null)->analyse(['tests/OtherCalculatorTest.php']),
    )
        ->toThrow(ProcessFailedException::class);
});

it('still fails the coverage map when a test case fails', function (): void {
    $project = gitRepository();
    new Filesystem()->mirror(fixturePath('pest'), $project, options: ['override' => true]);
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'source' => ['src'],
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/pest',
        'map' => 'build/coverage-map.json.gz',
    ]));
    file_put_contents($project . '/tests/OtherCalculatorTest.php', <<<'PHP'
        <?php

        declare(strict_types = 1);

        test('fails', function (): void {
            expect(1)->toBe(2);
        });
        PHP);

    expect(fn (): mixed => TestRedundancy::forProject($project, processes: null)->buildMap(['tests'], static fn (): null => null))
        ->toThrow(ProcessFailedException::class);
});

it('records the lines of every existing source directory when pcov is narrowed to them', function (): void {
    $project = temporaryDirectory();
    new Filesystem()->mirror(fixturePath('pest'), $project);
    new Filesystem()->remove($project . '/tests');
    mkdir($project . '/tests');
    mkdir($project . '/app');
    mkdir($project . '/config');
    mkdir($project . '/database');
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'source' => ['src', 'app', 'missing'],
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/pest',
    ]));
    file_put_contents($project . '/phpunit.xml', str_replace(
        '<directory>src</directory>',
        '<directory>src</directory><directory>app</directory><directory>config</directory><directory>database</directory>',
        (string) file_get_contents($project . '/phpunit.xml'),
    ));
    file_put_contents($project . '/app/Pricing.php', <<<'PHP'
        <?php

        declare(strict_types = 1);

        function priced(int $amount): int
        {
            return $amount * 2;
        }
        PHP);
    file_put_contents($project . '/config/settings.php', "<?php\n\nfunction setting(): int\n{\n    return 7;\n}\n");
    file_put_contents($project . '/database/Seeder.php', "<?php\n\nfunction seeded(): int\n{\n    return 5;\n}\n");
    file_put_contents($project . '/tests/MixedTest.php', <<<'PHP'
        <?php

        declare(strict_types = 1);

        require_once __DIR__ . '/../src/Calculator.php';
        require_once __DIR__ . '/../app/Pricing.php';
        require_once __DIR__ . '/../config/settings.php';
        require_once __DIR__ . '/../database/Seeder.php';

        use Fixture\Pest\Calculator;

        test('adds and prices', function (): void {
            expect((new Calculator())->add(setting(), seeded()))->toBe(12)
                ->and(priced(2))->toBe(4);
        });

        test('adds and prices once more', function (): void {
            expect(priced(2))->toBe(4);
        });
        PHP);
    file_put_contents($project . '/tests/OtherMixedTest.php', <<<'PHP'
        <?php

        declare(strict_types = 1);

        require_once __DIR__ . '/../app/Pricing.php';
        require_once __DIR__ . '/../database/Seeder.php';

        test('prices the seed', function (): void {
            expect(priced(seeded()))->toBe(10);
        });

        test('prices the seed once more', function (): void {
            expect(priced(seeded()))->toBe(10);
        });
        PHP);

    TestRedundancy::forProject($project, processes: 2)->analyse(['tests/MixedTest.php', 'tests/OtherMixedTest.php']);

    $baseline = CoverageBaseline::load($project . '/.test-redundancy/baseline.json');

    expect($baseline->files['tests/MixedTest.php']['lines'])->toEqualCanonicalizing(['app/Pricing.php:7', 'src/Calculator.php:12'])
        ->and($baseline->files['tests/OtherMixedTest.php']['lines'])->toBe(['app/Pricing.php:7']);

    removeDirectory($project);
});

/**
 * @return array<string, string>
 */
function singleTestFiles(): array
{
    return [
        'DatasetTest' => <<<'PHP'
            it('adds a dataset row', function (int $first): void {
                touch(__DIR__ . '/../markers/dataset');

                expect((new Calculator())->add($first, 1))->toBe($first + 1);
            })->with(['five' => [5]]);
            PHP,
        'PairTest' => <<<'PHP'
            it('adds', function (): void {
                expect((new Calculator())->add(1, 2))->toBe(3);
            });

            it('adds again', function (): void {
                expect((new Calculator())->add(1, 2))->toBe(3);
            });

            it('divides', function (): void {
                expect((new Calculator())->divide(6, 3))->toBe(2.0);
            });
            PHP,
        'SingleTest' => <<<'PHP'
            it('divides once', function (): void {
                touch(__DIR__ . '/../markers/single');

                expect((new Calculator())->divide(8, 2))->toBe(4.0);
            });
            PHP,
    ];
}

function singleTestProject(): string
{
    $project = gitRepository();
    new Filesystem()->mirror(fixturePath('pest'), $project, options: ['override' => true]);
    new Filesystem()->remove([$project . '/tests', $project . '/build']);
    mkdir($project . '/tests');
    mkdir($project . '/markers');
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/pest',
        'source' => ['src'],
    ]));

    foreach (singleTestFiles() as $name => $tests) {
        file_put_contents($project . '/tests/' . $name . '.php', <<<PHP
            <?php

            declare(strict_types = 1);

            require_once __DIR__ . '/../src/Calculator.php';

            use Fixture\Pest\Calculator;

            {$tests}
            PHP);
    }

    return $project;
}

it('skips a test file with a single test case from the coverage run without changing the candidates', function (): void {
    $project = singleTestProject();
    $testFiles = ['tests/DatasetTest.php', 'tests/PairTest.php', 'tests/SingleTest.php'];
    $withoutSingle = TestRedundancy::forProject($project, processes: 2)
        ->analyse(['tests/DatasetTest.php', 'tests/PairTest.php']);
    unlink($project . '/markers/dataset');

    $reports = TestRedundancy::forProject($project, processes: 2)->analyse($testFiles);

    expect(is_file($project . '/markers/single'))->toBeFalse()
        ->and(is_file($project . '/markers/dataset'))->toBeTrue()
        ->and(array_map(fn (FileReport $report): string => $report->testFile, $reports))->toBe($testFiles)
        ->and(array_map(fn (FileReport $report): bool => $report->skipped, $reports))->toBe([false, false, true])
        ->and($reports[2])->toEqual(FileReport::skipped('tests/SingleTest.php'))
        ->and($reports[2]->testCases)->toBe(1)
        ->and($reports[2]->candidates)->toBe([])
        ->and(candidatesOf($reports))->toBe(candidatesOf($withoutSingle))
        ->and(candidatesOf($reports))->toHaveCount(1)
        ->and(CoverageBaseline::load($project . '/.test-redundancy/baseline.json')->testFiles)->toBe($testFiles)
        ->and(array_keys(decodedJson((string) file_get_contents($project . '/.test-redundancy/durations.json'))))
        ->toEqualCanonicalizing(['tests/DatasetTest.php', 'tests/PairTest.php']);
});

it('keeps verify working for every file that had candidates when a single-test file is skipped', function (): void {
    $project = singleTestProject();
    $testRedundancy = TestRedundancy::forProject($project, processes: 2);
    $testRedundancy->analyse(['tests/DatasetTest.php', 'tests/PairTest.php', 'tests/SingleTest.php']);
    $pair = (string) file_get_contents($project . '/tests/PairTest.php');
    $withoutDivides = (string) preg_replace("/\n\nit\\('divides'.*?\\}\\);\$/s", '', $pair);
    $withoutAddsAgain = (string) preg_replace("/it\\('adds again'.*?\\}\\);\n\n/s", '', $pair);

    $untouched = $testRedundancy->verify();
    file_put_contents($project . '/tests/PairTest.php', $withoutDivides);
    $coveredBySkippedFile = $testRedundancy->verify();
    file_put_contents($project . '/tests/PairTest.php', $withoutAddsAgain);
    $candidateRemoved = $testRedundancy->verify();
    file_put_contents($project . '/tests/PairTest.php', $pair);
    unlink($project . '/tests/SingleTest.php');
    $skippedFileDeleted = $testRedundancy->verify();
    file_put_contents($project . '/tests/PairTest.php', $withoutDivides);
    $coverageDropped = $testRedundancy->verify();

    expect($untouched->isOk())->toBeTrue()
        ->and($untouched->baselineLines)->toBe(3)
        ->and($coveredBySkippedFile->isOk())->toBeTrue()
        ->and($coveredBySkippedFile->lostLines)->toBe([])
        ->and($candidateRemoved->isOk())->toBeTrue()
        ->and($candidateRemoved->lostLines)->toBe([])
        ->and($skippedFileDeleted->isOk())->toBeTrue()
        ->and($skippedFileDeleted->missingTestFiles)->toBe(['tests/SingleTest.php'])
        ->and($skippedFileDeleted->lostLines)->toBe([])
        ->and($coverageDropped->isOk())->toBeFalse()
        ->and($coverageDropped->lostLines)->toHaveCount(2)
        ->and($coverageDropped->missingTestFiles)->toBe(['tests/SingleTest.php']);
});

it('runs a skipped test file in verify and stores it without lines when every test file has a single test case', function (): void {
    $project = singleTestProject();
    $testRedundancy = TestRedundancy::forProject($project, processes: 2);

    $reports = $testRedundancy->analyse(['tests/SingleTest.php']);
    $markerAfterAnalyse = is_file($project . '/markers/single');
    $verification = $testRedundancy->verify();

    expect($markerAfterAnalyse)->toBeFalse()
        ->and($reports)->toEqual([FileReport::skipped('tests/SingleTest.php')])
        ->and(CoverageBaseline::load($project . '/.test-redundancy/baseline.json')->testFiles)->toBe(['tests/SingleTest.php'])
        ->and(is_file($project . '/markers/single'))->toBeTrue()
        ->and($verification->isOk())->toBeTrue()
        ->and($verification->baselineLines)->toBe(0);
});

it('runs a test file with a single test case when it is compared across files', function (): void {
    $project = singleTestProject();
    runIn($project, ['git', 'add', '.']);
    runIn($project, ['git', 'commit', '--quiet', '-m', 'fixture']);
    $testRedundancy = TestRedundancy::forProject($project, processes: 2);

    $testRedundancy->buildMap(['tests/PairTest.php', 'tests/SingleTest.php'], static function (): void {
    });
    $reports = $testRedundancy->analyseAcross(['tests/SingleTest.php'], $testRedundancy->loadMap());

    expect(is_file($project . '/markers/single'))->toBeTrue()
        ->and($reports[0]->skipped)->toBeFalse()
        ->and(candidatesOf($reports))->toBe(['tests/SingleTest.php :: it divides once' => 'review']);
});
