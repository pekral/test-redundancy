<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\TestRedundancy;
use Symfony\Component\Filesystem\Filesystem;

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
    $testRedundancy = TestRedundancy::forProject(fixturePath('pest'));

    $reports = $testRedundancy->analyse($testRedundancy->testFiles(['tests'], baseRef: null), ['tests']);

    expect(candidatesOf($reports))->toBe([
        'tests/CalculatorTest.php :: it adds one and two' => 'merge-into-dataset',
        'tests/CalculatorTest.php :: it adds zero' => 'merge-into-dataset',
        'tests/CalculatorTest.php :: it adds two numbers › small numbers' => 'review-dataset-row',
    ]);
});

it('analyses a single PHPUnit test file in parallel', function (): void {
    $testRedundancy = TestRedundancy::forProject(fixturePath('phpunit'));

    $reports = $testRedundancy->analyse(['tests/GreeterTest.php'], ['tests/GreeterTest.php']);

    expect(candidatesOf($reports))->toBe([
        'tests/GreeterTest.php :: testGreetsJane' => 'merge-into-dataset',
        'tests/GreeterTest.php :: testGreetsByName › jane' => 'review-dataset-row',
    ]);
});

it('compares test files with each other through the coverage map', function (): void {
    $project = gitRepository();
    (new Filesystem())->mirror(fixturePath('pest'), $project, options: ['override' => true]);
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'source' => ['src'],
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/pest',
        'processes' => 1,
        'map' => 'build/coverage-map.json.gz',
    ]));
    runIn($project, ['git', 'add', '.']);
    runIn($project, ['git', 'commit', '--quiet', '-m', 'fixture']);
    $testRedundancy = TestRedundancy::forProject($project);
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

    expect(TestRedundancy::forProject($project)->testFiles([], 'master'))->toBe(['tests/NewTest.php'])
        ->and(TestRedundancy::forProject($project)->testFiles([], baseRef: null))->toBe(['tests/NewTest.php']);

    removeDirectory($project);
});

it('ignores lines outside the configured source paths', function (): void {
    $project = temporaryDirectory();
    (new Filesystem())->mirror(fixturePath('pest'), $project);
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'source' => ['lib'],
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/pest',
        'processes' => 1,
    ]));

    $reports = TestRedundancy::forProject($project)->analyse(['tests/CalculatorTest.php'], ['tests/CalculatorTest.php']);

    expect($reports[0]->testCases)->toBe(0)
        ->and($reports[0]->candidates)->toBe([]);

    removeDirectory($project);
});
