<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Analysis\FileReport;
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

    $reports = $testRedundancy->analyse($testRedundancy->testFiles(['tests'], baseRef: null), ['tests']);

    expect(candidatesOf($reports))->toBe([
        'tests/CalculatorTest.php :: it adds one and two' => 'merge-into-dataset',
        'tests/CalculatorTest.php :: it adds zero' => 'merge-into-dataset',
        'tests/CalculatorTest.php :: it adds two numbers › small numbers' => 'review-dataset-row',
    ]);
});

it('analyses a single PHPUnit test file in parallel', function (): void {
    $testRedundancy = TestRedundancy::forProject(fixturePath('phpunit'), processes: null);

    $reports = $testRedundancy->analyse(['tests/GreeterTest.php'], ['tests/GreeterTest.php']);

    expect(candidatesOf($reports))->toBe([
        'tests/GreeterTest.php :: testGreetsJane' => 'merge-into-dataset',
        'tests/GreeterTest.php :: testGreetsByName › jane' => 'review-dataset-row',
    ]);
});

it('runs in parallel from a project path with an apostrophe', function (): void {
    $project = temporaryDirectory() . '/o\'brien';
    new Filesystem()->mirror(fixturePath('phpunit'), $project);
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'source' => ['src'],
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/phpunit',
    ]));

    $reports = TestRedundancy::forProject($project, processes: null)->analyse(['tests/GreeterTest.php'], ['tests']);

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
            'tests/CalculatorTest.php :: it adds one and two' => 'merge-into-dataset',
            'tests/CalculatorTest.php :: it adds zero' => 'merge-into-dataset',
            'tests/CalculatorTest.php :: it adds two numbers › small numbers' => 'review-dataset-row',
            'tests/CalculatorTest.php :: it divides two numbers' => 'delete',
        ])
        ->and($testRedundancy->changedSourceFiles($map))->toBe(['src/Calculator.php'])
        ->and($testRedundancy->sourceChangedSince($map))->toBeTrue()
        ->and(candidatesOf($rerun))->toBe(['tests/OtherCalculatorTest.php :: divide returns a float' => 'delete']);

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

    $reports = TestRedundancy::forProject($project, processes: null)->analyse(['tests/CalculatorTest.php'], ['tests/CalculatorTest.php']);

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

it('runs several test files as concurrent groups and one path in the runner\'s parallel mode', function (): void {
    $project = temporaryDirectory();
    new Filesystem()->mirror(fixturePath('pest'), $project);
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'source' => ['src'],
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/pest',
    ]));
    $testFiles = ['tests/CalculatorTest.php', 'tests/OtherCalculatorTest.php'];

    $concurrent = TestRedundancy::forProject($project, processes: 2)->analyse($testFiles, $testFiles);
    $parallel = TestRedundancy::forProject($project, processes: 2)->analyse($testFiles, ['tests']);

    expect(candidatesOf($concurrent))->toBe(candidatesOf($parallel))
        ->and(array_map(fn (FileReport $report): int => $report->testCases, $concurrent))->toBe([6, 1]);

    removeDirectory($project);
});

/**
 * @return array<string, string>
 */
function workerTokensOf(string $project): array
{
    $tokens = [];

    foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
        $tokens[$name] = (string) file_get_contents($project . '/tokens/' . $name);
        unlink($project . '/tokens/' . $name);
    }

    return $tokens;
}

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
            PHP);
    }

    $roundRobin = TestRedundancy::forProject($project, processes: 2)->analyse($testFiles, $testFiles);
    $roundRobinTokens = workerTokensOf($project);
    $roundRobinBaseline = (string) file_get_contents($project . '/.test-redundancy/baseline.json');
    $recorded = json_decode((string) file_get_contents($project . '/.test-redundancy/durations.json'), associative: true);
    file_put_contents($project . '/.test-redundancy/durations.json', (string) json_encode([
        $testFiles[0] => 10.0,
        $testFiles[1] => 5.0,
        $testFiles[2] => 5.0,
    ]));

    $balanced = TestRedundancy::forProject($project, processes: 2)->analyse($testFiles, $testFiles);
    $balancedTokens = workerTokensOf($project);

    expect(array_keys(is_array($recorded) ? $recorded : []))->toEqualCanonicalizing($testFiles)
        ->and($roundRobinTokens)->toBe(['Alpha' => '1', 'Beta' => '2', 'Gamma' => '1'])
        ->and($balancedTokens)->toBe(['Alpha' => '1', 'Beta' => '2', 'Gamma' => '2'])
        ->and($balanced)->toEqual($roundRobin)
        ->and((string) file_get_contents($project . '/.test-redundancy/baseline.json'))->toBe($roundRobinBaseline);

    removeDirectory($project);
});

it('fails when the analysed tests fail', function (): void {
    $project = temporaryDirectory();
    new Filesystem()->mirror(fixturePath('phpunit'), $project);
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'source' => ['src'],
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/phpunit',
    ]));
    file_put_contents(
        $project . '/tests/GreeterTest.php',
        str_replace('\'Hello, Jane!\'', '\'Hello, nobody!\'', (string) file_get_contents($project . '/tests/GreeterTest.php')),
    );

    expect(fn (): array => TestRedundancy::forProject($project, processes: null)->analyse(['tests/GreeterTest.php'], ['tests/GreeterTest.php']))
        ->toThrow(ProcessFailedException::class);

    removeDirectory($project);
});
