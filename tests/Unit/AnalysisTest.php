<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Analysis\Action;
use Pekral\TestRedundancy\Analysis\AgentReport;
use Pekral\TestRedundancy\Analysis\Candidate;
use Pekral\TestRedundancy\Analysis\FileReport;
use Pekral\TestRedundancy\Analysis\RedundancyAnalyzer;
use Pekral\TestRedundancy\Analysis\StaleMap;
use Pekral\TestRedundancy\Coverage\CoverageMap;
use Pekral\TestRedundancy\Fingerprint\FingerprintCache;

function analyzer(): RedundancyAnalyzer
{
    return new RedundancyAnalyzer(new FingerprintCache(fixturePath('pest')));
}

/**
 * @param list<string> $coveredBy
 * @param list<string>|null $differsBy
 */
function candidate(string $testCase, array $coveredBy, ?array $differsBy): Candidate
{
    return new Candidate('tests/FooTest.php :: ' . $testCase, $coveredBy, $differsBy);
}

it('picks the action that fits a candidate', function (Candidate $candidate, Action $action): void {
    expect($candidate->action())->toBe($action);
})->with([
    'new call' => [candidate('it works', ['it also works'], ['call:toThrow']), Action::Review],
    'nothing new' => [candidate('it works', ['it also works'], []), Action::Delete],
    'one test of the same file' => [candidate('it works', ['it also works'], ['value:1', 'const:Foo::BAR']), Action::MergeIntoDataset],
    'row of another test' => [candidate('it works › empty', ['it also works'], ['value:\'\'']), Action::Review],
    'sibling row' => [candidate('it works › empty', ['it works › blank'], ['value:\'\'']), Action::ReviewDatasetRow],
    'test of another file' => [candidate('it works', ['tests/BarTest.php :: it works'], ['value:1']), Action::Review],
    'two covering tests' => [candidate('it works', ['it a', 'it b'], ['value:1']), Action::Review],
    'unparsed' => [candidate('it works', ['it also works'], differsBy: null), Action::Review],
]);

it('names every covering test, or the sibling row, in the first step', function (Candidate $candidate, string $firstStep): void {
    expect($candidate->steps()[0])->toBe($firstStep);
})->with([
    'delete' => [candidate('it works', ['it a', 'it b'], []), 'Confirm that "it a", "it b" together assert the same input and result.'],
    'sibling row' => [candidate('it works › empty', ['it a', 'it works › blank'], ['value:\'\'']), 'Compare this row with "it works › blank".'],
]);

it('serializes a candidate and a file report for an agent', function (): void {
    $candidate = new Candidate('tests/FooTest.php :: it works › empty', ['it works › blank'], ['value:\'\'']);
    $json = decodedJson((string) json_encode(new AgentReport([
        new FileReport('tests/FooTest.php', 2, 5, [$candidate], []),
        new FileReport('tests/BarTest.php', 1, 3, [], []),
        new FileReport('tests/BazTest.php', 1, 0, [], ['it reads the config']),
    ], 4, new StaleMap('abc123', ['src/Foo.php']))));

    expect(valueAt($json, 'summary'))->toEqual([
        'testFiles' => 3,
        'testCases' => 4,
        'candidates' => 1,
        'testsWithoutSourceLines' => 1,
        'byAction' => ['delete' => 0, 'merge-into-dataset' => 0, 'review-dataset-row' => 1, 'review' => 0],
        'seconds' => 4,
        'staleMap' => ['commit' => 'abc123', 'changedSourceFiles' => ['src/Foo.php']],
    ])->and($json['files'])->toEqual([
        [
            'file' => 'tests/FooTest.php',
            'testCases' => 2,
            'coveredLines' => 5,
            'testsWithoutSourceLines' => [],
            'candidates' => [
                [
                    'test' => 'it works › empty',
                    'action' => 'review-dataset-row',
                    'steps' => Action::ReviewDatasetRow->steps('"it works › blank"'),
                    'coveredBy' => ['it works › blank'],
                    'differsBy' => ['value:\'\''],
                ],
            ],
        ],
        [
            'file' => 'tests/BazTest.php',
            'testCases' => 1,
            'coveredLines' => 0,
            'testsWithoutSourceLines' => ['it reads the config'],
            'candidates' => [],
        ],
    ])->and($json['workflow'])->toHaveCount(6)
        ->and(valueAt(decodedJson((string) json_encode(candidate('it works', ['it also works'], differsBy: null))), 'steps.0'))->toContain(
            'could not be parsed',
        )
        ->and($candidate->testCase())->toBe('it works › empty')
        ->and($candidate->addsNoValueOrCall())->toBeFalse();
});

it('keeps the dataset row of two tests that cover the same lines', function (): void {
    $reports = analyzer()->analyze(['tests/CalculatorTest.php' => [
        'tests/CalculatorTest.php :: it adds two numbers › small numbers' => ['src/Calculator.php:12' => true],
        'tests/CalculatorTest.php :: it adds one and two' => ['src/Calculator.php:12' => true],
        'tests/CalculatorTest.php :: it divides two numbers' => ['src/Calculator.php:17' => true, 'src/Calculator.php:21' => true],
    ],
    ], map: null);

    expect($reports)->toHaveCount(1)
        ->and($reports[0]->testCases)->toBe(3)
        ->and($reports[0]->coveredLines)->toBe(3)
        ->and(array_map(fn (Candidate $candidate): array => [$candidate->testKey, $candidate->coveredBy, $candidate->differsBy], $reports[0]->candidates))
        ->toBe([['tests/CalculatorTest.php :: it adds one and two', ['it adds two numbers › small numbers'], []]]);
});

it('never proposes both tests of a pair that cover each other across files', function (): void {
    $reports = analyzer()->analyze([
        'tests/CalculatorTest.php' => ['tests/CalculatorTest.php :: it divides two numbers' => ['src/Calculator.php:17' => true]],
        'tests/OtherCalculatorTest.php' => ['tests/OtherCalculatorTest.php :: divide returns a float' => ['src/Calculator.php:17' => true]],
    ], CoverageMap::fromLinesByTest('abc', []));

    $candidates = array_merge(...array_map(fn (FileReport $report): array => $report->candidates, $reports));

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]->coveredBy[0])->toContain(' :: ');
});

it('compares with tests of other files from the map', function (): void {
    $map = CoverageMap::fromLinesByTest('abc', [
        'tests/OtherCalculatorTest.php :: divide returns a float' => ['src/Calculator.php:17' => true],
    ]);

    $reports = analyzer()->analyze(['tests/CalculatorTest.php' => [
        'tests/CalculatorTest.php :: it divides two numbers' => ['src/Calculator.php:17' => true],
    ],
    ], $map);

    expect($reports[0]->candidates[0]->coveredBy)->toBe(['tests/OtherCalculatorTest.php :: divide returns a float'])
        ->and($reports[0]->candidates[0]->differsBy)->toBe([]);
});

it('gives every action concrete steps that end with verify', function (Action $action): void {
    $steps = $action->steps('it covers');

    expect($steps)->toHaveCount(3)
        ->and($steps[2])->toBe(Action::VERIFY_STEP);
})->with(Action::cases());
