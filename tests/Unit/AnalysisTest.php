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
use Pekral\TestRedundancy\Fingerprint\SharedDataset;

function analyzer(): RedundancyAnalyzer
{
    return new RedundancyAnalyzer(new FingerprintCache(fixturePath('pest')));
}

/**
 * @param list<string> $coveredBy
 * @param list<string>|null $differsBy
 * @param list<string>|null $coveringDifference
 * @param list<string> $unionOnlyLines
 */
function candidate(string $testCase, array $coveredBy, ?array $differsBy, ?array $coveringDifference = [], array $unionOnlyLines = []): Candidate
{
    return new Candidate('tests/FooTest.php :: ' . $testCase, $coveredBy, $differsBy, $coveringDifference, $unionOnlyLines, assertsNothing: false);
}

/**
 * @param list<string> $coveredBy
 * @param list<string>|null $differsBy
 * @param list<string> $coveringDifference
 * @param list<string> $unionOnlyLines
 */
function sharedRow(array $coveredBy, ?array $differsBy, array $coveringDifference = [], array $unionOnlyLines = []): Candidate
{
    $dataset = new SharedDataset('collidingRows', ['it second', 'it third']);

    return new Candidate(
        'tests/FooTest.php :: it first › one',
        $coveredBy,
        $differsBy,
        $coveringDifference,
        $unionOnlyLines,
        assertsNothing: false,
        sharedDataset: $dataset,
    );
}

/**
 * @param array<string, list<string>> $linesByTest
 */
function analyzeTokenTest(string $source, array $linesByTest): FileReport
{
    $project = temporaryDirectory();
    mkdir($project . '/tests');
    file_put_contents($project . '/tests/TokenTest.php', $source);

    $coverage = [];

    foreach ($linesByTest as $name => $lines) {
        $coverage['tests/TokenTest.php :: ' . $name] = array_fill_keys($lines, value: true);
    }

    return new RedundancyAnalyzer(new FingerprintCache($project))->analyze(['tests/TokenTest.php' => $coverage], map: null)[0];
}

function analyzeTestPair(string $candidateBody, string $coveringBody): FileReport
{
    return analyzeTokenTest(
        source: "<?php\nit('a', function (): void {\n    {$candidateBody}\n});\nit('b', function (): void {\n    {$coveringBody}\n});\n",
        linesByTest: ['it a' => ['src/Store.php:5'], 'it b' => ['src/Store.php:5', 'src/Store.php:6']],
    );
}

it('picks the action that fits a candidate', function (Candidate $candidate, Action $action): void {
    expect($candidate->action())->toBe($action);
})->with([
    'call in a sibling row' => [candidate('it works › a', ['it works › b'], ['value:1'], ['new:Money', 'value:2']), Action::ReviewDatasetRow],
    'call in the covering test' => [candidate('it works', ['it also works'], ['value:1'], ['value:2', 'call:forget']), Action::Review],
    'data in both tests and a key only in the covering test' => [
        candidate('it works', ['it also works'], ['value:1'], ['value:2', 'key:const:Foo::BAR']),
        Action::Review,
    ],
    'key in a sibling row' => [candidate('it works › a', ['it works › b'], ['key:value:\'a\'']), Action::ReviewDatasetRow],
    'key in a value of a call that only the covering test sets' => [
        candidate('it works', ['it also works'], ['in:function:store#0/value:\'a\'/value:1'], ['in:function:store#0/value:\'b\'/key:value:\'c\'']),
        Action::Review,
    ],
    'key in one test of the same file' => [candidate('it works', ['it also works'], ['key:value:\'a\'', 'key:const:Foo::BAR']), Action::MergeIntoDataset],
    'key next to a key differsBy lists' => [
        candidate('it works', ['it also works'], ['in:function:store#0/key:value:\'a\''], ['in:function:store#0/key:value:\'b\'']),
        Action::Review,
    ],
    'key of a call and a value without a path in differsBy' => [
        candidate('it works', ['it also works'], ['value:1'], ['in:function:store#0/key:value:\'b\'']),
        Action::Review,
    ],
    'literal under a key in both tests' => [
        candidate('it works', ['it also works'], ['in:value:\'a/b\'/value:false'], ['in:value:\'c\'/value:\'x\'/const:Foo::BAR']),
        Action::MergeIntoDataset,
    ],
    'new call' => [candidate('it works', ['it also works'], ['call:toThrow']), Action::Review],
    'nothing new' => [candidate('it works', ['it also works'], []), Action::Delete],
    'nothing new, but lines only the union reaches' => [
        candidate('it works', ['it a', 'it b'], [], unionOnlyLines: ['src/Foo.php:12']),
        Action::Review,
    ],
    'one test of the same file' => [candidate('it works', ['it also works'], ['value:1', 'const:Foo::BAR']), Action::MergeIntoDataset],
    'row of another test' => [candidate('it works › empty', ['it also works'], ['value:\'\'']), Action::Review],
    'sibling row' => [candidate('it works › empty', ['it works › blank'], ['value:\'\'']), Action::ReviewDatasetRow],
    'sibling row and another test' => [
        candidate('it works › a', ['it works › b', 'it also works'], ['value:1'], ['value:2', 'call:toBeTrue']),
        Action::ReviewDatasetRow,
    ],
    'test of another file' => [candidate('it works', ['tests/BarTest.php :: it works'], ['value:1']), Action::Review],
    'two covering tests' => [candidate('it works', ['it a', 'it b'], ['value:1']), Action::Review],
    'unparsed' => [candidate('it works', ['it also works'], differsBy: null), Action::Review],
]);

it('reports the covering-side tokens that made the action review', function (Candidate $candidate, array $coveringDiffersBy): void {
    expect(valueAt(decodedJson((string) json_encode($candidate)), 'coveringDiffersBy'))->toBe($coveringDiffersBy);
})->with([
    'call and new in the covering test' => [
        candidate('it works', ['it also works'], ['value:1'], ['value:2', 'call:forget', 'new:Money']),
        ['call:forget', 'new:Money'],
    ],
    'call in a sibling row' => [candidate('it works › a', ['it works › b'], ['value:1'], ['new:Money']), []],
    'call in both tests' => [candidate('it works', ['it also works'], ['call:toThrow'], ['call:forget']), []],
    'call in one of two covering tests' => [candidate('it works', ['it a', 'it b'], ['value:1'], ['call:forget']), []],
    'call in the covering test of a test that adds nothing' => [candidate('it works', ['it also works'], [], ['call:forget']), ['call:forget']],
    'data in the covering test' => [candidate('it works', ['it also works'], ['value:1'], ['value:2']), []],
    'unparsed' => [candidate('it works', ['it also works'], differsBy: null, coveringDifference: null), []],
]);

it('names coveringDiffersBy only the tokens that made the action review', function (FileReport $report, Action $action): void {
    $candidate = $report->candidates[0];

    expect($candidate->action())->toBe($action)
        ->and($candidate->coveringDifference)->not->toBeEmpty()
        ->and($candidate->jsonSerialize()['coveringDiffersBy'])->toBe([])
        ->and($candidate)->not->toHaveProperty('coveringDiffersBy');
})->with([
    'cast only the covering test has (#136)' => [
        fn (): FileReport => analyzeTestPair(
            candidateBody: '$x = make(); expect(render())->toBe($x);',
            coveringBody: '$x = make(); expect(render())->toBe((string) $x);',
        ),
        Action::Delete,
    ],
    'operator only the covering test has (#131)' => [
        fn (): FileReport => analyzeTestPair(
            candidateBody: 'foo(f()); expect(g())->toBe(1);',
            coveringBody: 'foo(f() . \'a\'); expect(g())->toBe(1);',
        ),
        Action::Delete,
    ],
    'other values of a sibling dataset row' => [
        fn (): FileReport => analyzeTokenTest(
            source: "<?php\nit('a', function (\$v, \$w): void {\n    expect(f(\$v))->toBe(\$w);\n})->with(['one' => [1, 1], 'two' => [2, 2]]);\n",
            linesByTest: ['it a › one' => ['src/Store.php:5'], 'it a › two' => ['src/Store.php:5']],
        ),
        Action::ReviewDatasetRow,
    ],
    'other values of one test of the same file' => [
        fn (): FileReport => analyzeTestPair(
            candidateBody: 'store(\'a\', 1); expect(g())->toBe(1);',
            coveringBody: 'store(\'b\', 2); expect(g())->toBe(2);',
        ),
        Action::MergeIntoDataset,
    ],
]);

it('names the covering-side tokens in the review steps', function (): void {
    $steps = candidate('it works', ['it also works'], ['value:1'], ['value:2', 'call:forget', 'new:Money'])->steps();

    expect($steps)->toHaveCount(4)
        ->and($steps[1])->toContain('coveringDiffersBy (call:forget, new:Money)')
        ->and($steps[3])->toBe(Action::VERIFY_STEP);
});

it('asks whether the code under test reads the array key only the covering test sets', function (): void {
    $steps = candidate('it drains › multicast', ['it drains › parallel'], [], ['key:value:\'parallel\'', 'value:1'])->steps();

    expect($steps)->toBe([
        'The covering test "it drains › parallel" sets the array keys in coveringDiffersBy (key:value:\'parallel\'), and this test does not: '
            . 'without such a key, the input has another shape. '
            . 'Check whether the code under test reads one of these keys, for example in an isset() check, a required rule or a short-circuit condition; '
            . 'keep this test when it does, and delete it only when the code never reads them.',
        Action::VERIFY_STEP,
    ]);
});

it('asks only about the array key, not a missing argument value, when the key is nested in an argument', function (): void {
    $key = 'in:function:drain#0/value:\'options\'/key:value:\'parallel\'';

    expect(candidate('it drains › multicast', ['it drains › parallel'], [], [$key])->steps())->toBe([
        sprintf(Action::MISSING_KEY_STEP, '"it drains › parallel"', $key),
        Action::VERIFY_STEP,
    ]);
});

it('names the lines only the union of the covering tests reaches in the report and the steps', function (): void {
    $candidate = candidate('it works', ['it a', 'it b'], [], unionOnlyLines: ['src/Foo.php:12', 'src/Foo.php:15']);
    $json = decodedJson((string) json_encode($candidate));

    expect(valueAt($json, 'action'))->toBe('review')
        ->and(valueAt($json, 'unionOnlyLines'))->toBe(['src/Foo.php:12', 'src/Foo.php:15'])
        ->and(valueAt($json, 'steps'))->toBe([
            'No single covering test runs every line of this test: the lines in unionOnlyLines run only in the union of "it a", "it b". '
                . 'Check whether a covering test that runs those lines asserts what this test asserts about them; '
                . 'delete this test only when one does, otherwise keep it.',
            Action::VERIFY_STEP,
        ])
        ->and($candidate->addsNoValueOrCall())->toBeFalse()
        ->and(candidate('it works', ['it a'], [])->addsNoValueOrCall())->toBeTrue();
});

it('names every covering test, or the sibling row, in the first step', function (Candidate $candidate, string $firstStep): void {
    expect($candidate->steps()[0])->toBe($firstStep);
})->with([
    'delete' => [candidate('it works', ['it a', 'it b'], []), 'Confirm that "it a", "it b" together assert the same input and result.'],
    'sibling row' => [candidate('it works › empty', ['it a', 'it works › blank'], ['value:\'\'']), 'Compare this row with "it works › blank".'],
]);

it('serializes a candidate and a file report for an agent', function (): void {
    $candidate = new Candidate('tests/FooTest.php :: it works › empty', ['it works › blank'], ['value:\'\''], [], [], assertsNothing: false);
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
        'skippedFiles' => 0,
        'byAction' => ['delete' => 0, 'merge-into-dataset' => 0, 'review-dataset-row' => 1, 'review' => 0],
        'seconds' => 4,
        'staleMap' => ['commit' => 'abc123', 'changedSourceFiles' => ['src/Foo.php']],
        'failedTests' => 0,
    ])->and($json['files'])->toEqual([
        [
            'file' => 'tests/FooTest.php',
            'testCases' => 2,
            'coveredLines' => 5,
            'testsWithoutSourceLines' => [],
            'failedTests' => [],
            'candidates' => [
                [
                    'test' => 'it works › empty',
                    'action' => 'review-dataset-row',
                    'steps' => Action::ReviewDatasetRow->steps('"it works › blank"', coveringDiffersBy: []),
                    'coveredBy' => ['it works › blank'],
                    'coveringDiffersBy' => [],
                    'differsBy' => ['value:\'\''],
                    'unionOnlyLines' => [],
                ],
            ],
        ],
        [
            'file' => 'tests/BazTest.php',
            'testCases' => 1,
            'coveredLines' => 0,
            'testsWithoutSourceLines' => ['it reads the config'],
            'failedTests' => [],
            'candidates' => [],
        ],
    ])->and($json['workflow'])->toHaveCount(7)
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

it('asks for a review of a test whose covering test asserts the negated result', function (): void {
    $project = temporaryDirectory();
    mkdir($project . '/tests');
    file_put_contents($project . '/tests/CacheTest.php', <<<'PHP'
        <?php
        it('reads nothing when the cache is empty', function (): void {
            expect(app(Cache::class)->read('key'))->toBeEmpty();
        });
        it('reads the stored value', function (): void {
            app(Cache::class)->store('key', ['a' => 1]);

            expect(app(Cache::class)->read('key'))->not->toBeEmpty();
        });
        PHP);

    $reports = new RedundancyAnalyzer(new FingerprintCache($project))->analyze(['tests/CacheTest.php' => [
        'tests/CacheTest.php :: it reads nothing when the cache is empty' => ['src/Cache.php:20' => true],
        'tests/CacheTest.php :: it reads the stored value' => ['src/Cache.php:15' => true, 'src/Cache.php:20' => true],
    ],
    ], map: null);

    expect($reports[0]->candidates)->toHaveCount(1)
        ->and($reports[0]->candidates[0]->coveredBy)->toBe(['it reads the stored value'])
        ->and($reports[0]->candidates[0]->differsBy)->toBe(['expect:function:app/call:read/call:toBeEmpty'])
        ->and($reports[0]->candidates[0]->action())->toBe(Action::Review);
});

it('asks for a review of a test whose covering test makes the same assertion on another subject', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('creates a template', function (): void {
                expect(app(Templates::class)->create())->toBeGreaterThan(0);
            });
            it('orders the saved templates', function (): void {
                app(Templates::class)->create();
                Template::all()->each(static fn (Template $template) => expect($template->order)->toBeGreaterThan(0));
            });
            PHP,
        linesByTest: [
            'it creates a template' => ['src/Templates.php:7', 'src/Templates.php:8', 'src/Templates.php:9'],
            'it orders the saved templates' => ['src/Templates.php:7', 'src/Templates.php:8', 'src/Templates.php:9', 'src/Template.php:12'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it creates a template')
        ->and($report->candidates[0]->differsBy)->toBe([
            'expect:function:app/call:create/call:toBeGreaterThan',
            'in:expect:function:app/call:create/call:toBeGreaterThan#0/value:0',
        ])
        ->and($report->candidates[0]->action())->toBe(Action::Review);
});

it('asks for a review of a test whose covering test makes the same assertion on the result of another receiver', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('leaves the instant it was given untouched', function (): void {
                $moment = CarbonImmutable::parse('2026-08-02 23:15:40', 'UTC');

                new Interval(5)->applyTo($moment);

                expect($moment->format('Y-m-d H:i:s'))->toBe('2026-08-02 23:15:40');
            });
            it('moves the instant', function (string $at, int $hours, string $expected): void {
                $moment = CarbonImmutable::parse($at, 'UTC');

                expect(new Interval($hours)->applyTo($moment)->format('Y-m-d H:i:s'))->toBe($expected);
            })->with([
                'one day back' => ['2026-10-25 00:30:00', -24, '2026-10-24 00:30:00'],
            ]);
            PHP,
        linesByTest: [
            'it leaves the instant it was given untouched' => ['src/Interval.php:9'],
            'it moves the instant › one day back' => ['src/Interval.php:9'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it leaves the instant it was given untouched')
        ->and($report->candidates[0]->differsBy)->toContain(
            'expect:variable:moment/call:format/call:toBe',
            'in:expect:variable:moment/call:format/call:toBe#0/value:\'2026-08-02 23:15:40\'',
        )
        ->and($report->candidates[0]->coveringDifference)->toContain('expect:call:applyTo/call:format/call:toBe')
        ->and($report->candidates[0]->action())->toBe(Action::Review);
});

it('asks for a review of a test whose covering test expects the same values for swapped literal arguments of the subject', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('maps a large value', function (): void {
                $mapping = detectColumns(['percentual', '10', '500']);
                expect($mapping->get(1))->toBe(Column::VALUE)
                    ->and($mapping->get(2))->toBe(Column::MIN_SPEND);
            });
            it('maps a zero value', function (): void {
                $mapping = detectColumns(['percentual', '0', '50']);
                expect($mapping->get(1))->toBe(Column::MIN_SPEND)
                    ->and($mapping->get(2))->toBe(Column::VALUE);
            });
            PHP,
        linesByTest: [
            'it maps a large value' => ['src/Columns.php:10'],
            'it maps a zero value' => ['src/Columns.php:10', 'src/Columns.php:20'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it maps a large value')
        ->and($report->candidates[0]->differsBy)->toBe([
            'in:function:detectColumns#0/value:\'10\'',
            'in:function:detectColumns#0/value:\'500\'',
            'bound:expect:variable:mapping/call:get#0:2/call:toBe#0:Column::MIN_SPEND',
            'bound:expect:variable:mapping/call:get#0:1/call:toBe#0:Column::VALUE',
        ])
        ->and($report->candidates[0]->action())->toBe(Action::Review);
});

it(
    'asks to merge a test whose covering test pairs the same arguments of the subject with the same values under another message',
    function (string $source, string $messageArgument): void {
        $report = analyzeTokenTest(
            source: $source,
            linesByTest: [
                'testMapsALargeValue' => ['src/Columns.php:10'],
                'testMapsAZeroValue' => ['src/Columns.php:10', 'src/Columns.php:20'],
            ],
        );

        expect($report->candidates)->toHaveCount(1)
            ->and($report->candidates[0]->testCase())->toBe('testMapsALargeValue')
            ->and($report->candidates[0]->differsBy)->toBe([
                'in:function:detectColumns#0/value:\'10\'',
                'in:function:detectColumns#0/value:\'500\'',
                'in:expect:variable:mapping/call:get/' . $messageArgument . '/value:\'first\'',
                'in:expect:variable:mapping/call:get/' . $messageArgument . '/value:\'second\'',
            ])
            ->and($report->candidates[0]->action())->toBe(Action::MergeIntoDataset);
    },
)->with([
    'pest toBe' => [
        <<<'PHP'
            <?php
            test('testMapsALargeValue', function (): void {
                $mapping = detectColumns(['percentual', '10', '500']);
                expect($mapping->get(1))->toBe(Column::VALUE, 'first')
                    ->and($mapping->get(2))->toBe(Column::MIN_SPEND, 'second');
            });
            test('testMapsAZeroValue', function (): void {
                $mapping = detectColumns(['percentual', '0', '50']);
                expect($mapping->get(1))->toBe(Column::VALUE, message: 'one')
                    ->and($mapping->get(2))->toBe(Column::MIN_SPEND, message: 'two');
            });
            PHP,
        'call:toBe#1',
    ],
    'phpunit assertSame' => [
        <<<'PHP'
            <?php
            final class ColumnsTest extends TestCase
            {
                public function testMapsALargeValue(): void
                {
                    $mapping = detectColumns(['percentual', '10', '500']);
                    $this->assertSame(Column::VALUE, $mapping->get(1), 'first');
                    $this->assertSame(Column::MIN_SPEND, $mapping->get(2), 'second');
                }
                public function testMapsAZeroValue(): void
                {
                    $mapping = detectColumns(['percentual', '0', '50']);
                    $this->assertSame(Column::VALUE, $mapping->get(1), 'one');
                    $this->assertSame(Column::MIN_SPEND, $mapping->get(2), 'two');
                }
            }
            PHP,
        'call:assertSame#2',
    ],
]);

it('asks for a review of a test whose covering test expects its result for a named literal argument under another argument', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('does not skip the event for a different pipeline than the one that produced it', function (): void {
                $manager = new SkipCacheManager();
                $manager->storePipelineSkip($event, 42);
                expect($manager->consumePipelineSkip($event, pipelineId: 99))->toBeFalse()
                    ->and($manager->consumePipelineSkip($event, 42))->toBeTrue();
            });
            it('stores and consumes pipeline skip events once', function (): void {
                $manager = new SkipCacheManager();
                $manager->storePipelineSkip($event, 42);
                expect($manager->consumePipelineSkip($event, 42))->toBeTrue()
                    ->and($manager->consumePipelineSkip($event, 42))->toBeFalse();
            });
            PHP,
        linesByTest: [
            'it does not skip the event for a different pipeline than the one that produced it' => ['src/SkipCacheManager.php:10'],
            'it stores and consumes pipeline skip events once' => ['src/SkipCacheManager.php:10', 'src/SkipCacheManager.php:20'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it does not skip the event for a different pipeline than the one that produced it')
        ->and($report->candidates[0]->differsBy)->toContain(
            'in:call:consumePipelineSkip#pipelineId/value:99',
            'bound:expect:variable:manager/call:consumePipelineSkip#0:variable:event#pipelineId:99/call:toBeFalse',
        )
        ->and($report->candidates[0]->action())->toBe(Action::Review);
});

it('never lists an assertion bound to the literal arguments of its subject as arrangement for a test without an assertion', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('detects the columns', function (): void {
                detectColumns(['percentual', '10', '500']);
            });
            it('maps every column', function (): void {
                $mapping = detectColumns(['percentual', '10', '500']);
                expect($mapping->get(1))->toBe(Column::VALUE)
                    ->and($mapping->get(2))->toBe(Column::MIN_SPEND);
            });
            PHP,
        linesByTest: [
            'it detects the columns' => ['src/Columns.php:10'],
            'it maps every column' => ['src/Columns.php:10', 'src/Columns.php:20'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it detects the columns')
        ->and($report->candidates[0]->coveringTokensForReview())->not->toContain(
            'bound:expect:variable:mapping/call:get#0:1/call:toBe#0:Column::VALUE',
            'bound:expect:variable:mapping/call:get#0:2/call:toBe#0:Column::MIN_SPEND',
        );
});

it('asks for a review of a test whose covering test makes a call it does not', function (): void {
    $project = temporaryDirectory();
    mkdir($project . '/tests');
    file_put_contents($project . '/tests/TemplateTest.php', <<<'PHP'
        <?php
        it('puts and gets the template HTML content', function (): void {
            expect($this->manager->get($this->id))->toBeNull();

            $this->manager->put($this->id, '<html><body><h1>Test</h1></body></html>');

            expect($this->manager->get($this->id))->toBe('<html><body><h1>Test</h1></body></html>');
        });
        it('forgets the template HTML content', function (): void {
            $this->manager->put($this->id, '<p>Some HTML</p>');
            expect($this->manager->get($this->id))->toBe('<p>Some HTML</p>');

            $this->manager->forget($this->id);

            expect($this->manager->get($this->id))->toBeNull();
        });
        PHP);

    $reports = new RedundancyAnalyzer(new FingerprintCache($project))->analyze(['tests/TemplateTest.php' => [
        'tests/TemplateTest.php :: it puts and gets the template HTML content' => ['src/Manager.php:10' => true, 'src/Manager.php:20' => true],
        'tests/TemplateTest.php :: it forgets the template HTML content' => [
            'src/Manager.php:10' => true,
            'src/Manager.php:20' => true,
            'src/Manager.php:30' => true,
        ],
    ],
    ], map: null);

    expect($reports[0]->candidates)->toHaveCount(1)
        ->and($reports[0]->candidates[0]->coveredBy)->toBe(['it forgets the template HTML content'])
        ->and($reports[0]->candidates[0]->differsBy)->toBe([
            'in:call:put#1/value:\'<html><body><h1>Test</h1></body></html>\'',
            'in:expect:variable:this/property:manager/call:get/call:toBe#0/value:\'<html><body><h1>Test</h1></body></html>\'',
        ])
        ->and($reports[0]->candidates[0]->action())->toBe(Action::Review)
        ->and($reports[0]->candidates[0]->coveringTokensForReview())->toBe(['call:forget']);
});

it('asks for a review of a test whose values each appear in a covering test but never together', function (): void {
    $project = temporaryDirectory();
    mkdir($project . '/tests');
    file_put_contents($project . '/tests/DispatchTest.php', <<<'PHP'
        <?php
        it('dispatches to the first address', function (): void {
            dispatch('a', 1, 'z');
        });
        it('dispatches to the second address', function (): void {
            dispatch('b', 2);
        });
        it('dispatches the first address with the second value', function (): void {
            dispatch('a', 2, 'z');
        });
        PHP);

    $reports = new RedundancyAnalyzer(new FingerprintCache($project))->analyze(['tests/DispatchTest.php' => [
        'tests/DispatchTest.php :: it dispatches to the first address' => ['src/Notifier.php:10' => true, 'src/Notifier.php:30' => true],
        'tests/DispatchTest.php :: it dispatches to the second address' => ['src/Notifier.php:20' => true, 'src/Notifier.php:40' => true],
        'tests/DispatchTest.php :: it dispatches the first address with the second value' => [
            'src/Notifier.php:10' => true,
            'src/Notifier.php:20' => true,
        ],
    ],
    ], map: null);

    expect($reports[0]->candidates)->toHaveCount(1)
        ->and($reports[0]->candidates[0]->coveredBy)->toHaveCount(2)
        ->and($reports[0]->candidates[0]->differsBy)->toBe(['in:function:dispatch#1/value:2'])
        ->and($reports[0]->candidates[0]->action())->toBe(Action::Review);
});

it('asks for a review of a test whose matching covering test does not run every line of it', function (
    FileReport $report,
    string $test,
    array $unionOnlyLines,
): void {
    $candidate = $report->candidates[0];

    expect($report->candidates)->toHaveCount(1)
        ->and($candidate->testCase())->toBe($test)
        ->and($candidate->coveredBy)->toHaveCount(2)
        ->and($candidate->differsBy)->toBe([])
        ->and($candidate->unionOnlyLines)->toBe($unionOnlyLines)
        ->and($candidate->action())->toBe(Action::Review);
})->with([
    'dataset row' => [
        fn (): FileReport => analyzeTokenTest(
            source: <<<'PHP'
                <?php
                it('drops a field that arrives without a token', function (array $data): void {
                    normalize($data);
                })->with([
                    'platform without token' => [['email' => 'user@example.test', 'platform' => 'android']],
                ]);
                it('keeps the stored triple when the token is dropped', function (): void {
                    normalize(['email' => 'user@example.test', 'platform' => 'android', 'token' => 'abc']);
                });
                it('normalizes the tags of an import source', function (): void {
                    importTags('daily');
                });
                PHP,
            linesByTest: [
                'it drops a field that arrives without a token › platform without token' => [
                    'src/Normalizer.php:1',
                    'src/Normalizer.php:5',
                    'src/Normalizer.php:10',
                ],
                'it keeps the stored triple when the token is dropped' => [
                    'src/Normalizer.php:1',
                    'src/Normalizer.php:5',
                    'src/Normalizer.php:30',
                ],
                'it normalizes the tags of an import source' => [
                    'src/Normalizer.php:1',
                    'src/Normalizer.php:10',
                    'src/Normalizer.php:40',
                ],
            ],
        ),
        'it drops a field that arrives without a token › platform without token',
        ['src/Normalizer.php:10'],
    ],
    'test without a dataset' => [
        fn (): FileReport => analyzeTokenTest(
            source: <<<'PHP'
                <?php
                it('rejects a token request that carries no credentials', function (): void {
                    authenticate(['secret' => 'configured']);
                });
                it('answers invalid_grant when the redirect uri does not match', function (): void {
                    authenticate(['secret' => 'configured', 'redirect' => 'https://other.example.test']);
                });
                it('rejects every token request while no secret is configured', function (): void {
                    authenticate(['secret' => '']);
                });
                PHP,
            linesByTest: [
                'it answers invalid_grant when the redirect uri does not match' => [
                    'src/Middleware.php:31',
                    'src/Middleware.php:40',
                    'src/Middleware.php:41',
                ],
                'it rejects a token request that carries no credentials' => ['src/Middleware.php:31', 'src/Middleware.php:35'],
                'it rejects every token request while no secret is configured' => [
                    'src/Middleware.php:35',
                    'src/Middleware.php:36',
                    'src/Middleware.php:37',
                ],
            ],
        ),
        'it rejects a token request that carries no credentials',
        ['src/Middleware.php:35'],
    ],
]);

it('names the lines of the matching covering test that misses the fewest', function (): void {
    $project = temporaryDirectory();
    mkdir($project . '/tests');
    file_put_contents($project . '/tests/TokenTest.php', <<<'PHP'
        <?php
        it('rejects a request without credentials', function (): void {
            authenticate(['secret' => 'configured']);
        });
        it('rejects every request while no secret is configured', function (): void {
            authenticate(['secret' => '']);
        });
        it('rejects a request with a wrong redirect', function (): void {
            authenticate(['secret' => 'configured', 'redirect' => 'https://other.example.test']);
        });
        it('rejects a request with a wrong code', function (): void {
            authenticate(['secret' => 'configured', 'code' => 'abc']);
        });
        PHP);

    $reports = new RedundancyAnalyzer(new FingerprintCache($project))->analyze(['tests/TokenTest.php' => [
        'tests/TokenTest.php :: it rejects a request without credentials' => array_fill_keys(
            array_map(static fn (int $line): string => 'src/Middleware.php:' . $line, range(1, 7)),
            value: true,
        ),
        'tests/TokenTest.php :: it rejects every request while no secret is configured' => array_fill_keys(
            ['src/Middleware.php:1', 'src/Middleware.php:2', 'src/Middleware.php:3', 'src/Middleware.php:4', 'src/Middleware.php:90'],
            value: true,
        ),
        'tests/TokenTest.php :: it rejects a request with a wrong redirect' => array_fill_keys(
            ['src/Middleware.php:5', 'src/Middleware.php:6', 'src/Middleware.php:91'],
            value: true,
        ),
        'tests/TokenTest.php :: it rejects a request with a wrong code' => array_fill_keys(
            ['src/Middleware.php:3', 'src/Middleware.php:4', 'src/Middleware.php:7', 'src/Middleware.php:92'],
            value: true,
        ),
    ],
    ], map: null);
    $candidate = $reports[0]->candidates[0];

    expect($reports[0]->candidates)->toHaveCount(1)
        ->and($candidate->testCase())->toBe('it rejects a request without credentials')
        ->and($candidate->coveredBy)->toBe([
            'it rejects every request while no secret is configured',
            'it rejects a request with a wrong redirect',
            'it rejects a request with a wrong code',
        ])
        ->and($candidate->differsBy)->toBe([])
        ->and($candidate->unionOnlyLines)->toBe(['src/Middleware.php:1', 'src/Middleware.php:2', 'src/Middleware.php:5', 'src/Middleware.php:6'])
        ->and($candidate->action())->toBe(Action::Review);
});

it('still deletes a test that one matching covering test runs completely, whatever the other tests cover', function (): void {
    $project = temporaryDirectory();
    mkdir($project . '/tests');
    file_put_contents($project . '/tests/TokenTest.php', <<<'PHP'
        <?php
        it('rejects a request without credentials', function (): void {
            authenticate(['secret' => 'configured']);
        });
        it('refuses a request without credentials', function (): void {
            expect(authenticate(['secret' => 'configured']))->toBeFalse();
        });
        it('rejects every request while no secret is configured', function (): void {
            authenticate(['secret' => '']);
        });
        PHP);

    $reports = new RedundancyAnalyzer(new FingerprintCache($project))->analyze(['tests/TokenTest.php' => [
        'tests/TokenTest.php :: it rejects a request without credentials' => ['src/Middleware.php:1' => true, 'src/Middleware.php:2' => true],
        'tests/TokenTest.php :: it refuses a request without credentials' => [
            'src/Middleware.php:1' => true,
            'src/Middleware.php:2' => true,
            'src/Middleware.php:10' => true,
        ],
        'tests/TokenTest.php :: it rejects every request while no secret is configured' => [
            'src/Middleware.php:2' => true,
            'src/Middleware.php:20' => true,
            'src/Middleware.php:21' => true,
        ],
    ],
    ], map: null);

    expect($reports[0]->candidates)->toHaveCount(1)
        ->and($reports[0]->candidates[0]->coveredBy)->toBe(['it refuses a request without credentials'])
        ->and($reports[0]->candidates[0]->unionOnlyLines)->toBe([])
        ->and($reports[0]->candidates[0]->action())->toBe(Action::Delete);
});

it('keeps a test that adds only an empty array to its covering test', function (
    string $source,
    string $test,
    string $covering,
    array $differsBy,
    Action $action,
): void {
    $project = temporaryDirectory();
    mkdir($project . '/tests');
    file_put_contents($project . '/tests/TagsTest.php', $source);

    $reports = new RedundancyAnalyzer(new FingerprintCache($project))->analyze(['tests/TagsTest.php' => [
        'tests/TagsTest.php :: ' . $test => ['src/Tags.php:10' => true],
        'tests/TagsTest.php :: ' . $covering => ['src/Tags.php:10' => true, 'src/Tags.php:20' => true],
    ],
    ], map: null);

    expect($reports[0]->candidates)->toHaveCount(1)
        ->and($reports[0]->candidates[0]->testKey)->toBe('tests/TagsTest.php :: ' . $test)
        ->and($reports[0]->candidates[0]->differsBy)->toBe($differsBy)
        ->and($reports[0]->candidates[0]->action())->toBe($action);
})->with([
    'empty tags dataset row' => [
        <<<'PHP'
            <?php
            test('can tags be stored', function (array $newTags, bool $expected): void {
                expect($validator->canTagsBeStored($overLimitLength, $newTags))->toBe($expected);
            })->with([
                'allowed overlimit tag prefix without suffix' => [['ShopifY'], true],
                'empty tags' => [[], true],
            ]);
            PHP,
        'can tags be stored › empty tags',
        'can tags be stored › allowed overlimit tag prefix without suffix',
        ['in:call:canTagsBeStored#1/value:[]'],
        Action::ReviewDatasetRow,
    ],
    'nested empty tags in a dataset row' => [
        <<<'PHP'
            <?php
            it('registers no tags', function (array $data): void {
                register($data);
            })->with([
                'tags key absent' => [['name' => 'Test']],
                'tags is an empty array' => [['name' => 'Test', 'tags' => []]],
                'tags contains only blank values' => [['name' => 'Test', 'tags' => ['', ' ']]],
            ]);
            PHP,
        'it registers no tags › tags is an empty array',
        'it registers no tags › tags contains only blank values',
        ['in:function:register#0/value:\'tags\'/value:[]'],
        Action::ReviewDatasetRow,
    ],
    'standalone test with empty custom fields' => [
        <<<'PHP'
            <?php
            it('stores a contact with custom fields', function (): void {
                store(['name' => 'Test', 'custom_fields' => ['color' => 'red']]);
            });
            it('stores a contact without custom fields', function (): void {
                store(['name' => 'Test', 'custom_fields' => []]);
            });
            PHP,
        'it stores a contact without custom fields',
        'it stores a contact with custom fields',
        ['in:function:store#0/value:\'custom_fields\'/value:[]'],
        Action::MergeIntoDataset,
    ],
]);

it('keeps a dataset row whose literal the covering row has only under an unrelated key', function (): void {
    $project = temporaryDirectory();
    mkdir($project . '/tests');
    file_put_contents($project . '/tests/ToggleTest.php', <<<'PHP'
        <?php
        it('toggles', function (mixed $input, mixed $expected): void {
            expect(toggle($input))->toBe($expected);
        })->with([
            'plain false' => [false, false],
            'false under a key' => [['enabled' => false], ['enabled' => false]],
        ]);
        PHP);

    $reports = new RedundancyAnalyzer(new FingerprintCache($project))->analyze(['tests/ToggleTest.php' => [
        'tests/ToggleTest.php :: it toggles › plain false' => ['src/Toggle.php:10' => true],
        'tests/ToggleTest.php :: it toggles › false under a key' => ['src/Toggle.php:10' => true, 'src/Toggle.php:20' => true],
    ],
    ], map: null);

    expect($reports[0]->candidates)->toHaveCount(1)
        ->and($reports[0]->candidates[0]->testKey)->toBe('tests/ToggleTest.php :: it toggles › plain false')
        ->and($reports[0]->candidates[0]->differsBy)->toBe(['in:function:toggle#0/value:false', 'in:expect:function:toggle/call:toBe#0/value:false'])
        ->and($reports[0]->candidates[0]->action())->toBe(Action::ReviewDatasetRow);
});

it('keeps a dataset row whose empty array the covering row has only under an unrelated key', function (): void {
    $project = temporaryDirectory();
    mkdir($project . '/tests');
    file_put_contents($project . '/tests/OptionsTest.php', <<<'PHP'
        <?php
        it('stores options', function (array $options): void {
            expect(store($options))->toBeTrue();
        })->with([
            'empty a' => [['a' => []]],
            'empty b' => [['a' => 1, 'b' => []]],
        ]);
        PHP);

    $reports = new RedundancyAnalyzer(new FingerprintCache($project))->analyze(['tests/OptionsTest.php' => [
        'tests/OptionsTest.php :: it stores options › empty a' => ['src/Options.php:10' => true],
        'tests/OptionsTest.php :: it stores options › empty b' => ['src/Options.php:10' => true, 'src/Options.php:20' => true],
    ],
    ], map: null);

    expect($reports[0]->candidates)->toHaveCount(1)
        ->and($reports[0]->candidates[0]->testKey)->toBe('tests/OptionsTest.php :: it stores options › empty a')
        ->and($reports[0]->candidates[0]->differsBy)->toBe(['in:function:store#0/value:\'a\'/value:[]'])
        ->and($reports[0]->candidates[0]->action())->toBe(Action::ReviewDatasetRow);
});

it('still deletes a dataset row whose literal sits under the same key as in the covering row', function (): void {
    $project = temporaryDirectory();
    mkdir($project . '/tests');
    file_put_contents($project . '/tests/ToggleTest.php', <<<'PHP'
        <?php
        it('toggles', function (mixed $input, mixed $expected): void {
            expect(toggle($input))->toBe($expected);
        })->with([
            'first false' => [['enabled' => false], ['enabled' => false]],
            'second false' => [['enabled' => false], ['enabled' => false], 'extra'],
        ]);
        PHP);

    $reports = new RedundancyAnalyzer(new FingerprintCache($project))->analyze(['tests/ToggleTest.php' => [
        'tests/ToggleTest.php :: it toggles › first false' => ['src/Toggle.php:10' => true],
        'tests/ToggleTest.php :: it toggles › second false' => ['src/Toggle.php:10' => true, 'src/Toggle.php:20' => true],
    ],
    ], map: null);

    expect($reports[0]->candidates)->toHaveCount(1)
        ->and($reports[0]->candidates[0]->differsBy)->toBe([])
        ->and($reports[0]->candidates[0]->action())->toBe(Action::Delete);
});

it('keeps a dataset row whose closure has another body than the closure of its covering row', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('keeps the plus character', function (callable $encode): void {
                expect(normalize(urldecode($encode('user+tag@example.com'))))->toBe('user+tag@example.com');
            })->with([
                'sent raw' => [static fn (string $email): string => $email],
                'sent encoded' => [static fn (string $email): string => rawurlencode($email)],
            ]);
            PHP,
        linesByTest: [
            'it keeps the plus character › sent raw' => ['src/Strings.php:10'],
            'it keeps the plus character › sent encoded' => ['src/Strings.php:10', 'src/Strings.php:20'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testKey)->toBe('tests/TokenTest.php :: it keeps the plus character › sent raw')
        ->and($report->candidates[0]->differsBy)->toHaveCount(1)
        ->and($report->candidates[0]->differsBy[0] ?? '')->toStartWith('value:closure:')
        ->and($report->candidates[0]->action())->toBe(Action::ReviewDatasetRow);
});

it('still deletes a dataset row whose closure its covering row has with other formatting', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('keeps the plus character', function (callable $encode): void {
                expect(normalize($encode('user+tag@example.com')))->toBe('user+tag@example.com');
            })->with([
                'sent raw' => [static fn (string $email): string => $email],
                'sent raw again' => [static fn(string   $email) : string
                    => $email],
            ]);
            PHP,
        linesByTest: [
            'it keeps the plus character › sent raw' => ['src/Strings.php:10'],
            'it keeps the plus character › sent raw again' => ['src/Strings.php:10', 'src/Strings.php:20'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->differsBy)->toBe([])
        ->and($report->candidates[0]->action())->toBe(Action::Delete);
});

it('asks for a review of a dataset row that omits an array key its covering row sets', function (
    FileReport $report,
    string $test,
    array $coveringDiffersBy,
): void {
    $json = decodedJson((string) json_encode($report->candidates[0]));

    expect($report->candidates)->toHaveCount(1)
        ->and(valueAt($json, 'test'))->toBe($test)
        ->and(valueAt($json, 'differsBy'))->toBe([])
        ->and(valueAt($json, 'coveringDiffersBy'))->toBe($coveringDiffersBy)
        ->and(valueAt($json, 'action'))->toBe('review');
})->with([
    'mail action without variants' => [
        fn (): FileReport => analyzeTokenTest(
            source: <<<'PHP'
                <?php
                it('builds the exportable shape of a mail action carrying variants', function (array $action, array $expected): void {
                    expect(buildPipelineAction($action))->toEqual($expected);
                })->with([
                    'mail action without variants' => [
                        ['type' => 'mail', 'properties' => ['senderMail' => 'test@example.com']],
                        ['type' => 'mail', 'properties' => ['senderMail' => 'test@example.com']],
                    ],
                    'mail action with non-array variants' => [
                        ['type' => 'mail', 'properties' => ['senderMail' => 'test@example.com', 'variants' => 'not_an_array']],
                        ['type' => 'mail', 'properties' => ['senderMail' => 'test@example.com', 'variants' => 'not_an_array']],
                    ],
                ]);
                PHP,
            linesByTest: [
                'it builds the exportable shape of a mail action carrying variants › mail action with non-array variants' => [
                    'src/PipelineExportBuilder.php:10',
                    'src/PipelineExportBuilder.php:11',
                    'src/PipelineExportBuilder.php:12',
                ],
                'it builds the exportable shape of a mail action carrying variants › mail action without variants' => [
                    'src/PipelineExportBuilder.php:10',
                    'src/PipelineExportBuilder.php:11',
                ],
            ],
        ),
        'it builds the exportable shape of a mail action carrying variants › mail action without variants',
        [
            'in:function:buildPipelineAction#0/value:\'properties\'/key:value:\'variants\'',
            'in:function:buildPipelineAction#0/value:\'properties\'/value:\'variants\'/value:\'not_an_array\'',
        ],
    ],
    'multicast pipeline without the parallel flag' => [
        fn (): FileReport => analyzeTokenTest(
            source: <<<'PHP'
                <?php
                it('refuses to drain a pipeline through the recurring queue', function (array $attributes): void {
                    expect(canDrainThroughRecurringQueue(pipeline($attributes)))->toBeFalse();
                })->with([
                    'multicast' => [['multicast' => 1]],
                    'parallel' => [['multicast' => 1, 'parallel' => 1]],
                ]);
                PHP,
            linesByTest: [
                'it refuses to drain a pipeline through the recurring queue › multicast' => ['src/Validator.php:20'],
                'it refuses to drain a pipeline through the recurring queue › parallel' => ['src/Validator.php:20'],
            ],
        ),
        'it refuses to drain a pipeline through the recurring queue › multicast',
        [
            'in:function:canDrainThroughRecurringQueue#0/function:pipeline#0/key:value:\'parallel\'',
            'in:function:canDrainThroughRecurringQueue#0/function:pipeline#0/value:\'parallel\'/value:1',
        ],
    ],
]);

it('asks for a review of a test that passes the code under test a list without an element its covering test passes', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('forces the email column even when it is omitted from the subscriber columns', function (): void {
                $connections = repository()->getByListIdAndLastId(1, 0, 10, ['id', 'email'], ['id', 'tags']);

                expect($connections[0]->subscriber->email)->toBe('owner@example.com');
            });
            it('does not duplicate the email column when it is already requested', function (): void {
                $connections = repository()->getByListIdAndLastId(1, 0, 10, ['id', 'email'], ['id', 'email', 'tags']);

                expect($connections[0]->subscriber->email)->toBe('owner@example.com');
            });
            it('loads the subscriber relation with the requested columns', function (): void {
                $connections = repository()->getByListIdAndLastId(1, 0, 10, ['id', 'email'], ['id', 'email', 'tags']);

                expect($connections[0]->subscriber->email)->toBe('owner@example.com')
                    ->and($connections[0]->subscriber->tags)->toBe('vip');
            });
            PHP,
        linesByTest: [
            'it forces the email column even when it is omitted from the subscriber columns' => ['src/Repository.php:788', 'src/Repository.php:790'],
            'it does not duplicate the email column when it is already requested' => ['src/Repository.php:788', 'src/Repository.php:790'],
            'it loads the subscriber relation with the requested columns' => [
                'src/Repository.php:788',
                'src/Repository.php:790',
                'src/Repository.php:791',
            ],
        ],
    );
    $candidates = [];

    foreach ($report->candidates as $candidate) {
        $candidates[$candidate->testCase()] = decodedJson((string) json_encode($candidate));
    }

    expect($candidates)->toHaveCount(2)
        ->and(valueAt($candidates, 'it forces the email column even when it is omitted from the subscriber columns'))->toBe([
            'action' => 'review',
            'coveredBy' => ['it loads the subscriber relation with the requested columns'],
            'coveringDiffersBy' => ['in:call:getByListIdAndLastId#4/value:list-item', 'in:call:getByListIdAndLastId#4/value:\'email\''],
            'differsBy' => [],
            'steps' => [
                'The covering test "it loads the subscriber relation with the requested columns" passes the values in coveringDiffersBy '
                    . '(in:call:getByListIdAndLastId#4/value:list-item, in:call:getByListIdAndLastId#4/value:\'email\') '
                    . 'to a call that is not an assertion, and this test does not: '
                    . 'without such a value, the code under test gets another input. '
                    . 'Check whether the code under test behaves differently without one of these values, '
                    . 'for example when it adds a missing value itself or falls back to a default; '
                    . 'keep this test when it does, and delete it only when it does not.',
                Action::VERIFY_STEP,
            ],
            'test' => 'it forces the email column even when it is omitted from the subscriber columns',
            'unionOnlyLines' => [],
        ])
        ->and(valueAt($candidates, 'it does not duplicate the email column when it is already requested.coveringDiffersBy'))->toBe([])
        ->and(valueAt($candidates, 'it does not duplicate the email column when it is already requested.action'))->toBe('delete');
});

it('asks for a review of a test that passes a list through a local variable or a dataset row without an element its covering test passes', function (
    FileReport $report,
    string $test,
    string $covering,
): void {
    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe($test)
        ->and($report->candidates[0]->coveredBy)->toBe([$covering])
        ->and($report->candidates[0]->differsBy)->toBe([])
        ->and($report->candidates[0]->coveringTokensForReview())->toBe([
            'in:call:getByListIdAndLastId#4/value:list-item',
            'in:call:getByListIdAndLastId#4/value:\'email\'',
        ])
        ->and($report->candidates[0]->action())->toBe(Action::Review);
})->with([
    'list in a dataset row' => [
        fn (): FileReport => analyzeTokenTest(
            source: <<<'PHP'
                <?php
                it('loads the subscriber relation with the requested columns', function (array $columns): void {
                    $connections = repository()->getByListIdAndLastId(1, 0, 10, ['id', 'email'], $columns);

                    expect($connections[0]->subscriber->email)->toBe('owner@example.com');
                })->with(['without email' => [['id', 'tags']], 'with email' => [['id', 'email', 'tags']]]);
                PHP,
            linesByTest: [
                'it loads the subscriber relation with the requested columns › with email' => [
                    'src/Repository.php:788',
                    'src/Repository.php:790',
                    'src/Repository.php:791',
                ],
                'it loads the subscriber relation with the requested columns › without email' => [
                    'src/Repository.php:788',
                    'src/Repository.php:790',
                ],
            ],
        ),
        'it loads the subscriber relation with the requested columns › without email',
        'it loads the subscriber relation with the requested columns › with email',
    ],
    'list in a local variable' => [
        fn (): FileReport => analyzeTokenTest(
            source: <<<'PHP'
                <?php
                it('forces the email column even when it is omitted from the subscriber columns', function (): void {
                    $columns = ['id', 'tags'];
                    $connections = repository()->getByListIdAndLastId(1, 0, 10, ['id', 'email'], $columns);

                    expect($connections[0]->subscriber->email)->toBe('owner@example.com');
                });
                it('loads the subscriber relation with the requested columns', function (): void {
                    $columns = ['id', 'email', 'tags'];
                    $connections = repository()->getByListIdAndLastId(1, 0, 10, ['id', 'email'], $columns);

                    expect($connections[0]->subscriber->email)->toBe('owner@example.com')
                        ->and($connections[0]->subscriber->tags)->toBe('vip');
                });
                PHP,
            linesByTest: [
                'it forces the email column even when it is omitted from the subscriber columns' => ['src/Repository.php:788', 'src/Repository.php:790'],
                'it loads the subscriber relation with the requested columns' => [
                    'src/Repository.php:788',
                    'src/Repository.php:790',
                    'src/Repository.php:791',
                ],
            ],
        ),
        'it forces the email column even when it is omitted from the subscriber columns',
        'it loads the subscriber relation with the requested columns',
    ],
]);

it('keeps the rows of a dataset that swap the same literals between two arguments apart', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('lists the changed keys', function (array $previous, array $current, array $expected): void {
                expect(changedKeys($previous, $current))->toBe($expected);
            })->with([
                'a key was added' => [['listId' => 1], ['listId' => 1, 'store' => 'shop'], ['store']],
                'a key was removed' => [['listId' => 1, 'store' => 'shop'], ['listId' => 1], ['store']],
            ]);
            PHP,
        linesByTest: [
            'it lists the changed keys › a key was added' => ['src/Changes.php:5', 'src/Changes.php:6'],
            'it lists the changed keys › a key was removed' => ['src/Changes.php:5', 'src/Changes.php:6'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it lists the changed keys › a key was added')
        ->and($report->candidates[0]->coveredBy)->toBe(['it lists the changed keys › a key was removed'])
        ->and($report->candidates[0]->differsBy)->toBe([
            'in:function:changedKeys#1/key:value:\'store\'',
            'in:function:changedKeys#1/value:\'store\'/value:\'shop\'',
        ])
        ->and($report->candidates[0]->action())->not->toBe(Action::Delete);
});

it('deletes a test that passes a once-assigned local map where its covering test passes the same map inline', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('a', function (): void {
                $options = ['a' => 1];
                store($options);
            });
            it('b', function (): void {
                store(['a' => 1]);
            });
            PHP,
        linesByTest: [
            'it a' => ['src/Store.php:5'],
            'it b' => ['src/Store.php:5', 'src/Store.php:6'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it a')
        ->and($report->candidates[0]->differsBy)->toBe([])
        ->and($report->candidates[0]->action())->toBe(Action::Delete);
});

it('does not delete a dataset row whose value a sibling row passes through one more call', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('decodes stored custom fields', function (string $stored, array $expected): void {
                expect(decode($stored))->toEqual($expected);
            })->with([
                'a json string is decoded' => [json_encode(['test' => true]), ['test' => true]],
                'a double encoded json string is decoded twice' => [json_encode(json_encode(['test' => true])), ['test' => true]],
            ]);
            PHP,
        linesByTest: [
            'it decodes stored custom fields › a json string is decoded' => ['src/Decode.php:5', 'src/Decode.php:7', 'src/Decode.php:11'],
            'it decodes stored custom fields › a double encoded json string is decoded twice' => [
                'src/Decode.php:5',
                'src/Decode.php:7',
                'src/Decode.php:8',
                'src/Decode.php:11',
            ],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it decodes stored custom fields › a json string is decoded')
        ->and($report->candidates[0]->differsBy)->toBe([
            'in:function:json_encode#0/key:value:\'test\'',
            'in:function:json_encode#0/value:\'test\'/value:true',
        ])
        ->and($report->candidates[0]->action())->toBe(Action::ReviewDatasetRow);
});

it('does not delete a test whose covering test passes its literal only as part of a larger argument expression', function (
    string $coveringArgument,
    string $literal,
): void {
    $report = analyzeTokenTest(
        source: <<<PHP
            <?php
            it('passes the literal', function (): void {
                expect(foo({$literal}))->toBeTrue();
            });
            it('passes the literal in an expression', function (): void {
                \$suffix = suffix();
                expect(foo({$coveringArgument}))->toBeTrue();
            });
            PHP,
        linesByTest: [
            'it passes the literal' => ['src/Foo.php:5'],
            'it passes the literal in an expression' => ['src/Foo.php:5'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it passes the literal')
        ->and($report->candidates[0]->differsBy)->toBe(['in:function:foo#0/value:' . $literal])
        ->and($report->candidates[0]->action())->toBe(Action::Review);
})->with([
    'addition' => ['1 + $suffix', '1'],
    'concatenation' => ['\'a\' . $suffix', '\'a\''],
]);

it('does not delete a test whose covering test passes its literal inside a nested list', function (
    string $argument,
    string $coveringArgument,
    array $differsBy,
): void {
    $report = analyzeTokenTest(
        source: <<<PHP
            <?php
            it('passes the literal', function (): void {
                expect(foo({$argument}))->toBeTrue();
            });
            it('passes the literal in a nested list', function (): void {
                expect(foo({$coveringArgument}))->toBeTrue();
            });
            PHP,
        linesByTest: [
            'it passes the literal' => ['src/Foo.php:5'],
            'it passes the literal in a nested list' => ['src/Foo.php:5'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it passes the literal')
        ->and($report->candidates[0]->differsBy)->toBe($differsBy)
        ->and($report->candidates[0]->action())->toBe(Action::MergeIntoDataset);
})->with([
    'list in a list' => ['1', '[[1]]', ['in:function:foo#0/value:1']],
    'list in a list against a list' => ['[1]', '[[1]]', ['in:function:foo#0/value:1']],
    'list in a list in a call' => ['bar(1)', 'bar([[1]])', ['in:function:foo#0/function:bar#0/value:1']],
    'list in a list in a list' => ['1', '[[[1]]]', ['in:function:foo#0/value:1']],
    'list in a list under a key' => ['[\'a\' => 1]', '[\'a\' => [[1]]]', ['in:function:foo#0/value:\'a\'/value:1']],
    'list under a key' => ['[\'a\' => 1]', '[\'a\' => [1]]', ['in:function:foo#0/value:\'a\'/value:1']],
    'list under a key against a map' => [
        '[\'a\' => [1]]',
        '[\'a\' => 1]',
        ['in:function:foo#0/value:\'a\'/value:list-item', 'in:value:\'a\'/value:1'],
    ],
]);

it('asks for a review of a test whose covering test sets its array key only inside a list', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('passes the literal', function (): void {
                expect(foo(['a' => 1]))->toBeTrue();
            });
            it('passes the literal in a nested list', function (): void {
                expect(foo([['a' => 1]]))->toBeTrue();
            });
            PHP,
        linesByTest: [
            'it passes the literal' => ['src/Foo.php:5'],
            'it passes the literal in a nested list' => ['src/Foo.php:5'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it passes the literal')
        ->and($report->candidates[0]->differsBy)->toBe(['in:function:foo#0/key:value:\'a\'', 'in:function:foo#0/value:\'a\'/value:1'])
        ->and($report->candidates[0]->coveringTokensForReview())->toBe(['key:value:\'a\''])
        ->and($report->candidates[0]->action())->toBe(Action::Review);
});

it('does not delete a dataset row whose sibling row passes its value inside a nested list', function (
    string $row,
    string $nestedRow,
    array $differsBy,
): void {
    $report = analyzeTokenTest(
        source: <<<PHP
            <?php
            it('foos', function (mixed \$value): void {
                expect(foo(\$value))->toBeTrue();
            })->with([
                'flat' => [{$row}],
                'nested' => [{$nestedRow}],
            ]);
            PHP,
        linesByTest: [
            'it foos › flat' => ['src/Foo.php:5'],
            'it foos › nested' => ['src/Foo.php:5'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it foos › flat')
        ->and($report->candidates[0]->differsBy)->toBe($differsBy)
        ->and($report->candidates[0]->action())->toBe(Action::ReviewDatasetRow);
})->with([
    'list in a list in a list' => ['1', '[[[1]]]', ['in:function:foo#0/value:1']],
    'list under a key' => ['[\'a\' => 1]', '[\'a\' => [1]]', ['in:function:foo#0/value:\'a\'/value:1']],
    'map in a list' => ['[\'a\' => 1]', '[[\'a\' => 1]]', ['in:function:foo#0/key:value:\'a\'', 'in:function:foo#0/value:\'a\'/value:1']],
]);

it('does not list the literal a flat row and a sibling row with a nested list both pass', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('registers', function (array $data): void {
                register($data);
            })->with([
                'flat' => [['name' => 'T']],
                'nested' => [['name' => 'T', 'tags' => ['x']]],
            ]);
            PHP,
        linesByTest: [
            'it registers › flat' => ['src/Register.php:5'],
            'it registers › nested' => ['src/Register.php:5', 'src/Register.php:6'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it registers › flat')
        ->and($report->candidates[0]->differsBy)->toBe([])
        ->and($report->candidates[0]->coveringDifference)->toContain('in:function:register#0/key:value:\'tags\'')
        ->and($report->candidates[0]->action())->toBe(Action::Review);
});

it('still deletes a test whose covering test passes a value it lacks only to an assertion', function (
    FileReport $report,
    string $test,
    array $coveringDifference,
): void {
    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe($test)
        ->and($report->candidates[0]->coveringDifference)->toBe($coveringDifference)
        ->and($report->candidates[0]->action())->toBe(Action::Delete);
})->with([
    'pest expectation of a dataset row' => [
        fn (): FileReport => analyzeTokenTest(
            source: <<<'PHP'
                <?php
                it('adds two numbers', function (int $first, int $second, int $sum): void {
                    expect(add($first, $second))->toBeInt()->toBe($sum);
                })->with(['one and two' => [1, 2, 3]]);
                it('adds one and two', function (): void {
                    expect(add(1, 2))->toBeInt();
                });
                PHP,
            linesByTest: [
                'it adds one and two' => ['src/Calculator.php:5'],
                'it adds two numbers › one and two' => ['src/Calculator.php:5'],
            ],
        ),
        'it adds one and two',
        ['expect:function:add/call:toBe', 'in:expect:function:add/call:toBe#0/value:3'],
    ],
    'pest expectation of an array with a top-level and a nested key' => [
        fn (): FileReport => analyzeTokenTest(
            source: <<<'PHP'
                <?php
                it('builds a mail action', function (): void {
                    expect(build('mail'))->toBeArray();
                });
                it('builds the shape of a mail action', function (): void {
                    expect(build('mail'))->toBeArray()->toEqual(['type' => 'mail', 'properties' => ['senderMail' => 'a@example.com']]);
                });
                PHP,
            linesByTest: [
                'it builds a mail action' => ['src/Builder.php:5'],
                'it builds the shape of a mail action' => ['src/Builder.php:5', 'src/Builder.php:6'],
            ],
        ),
        'it builds a mail action',
        [
            'expect:function:build/call:toEqual',
            'in:expect:function:build/call:toEqual#0/key:value:\'type\'',
            'in:expect:function:build/call:toEqual#0/value:\'type\'/value:\'mail\'',
            'in:expect:function:build/call:toEqual#0/key:value:\'properties\'',
            'in:expect:function:build/call:toEqual#0/value:\'properties\'/key:value:\'senderMail\'',
            'in:expect:function:build/call:toEqual#0/value:\'properties\'/value:\'senderMail\'/value:\'a@example.com\'',
        ],
    ],
    'pest expectation of an array with a top-level and a nested key for a test without an assertion' => [
        fn (): FileReport => analyzeTokenTest(
            source: <<<'PHP'
                <?php
                it('builds a mail action without an error', function (): void {
                    build('mail');
                });
                it('builds the shape of a mail action', function (): void {
                    expect(build('mail'))->toEqual(['type' => 'mail', 'properties' => ['senderMail' => 'a@example.com']]);
                });
                PHP,
            linesByTest: [
                'it builds a mail action without an error' => ['src/Builder.php:5'],
                'it builds the shape of a mail action' => ['src/Builder.php:5', 'src/Builder.php:6'],
            ],
        ),
        'it builds a mail action without an error',
        [
            'expect:function:build/call:toEqual',
            'function:expect',
            'in:expect:function:build/call:toEqual#0/key:value:\'type\'',
            'in:expect:function:build/call:toEqual#0/value:\'type\'/value:\'mail\'',
            'in:expect:function:build/call:toEqual#0/key:value:\'properties\'',
            'in:expect:function:build/call:toEqual#0/value:\'properties\'/key:value:\'senderMail\'',
            'in:expect:function:build/call:toEqual#0/value:\'properties\'/value:\'senderMail\'/value:\'a@example.com\'',
        ],
    ],
    'phpunit assertion' => [
        fn (): FileReport => analyzeTokenTest(
            source: <<<'PHP'
                <?php
                final class CalculatorTest extends TestCase
                {
                    public function testAddsOneAndTwo(): void
                    {
                        self::assertIsInt(add(1, 2));
                    }

                    public function testAddsOneAndTwoToThree(): void
                    {
                        $sum = add(1, 2);

                        self::assertIsInt($sum);
                        self::assertSame(3, $sum);
                    }
                }
                PHP,
            linesByTest: [
                'testAddsOneAndTwo' => ['src/Calculator.php:5'],
                'testAddsOneAndTwoToThree' => ['src/Calculator.php:5', 'src/Calculator.php:6'],
            ],
        ),
        'testAddsOneAndTwo',
        [
            'in:expect:function:add/call:self::assertSame#1/variable:sum',
            'expect:function:add/call:self::assertSame',
            'in:expect:function:add/call:self::assertSame#0/value:3',
        ],
    ],
    'phpunit exception expectation' => [
        fn (): FileReport => analyzeTokenTest(
            source: <<<'PHP'
                <?php
                final class CalculatorTest extends TestCase
                {
                    public function testRejectsADivisionByZero(): void
                    {
                        $this->expectException(InvalidArgumentException::class);

                        divide(1, 0);
                    }

                    public function testRejectsADivisionByZeroWithAMessage(): void
                    {
                        $this->expectException(InvalidArgumentException::class);
                        $this->expectExceptionMessage('Division by zero.');

                        divide(1, 0);
                    }
                }
                PHP,
            linesByTest: [
                'testRejectsADivisionByZero' => ['src/Calculator.php:9'],
                'testRejectsADivisionByZeroWithAMessage' => ['src/Calculator.php:9', 'src/Calculator.php:10'],
            ],
        ),
        'testRejectsADivisionByZero',
        ['call:expectExceptionMessage', 'in:call:expectExceptionMessage#0/value:\'Division by zero.\''],
    ],
]);

it('keeps a dataset row whose literals a sibling row has at swapped positions', function (string $source, array $differsBy): void {
    $project = temporaryDirectory();
    mkdir($project . '/tests');
    file_put_contents($project . '/tests/NotifyTest.php', $source);

    $reports = new RedundancyAnalyzer(new FingerprintCache($project))->analyze(['tests/NotifyTest.php' => [
        'tests/NotifyTest.php :: it notifies › first' => ['src/Notifier.php:10' => true],
        'tests/NotifyTest.php :: it notifies › second' => ['src/Notifier.php:10' => true, 'src/Notifier.php:20' => true],
    ],
    ], map: null);

    expect($reports[0]->candidates)->toHaveCount(1)
        ->and($reports[0]->candidates[0]->testKey)->toBe('tests/NotifyTest.php :: it notifies › first')
        ->and($reports[0]->candidates[0]->differsBy)->toBe($differsBy)
        ->and($reports[0]->candidates[0]->action())->toBe(Action::ReviewDatasetRow);
})->with([
    'swapped addresses passed as arguments' => [
        <<<'PHP'
            <?php
            it('notifies', function (?string $subNotify, ?string $unsubNotify, Status $status, bool $expected): void {
                notify($subNotify, $unsubNotify, $status);

                expect(dispatched())->toBe($expected);
            })->with([
                'first' => ['owner@example.com', null, Status::SUBSCRIBED, true],
                'second' => [null, 'owner@example.com', Status::SUBSCRIBED, true],
            ]);
            PHP,
        ['in:function:notify#0/value:\'owner@example.com\'', 'in:function:notify#1/value:null'],
    ],
    'swapped addresses passed under keys' => [
        <<<'PHP'
            <?php
            it('notifies', function (?string $subNotify, ?string $unsubNotify, Status $status, bool $expected): void {
                notify(['sub_notify' => $subNotify, 'unsub_notify' => $unsubNotify], $status);

                expect(dispatched())->toBe($expected);
            })->with([
                'first' => ['owner@example.com', null, Status::SUBSCRIBED, true],
                'second' => [null, 'owner@example.com', Status::SUBSCRIBED, true],
            ]);
            PHP,
        ['in:function:notify#0/value:\'sub_notify\'/value:\'owner@example.com\'', 'in:function:notify#0/value:\'unsub_notify\'/value:null'],
    ],
    'swapped booleans' => [
        <<<'PHP'
            <?php
            it('notifies', function (bool $subscribed, bool $expected): void {
                expect(notify($subscribed))->toBe($expected);
            })->with([
                'first' => [false, true],
                'second' => [true, false],
            ]);
            PHP,
        ['in:function:notify#0/value:false', 'in:expect:function:notify/call:toBe#0/value:true'],
    ],
    'swapped values the rows pair with the same literal subjects' => [
        <<<'PHP'
            <?php
            it('notifies', function (string $first, int $firstCount, string $second, int $secondCount): void {
                expect($first)->toBe($firstCount);
                expect($second)->toBe($secondCount);
            })->with([
                'first' => ['5', 5, '6', 6],
                'second' => ['5', 6, '6', 5],
            ]);
            PHP,
        ['in:expect:value:\'5\'/call:toBe#0/value:5', 'in:expect:value:\'6\'/call:toBe#0/value:6'],
    ],
    'swapped values the test reads without the parameter name' => [
        <<<'PHP'
            <?php
            it('notifies', function (bool $subscribed, bool $expected): void {
                expect(notify(...func_get_args()))->toBeTrue();
            })->with([
                'first' => [false, true, 1],
                'second' => [true, false, 2],
            ]);
            PHP,
        ['value:1'],
    ],
]);

it('asks to merge a test whose literal arguments its covering test passes at swapped positions', function (): void {
    $project = temporaryDirectory();
    mkdir($project . '/tests');
    file_put_contents($project . '/tests/FlagsTest.php', <<<'PHP'
        <?php
        it('stores an enabled hidden item', function (): void {
            store(true, false);
        });
        it('stores a disabled visible item', function (): void {
            store(false, true);
        });
        PHP);

    $reports = new RedundancyAnalyzer(new FingerprintCache($project))->analyze(['tests/FlagsTest.php' => [
        'tests/FlagsTest.php :: it stores an enabled hidden item' => ['src/Flags.php:10' => true],
        'tests/FlagsTest.php :: it stores a disabled visible item' => ['src/Flags.php:10' => true, 'src/Flags.php:20' => true],
    ],
    ], map: null);

    expect($reports[0]->candidates)->toHaveCount(1)
        ->and($reports[0]->candidates[0]->differsBy)->toBe(['in:function:store#0/value:true', 'in:function:store#1/value:false'])
        ->and($reports[0]->candidates[0]->action())->toBe(Action::MergeIntoDataset);
});

it('reviews, and does not merge, a test whose value the covering dataset closure hard-codes in its body', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('appends vars to http links', function (string $content, string $vars, string $expected, bool $replace = false): void {
                expect(Content::addVarsToUrlQuery($content, $vars, true, $replace))->toBe($expected);
            })->with([
                'http link gets query appended' => ['<a href="http://a.cz">x</a>', 'test=true', '<a href="http://a.cz?test=true">x</a>'],
            ]);

            it('keeps encoded merge tags when containsMergeTags is false', function (): void {
                expect(Content::addVarsToUrlQuery('<a href="http://a.cz">x</a>', 'tag=%2A%7C', false))
                    ->toBe('<a href="http://a.cz?tag=%2A%7C">x</a>');
            });
            PHP,
        linesByTest: [
            'it keeps encoded merge tags when containsMergeTags is false' => ['src/Content.php:10'],
            'it appends vars to http links › http link gets query appended' => ['src/Content.php:10', 'src/Content.php:20'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it keeps encoded merge tags when containsMergeTags is false')
        ->and($report->candidates[0]->differsBy)->toContain('in:call:Content::addVarsToUrlQuery#2/value:false')
        ->and($report->candidates[0]->action())->toBe(Action::Review);
});

it('merges a test into a covering dataset only when each value in differsBy reaches a closure parameter that takes it', function (
    string $source,
    Action $action,
): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: ['it covers' => ['src/Foo.php:10'], 'it runs › row' => ['src/Foo.php:10', 'src/Foo.php:20']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it covers')
        ->and($report->candidates[0]->action())->toBe($action);
})->with([
    'another keyed array for a parameter of type array' => [
        <<<'PHP'
            <?php
            it('runs', fn (array $opts) => expect(f($opts))->toBeTrue())->with(['row' => [['a' => 1]]]);
            it('covers', fn () => expect(f(['a' => 2]))->toBeTrue());
            PHP,
        Action::MergeIntoDataset,
    ],
    'another list for a parameter of type array' => [
        <<<'PHP'
            <?php
            it('runs', fn (array $ids) => expect(find($ids))->toBeTrue())->with(['row' => [[1, 2]]]);
            it('covers', fn () => expect(find([3, 4]))->toBeTrue());
            PHP,
        Action::MergeIntoDataset,
    ],
    'another list length for a parameter without a type the covering closure puts in a list' => [
        <<<'PHP'
            <?php
            it('runs', fn ($n) => expect(f([$n]))->toBeTrue())->with(['row' => [1]]);
            it('covers', fn () => expect(f([1, 2]))->toBeTrue());
            PHP,
        Action::Review,
    ],
    'another list length for a parameter without a type the covering closure puts in an expected list' => [
        <<<'PHP'
            <?php
            it('runs', fn ($e) => expect(g())->toBe([$e]))->with(['row' => ['a']]);
            it('covers', fn () => expect(g())->toBe(['a', 'b']));
            PHP,
        Action::Review,
    ],
    'argument the covering closure does not pass' => [
        <<<'PHP'
            <?php
            it('runs', function (string $subject): void {
                expect(validate($subject))->toBeFalse();
            })->with(['row' => ['*|X|*']]);
            it('covers', fn () => expect(validate('*|Y|*', 'subject'))->toBeFalse());
            PHP,
        Action::Review,
    ],
    'empty list for a mixed parameter the covering closure puts in an expected list' => [
        <<<'PHP'
            <?php
            it('runs', fn (mixed $e) => expect(g())->toBe([$e]))->with(['row' => ['a']]);
            it('covers', fn () => expect(g())->toBe([]));
            PHP,
        Action::Review,
    ],
    'expected value under a key the covering closure hard-codes' => [
        <<<'PHP'
            <?php
            it('runs', function (Type $type, string $expectedKey): void {
                expect(build($type, null))->toBe([$expectedKey => null]);
            })->with(['row' => [Type::LEAD, 'lead_id']]);
            it('covers', fn () => expect(build(Type::PERSON, 7))->toBe(['person_id' => 7]));
            PHP,
        Action::Review,
    ],
    'float for a parameter of type int or string' => [
        <<<'PHP'
            <?php
            it('runs', fn (int|string $id) => expect(find($id))->toBeTrue())->with(['row' => [1]]);
            it('covers', fn () => expect(find(1.5))->toBeTrue());
            PHP,
        Action::Review,
    ],
    'keyed array for a parameter of type int' => [
        <<<'PHP'
            <?php
            it('runs', fn (int $a) => expect(f($a))->toBeTrue())->with(['row' => [1]]);
            it('covers', fn () => expect(f(['a' => 1]))->toBeTrue());
            PHP,
        Action::Review,
    ],
    'list item int for a parameter of type int the covering closure puts in a list' => [
        <<<'PHP'
            <?php
            it('runs', fn (int $n) => expect(f([$n]))->toBeTrue())->with(['row' => [1]]);
            it('covers', fn () => expect(f([2]))->toBeTrue());
            PHP,
        Action::MergeIntoDataset,
    ],
    'list item int for a parameter of type int the covering closure puts in a list beside a hard-coded int' => [
        <<<'PHP'
            <?php
            it('runs', fn (int $n) => expect(f([$n, 5]))->toBeTrue())->with(['row' => [1]]);
            it('covers', fn () => expect(f([6, 5]))->toBeTrue());
            PHP,
        Action::MergeIntoDataset,
    ],
    'list item int for a parameter of type string the covering closure puts in an expected list' => [
        <<<'PHP'
            <?php
            it('runs', fn (string $e) => expect(g())->toBe([$e]))->with(['row' => ['a']]);
            it('covers', fn () => expect(g())->toBe([2]));
            PHP,
        Action::Review,
    ],
    'list item int for the hard-coded int beside a parameter of type int in a covering closure list' => [
        <<<'PHP'
            <?php
            it('runs', fn (int $n) => expect(f([$n, 5]))->toBeTrue())->with(['row' => [1]]);
            it('covers', fn () => expect(f([1, 6]))->toBeTrue());
            PHP,
        Action::Review,
    ],
    'list item string for a parameter of type int the covering closure puts in a list' => [
        <<<'PHP'
            <?php
            it('runs', fn (int $n) => expect(f([$n]))->toBeTrue())->with(['row' => [1]]);
            it('covers', fn () => expect(f(['x']))->toBeTrue());
            PHP,
        Action::Review,
    ],
    'list item string for a parameter of type string the covering closure puts in an expected list' => [
        <<<'PHP'
            <?php
            it('runs', fn (string $e) => expect(g())->toBe([$e]))->with(['row' => ['a']]);
            it('covers', fn () => expect(g())->toBe(['b']));
            PHP,
        Action::MergeIntoDataset,
    ],
    'list item string for a parameter of type string the covering closure puts in an expected list beside a hard-coded string' => [
        <<<'PHP'
            <?php
            it('runs', fn (string $e) => expect(g())->toBe([$e, 'x']))->with(['row' => ['a']]);
            it('covers', fn () => expect(g())->toBe(['b', 'x']));
            PHP,
        Action::MergeIntoDataset,
    ],
    'list item string for a parameter without a type the covering closure puts in an expected list' => [
        <<<'PHP'
            <?php
            it('runs', fn ($e) => expect(g())->toBe([$e]))->with(['row' => ['a']]);
            it('covers', fn () => expect(g())->toBe(['b']));
            PHP,
        Action::MergeIntoDataset,
    ],
    'list item string for the hard-coded string beside a parameter without a type in a covering expected list' => [
        <<<'PHP'
            <?php
            it('runs', fn ($e) => expect(g())->toBe([$e, 'x']))->with(['row' => ['a']]);
            it('covers', fn () => expect(g())->toBe(['b', 'c']));
            PHP,
        Action::Review,
    ],
    'longer list for a parameter without a type' => [
        <<<'PHP'
            <?php
            it('runs', fn ($x) => expect(f($x))->toBeTrue())->with(['row' => [[1]]]);
            it('covers', fn () => expect(f([1, 2]))->toBeTrue());
            PHP,
        Action::MergeIntoDataset,
    ],
    'null for a nullable parameter' => [
        <<<'PHP'
            <?php
            it('runs', fn (?int $reminderCode) => expect(type($reminderCode))->toBe('reminder'))->with(['row' => [1]]);
            it('covers', fn () => expect(type(null))->toBe('reminder'));
            PHP,
        Action::MergeIntoDataset,
    ],
    'null for a parameter of type array' => [
        <<<'PHP'
            <?php
            it('runs', fn (array $ids) => expect(find($ids))->toBeTrue())->with(['row' => [[1, 2]]]);
            it('covers', fn () => expect(find(null))->toBeTrue());
            PHP,
        Action::Review,
    ],
    'null for a parameter of type callable' => [
        <<<'PHP'
            <?php
            it('runs', fn (callable $f) => expect(apply($f))->toBeTrue())->with(['row' => ['strlen']]);
            it('covers', fn () => expect(apply(null))->toBeTrue());
            PHP,
        Action::Review,
    ],
    'null for a parameter of type int' => [
        <<<'PHP'
            <?php
            it('runs', fn (int $reminderCode) => expect(type($reminderCode))->toBe('reminder'))->with(['row' => [1]]);
            it('covers', fn () => expect(type(null))->toBe('reminder'));
            PHP,
        Action::Review,
    ],
    'scalar for a parameter of type array' => [
        <<<'PHP'
            <?php
            it('runs', fn (array $ids) => expect(find($ids))->toBeTrue())->with(['row' => [[1, 2]]]);
            it('covers', fn () => expect(find(5))->toBeTrue());
            PHP,
        Action::Review,
    ],
    'shorter expected list for a parameter without a type the covering closure expects' => [
        <<<'PHP'
            <?php
            it('runs', fn ($n, $e) => expect(g($n))->toBe($e))->with(['row' => [2, ['a', 'b']]]);
            it('covers', fn () => expect(g(1))->toBe(['a']));
            PHP,
        Action::MergeIntoDataset,
    ],
    'shorter list than a covering expected list that hard-codes a string beside a parameter without a type' => [
        <<<'PHP'
            <?php
            it('runs', fn ($e) => expect(g())->toBe([$e, 'x']))->with(['row' => ['a']]);
            it('covers', fn () => expect(g())->toBe(['b']));
            PHP,
        Action::Review,
    ],
    'string for an enum parameter' => [
        <<<'PHP'
            <?php
            it('runs', fn (Status $status) => expect(label($status))->toBeTrue())->with(['row' => [Status::ACTIVE]]);
            it('covers', fn () => expect(label('inactive'))->toBeTrue());
            PHP,
        Action::Review,
    ],
    'string for a parameter of type iterable' => [
        <<<'PHP'
            <?php
            it('runs', fn (iterable $items) => expect(sum($items))->toBe(3))->with(['row' => [[1, 2]]]);
            it('covers', fn () => expect(sum('abc'))->toBe(3));
            PHP,
        Action::Review,
    ],
    'string with a slash for a parameter of type string' => [
        <<<'PHP'
            <?php
            it('runs', fn (string $url) => expect(fetch($url))->toBeTrue())->with(['row' => ['http://a.cz/x']]);
            it('covers', fn () => expect(fetch('http://a.cz/y'))->toBeTrue());
            PHP,
        Action::MergeIntoDataset,
    ],
    'value for a parameter without a type' => [
        <<<'PHP'
            <?php
            it('runs', fn ($value) => expect(store($value))->toBeTrue())->with(['row' => [1]]);
            it('covers', fn () => expect(store(null))->toBeTrue());
            PHP,
        Action::MergeIntoDataset,
    ],
]);

it('reviews, and does not merge, a test whose value the data provider test hard-codes in its body', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            final class CalculatorTest extends TestCase
            {
                #[DataProvider('sums')]
                public function testAdds(int $first, int $second, int $sum): void { self::assertSame($sum, add($first, $second, false)); }
                public static function sums(): iterable { yield 'one and two' => [1, 2, 3]; }
                public function testAddsRounded(): void { self::assertSame(3, add(1, 2, true)); }
            }
            PHP,
        linesByTest: ['testAddsRounded' => ['src/Calculator.php:10'], 'testAdds › one and two' => ['src/Calculator.php:10', 'src/Calculator.php:20']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('testAddsRounded')
        ->and($report->candidates[0]->differsBy)->toBe(['in:function:add#2/value:true'])
        ->and($report->candidates[0]->action())->toBe(Action::Review);
});

it('asks for a review of a test whose expected value lacks an element, a key or an argument its covering test expects', function (
    string $source,
    string $coveringTest,
    string ...$coveringDiffersBy,
): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: ['it expects less' => ['src/Subject.php:5'], $coveringTest => ['src/Subject.php:5', 'src/Subject.php:6']],
    );
    $json = decodedJson((string) json_encode($report->candidates[0]));

    expect($report->candidates)->toHaveCount(1)
        ->and(valueAt($json, 'test'))->toBe('it expects less')
        ->and(valueAt($json, 'action'))->toBe('review')
        ->and(valueAt($json, 'coveringDiffersBy'))->toBe($coveringDiffersBy)
        ->and(valueAt($json, 'steps'))->toBe([
            sprintf(Action::MISSING_EXPECTATION_STEP, sprintf('"%s"', $coveringTest), implode(', ', $coveringDiffersBy)),
            'Check whether differsBy holds an assertion the covering tests lack: another result, input class, ordering or rejection.',
            'Delete this test only when it does not; otherwise keep it.',
            Action::VERIFY_STEP,
        ]);
})->with([
    'array without a key the covering test expects' => [
        <<<'PHP'
            <?php
            it('expects less', function (): void {
                expect(new Context(isImpersonated: false, impersonatorEmail: 'a@b.cz')->toArray())
                    ->toBe(['impersonated' => false]);
            });
            it('expects the impersonator', function (): void {
                expect(new Context(isImpersonated: true, impersonatorEmail: 'a@b.cz')->toArray())
                    ->toBe(['impersonated' => true, 'impersonator_email' => 'a@b.cz']);
            });
            PHP,
        'it expects the impersonator',
        'in:expect:new:Context/call:toArray/call:toBe#0/key:value:\'impersonator_email\'',
    ],
    'array without a key the covering test expects beside a key both expect with another value' => [
        <<<'PHP'
            <?php
            it('expects less', fn () => expect(createAccount(Plan::FREE)->toArray())->toBe(['plan' => 0]));
            it('expects the credit', fn () => expect(createAccount(Plan::CREDIT)->toArray())->toBe(['plan' => 1, 'credit' => 0]));
            PHP,
        'it expects the credit',
        'in:expect:function:createAccount/call:toArray/call:toBe#0/key:value:\'credit\'',
    ],
    'array without a nested key the covering test expects' => [
        <<<'PHP'
            <?php
            it('expects less', fn () => expect(build('sms'))->toEqual(['type' => 'sms']));
            it('expects the properties', fn () => expect(build('mail'))->toEqual(['type' => 'mail', 'properties' => ['senderMail' => 'a@example.com']]));
            PHP,
        'it expects the properties',
        'in:expect:function:build/call:toEqual#0/key:value:\'properties\'',
        'in:expect:function:build/call:toEqual#0/value:\'properties\'/key:value:\'senderMail\'',
    ],
    'exception without the message the covering test expects' => [
        <<<'PHP'
            <?php
            it('expects less', fn () => expect(fn () => import('a,b'))->toThrow(InvalidArgumentException::class));
            it('expects the line', fn () => expect(fn () => import('a,b,c'))->toThrow(InvalidArgumentException::class, 'vocatives_cz.csv:2'));
            PHP,
        'it expects the line',
        'in:expect:function:import/call:toThrow#1/value:\'vocatives_cz.csv:2\'',
    ],
    'list without an element the covering test expects beside an element both expect with another value' => [
        <<<'PHP'
            <?php
            it('expects less', fn () => expect(recipients(1))->toBe(['a@test.com']));
            it('expects two recipients', fn () => expect(recipients(2))->toBe(['b@test.com', 'c@test.com']));
            PHP,
        'it expects two recipients',
        'in:expect:function:recipients/call:toBe#0/value:list-item',
    ],
    'list without an element the covering test expects in any order' => [
        <<<'PHP'
            <?php
            it('expects less', fn () => expect(resolve('segment', allowNonActive: false))->toEqualCanonicalizing(['rfm.active@test.com']));
            it('expects the inactive recipient', fn () => expect(resolve('segment', allowNonActive: true))
                ->toEqualCanonicalizing(['rfm.active@test.com', 'rfm.inactive@test.com']));
            PHP,
        'it expects the inactive recipient',
        'in:expect:function:resolve/call:toEqualCanonicalizing#0/value:list-item',
        'in:expect:function:resolve/call:toEqualCanonicalizing#0/value:\'rfm.inactive@test.com\'',
    ],
]);

it('reviews, and does not merge, a test that expects another exception than the test-level throws() of its covering test', function (
    string $source,
    array $differsBy,
    string ...$coveringDiffersBy,
): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: ['it rejects one input' => ['src/Validator.php:5'], 'it rejects another input' => ['src/Validator.php:5', 'src/Validator.php:6']],
    );
    $json = decodedJson((string) json_encode($report->candidates[0]));

    expect($report->candidates)->toHaveCount(1)
        ->and(valueAt($json, 'test'))->toBe('it rejects one input')
        ->and(valueAt($json, 'action'))->toBe('review')
        ->and(valueAt($json, 'differsBy'))->toBe($differsBy)
        ->and(valueAt($json, 'coveringDiffersBy'))->toBe($coveringDiffersBy)
        ->and(valueAt($json, 'steps'))->toBe([
            sprintf(Action::EXPECTED_EXCEPTION_STEP, '"it rejects another input"', implode(', ', $coveringDiffersBy)),
            'Check whether differsBy holds an assertion the covering tests lack: another result, input class, ordering or rejection.',
            'Delete this test only when it does not; otherwise keep it.',
            Action::VERIFY_STEP,
        ]);
})->with([
    'a call only this test makes and no exception against the throws() of the covering test' => [
        <<<'PHP'
            <?php
            it('rejects one input', fn () => validate(trim(' ')));
            it('rejects another input', fn () => validate('a'))->throws(InvalidUrlAddress::class);
            PHP,
        ['in:function:validate#0/non-literal', 'function:trim', 'in:function:validate#0/function:trim#0/value:\' \''],
        'call:throws',
        'call:throws#0/const:InvalidUrlAddress::class',
    ],
    'another exception class in throws()' => [
        <<<'PHP'
            <?php
            it('rejects one input', function (): void {
                validateWebsites(['website' => '', 'mainDomain' => 'https://example.com']);
            })->throws(EmailNotFound::class);
            it('rejects another input', function (): void {
                validateWebsites(['website' => 'https://example.com', 'mainDomain' => '']);
            })->throws(InvalidUrlAddress::class);
            PHP,
        [
            'in:function:validateWebsites#0/value:\'website\'/value:\'\'',
            'in:function:validateWebsites#0/value:\'mainDomain\'/value:\'https://example.com\'',
            'call:throws#0/const:EmailNotFound::class',
        ],
        'call:throws#0/const:InvalidUrlAddress::class',
    ],
    'another exception class in throwsIf()' => [
        <<<'PHP'
            <?php
            it('rejects one input', fn () => validate(''))->throwsIf(PHP_OS_FAMILY === 'Linux', EmailNotFound::class);
            it('rejects another input', fn () => validate('a'))->throwsIf(PHP_OS_FAMILY === 'Linux', InvalidUrlAddress::class);
            PHP,
        ['in:function:validate#0/value:\'\'', 'call:throwsIf#1/const:EmailNotFound::class'],
        'call:throwsIf#1/const:InvalidUrlAddress::class',
    ],
    'another exception class in throwsUnless()' => [
        <<<'PHP'
            <?php
            it('rejects one input', fn () => validate(''))->throwsUnless(PHP_OS_FAMILY === 'Linux', EmailNotFound::class);
            it('rejects another input', fn () => validate('a'))->throwsUnless(PHP_OS_FAMILY === 'Linux', InvalidUrlAddress::class);
            PHP,
        ['in:function:validate#0/value:\'\'', 'call:throwsUnless#1/const:EmailNotFound::class'],
        'call:throwsUnless#1/const:InvalidUrlAddress::class',
    ],
    'another exception message as a named argument of throws()' => [
        <<<'PHP'
            <?php
            it('rejects one input', fn () => validate(''))->throws(InvalidArgumentException::class, exceptionMessage: 'The website is empty.');
            it('rejects another input', fn () => validate('a'))->throws(InvalidArgumentException::class, exceptionMessage: 'The website is invalid.');
            PHP,
        ['in:function:validate#0/value:\'\'', 'call:throws#exceptionMessage/value:\'The website is empty.\''],
        'call:throws#exceptionMessage/value:\'The website is invalid.\'',
    ],
    'another exception message in throws()' => [
        <<<'PHP'
            <?php
            it('rejects one input', fn () => validate(''))->throws(InvalidArgumentException::class, 'The website is empty.');
            it('rejects another input', fn () => validate('a'))->throws(InvalidArgumentException::class, 'The website is invalid.');
            PHP,
        ['in:function:validate#0/value:\'\'', 'call:throws#1/value:\'The website is empty.\''],
        'call:throws#1/value:\'The website is invalid.\'',
    ],
    'another operator in the condition of throwsIf()' => [
        <<<'PHP'
            <?php
            it('rejects one input', fn () => validate(''))->throwsIf($response === null, InvalidUrlAddress::class);
            it('rejects another input', fn () => validate('a'))->throwsIf($response !== null, InvalidUrlAddress::class);
            PHP,
        ['in:function:validate#0/value:\'\'', 'call:throwsIf#0/source:$response === null'],
        'call:throwsIf#0/source:$response !== null',
    ],
    'another property read by the closure condition of throwsIf()' => [
        <<<'PHP'
            <?php
            it('rejects one input', fn () => validate(''))->throwsIf(fn () => $this->onLinux, InvalidUrlAddress::class);
            it('rejects another input', fn () => validate('a'))->throwsIf(fn () => $this->onMac, InvalidUrlAddress::class);
            PHP,
        ['in:function:validate#0/value:\'\'', 'call:throwsIf#0/source:fn() => $this->onLinux'],
        'call:throwsIf#0/source:fn() => $this->onMac',
    ],
    'another variable as the condition of throwsIf()' => [
        <<<'PHP'
            <?php
            it('rejects one input', fn () => validate(''))->throwsIf($onLinux, InvalidUrlAddress::class);
            it('rejects another input', fn () => validate('a'))->throwsIf($onMac, InvalidUrlAddress::class);
            PHP,
        ['in:function:validate#0/value:\'\'', 'call:throwsIf#0/source:$onLinux'],
        'call:throwsIf#0/source:$onMac',
    ],
    'another variable as the condition of throwsUnless()' => [
        <<<'PHP'
            <?php
            it('rejects one input', fn () => validate(''))->throwsUnless($onLinux, InvalidUrlAddress::class);
            it('rejects another input', fn () => validate('a'))->throwsUnless($onMac, InvalidUrlAddress::class);
            PHP,
        ['in:function:validate#0/value:\'\'', 'call:throwsUnless#0/source:$onLinux'],
        'call:throwsUnless#0/source:$onMac',
    ],
    'another variable as the exception class of throws()' => [
        <<<'PHP'
            <?php
            it('rejects one input', fn () => validate(''))->throws($emailNotFound);
            it('rejects another input', fn () => validate('a'))->throws($invalidUrlAddress);
            PHP,
        ['in:function:validate#0/value:\'\'', 'call:throws#0/source:$emailNotFound'],
        'call:throws#0/source:$invalidUrlAddress',
    ],
    'another variable interpolated into the exception message of throws()' => [
        <<<'PHP'
            <?php
            it('rejects one input', fn () => validate(''))->throws(InvalidArgumentException::class, "The website {$website} is empty.");
            it('rejects another input', fn () => validate('a'))->throws(InvalidArgumentException::class, "The website {$domain} is empty.");
            PHP,
        ['in:function:validate#0/value:\'\'', 'call:throws#1/source:"The website {$website} is empty."'],
        'call:throws#1/source:"The website {$domain} is empty."',
    ],
    'no exception against the throws() of the covering test' => [
        <<<'PHP'
            <?php
            it('rejects one input', fn () => validate(''));
            it('rejects another input', fn () => validate('a'))->throws(InvalidUrlAddress::class);
            PHP,
        ['in:function:validate#0/value:\'\''],
        'call:throws',
        'call:throws#0/const:InvalidUrlAddress::class',
    ],
]);

it('asks for a review of a test whose covering test runs the same code and expects more of an exception through its test-level throws()', function (
    string $source,
    string ...$coveringDiffersBy,
): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: ['it validates a website' => ['src/Validator.php:5'], 'it rejects a website' => ['src/Validator.php:5', 'src/Validator.php:6']],
    );
    $json = decodedJson((string) json_encode($report->candidates[0]));

    expect($report->candidates)->toHaveCount(1)
        ->and(valueAt($json, 'test'))->toBe('it validates a website')
        ->and(valueAt($json, 'action'))->toBe('review')
        ->and(valueAt($json, 'differsBy'))->toBe([])
        ->and(valueAt($json, 'coveringDiffersBy'))->toBe($coveringDiffersBy)
        ->and(valueAt($json, 'steps'))->toBe([
            sprintf(Action::EXPECTED_EXCEPTION_STEP, '"it rejects a website"', implode(', ', $coveringDiffersBy)),
            Action::VERIFY_STEP,
        ]);
})->with([
    'no exception' => [
        <<<'PHP'
            <?php
            it('validates a website', fn () => validate(''));
            it('rejects a website', fn () => validate(''))->throws(InvalidUrlAddress::class);
            PHP,
        'call:throws',
        'call:throws#0/const:InvalidUrlAddress::class',
    ],
    'no message that reads like an assertion after a colon' => [
        <<<'PHP'
            <?php
            it('validates a website', fn () => validate(''))->throws(InvalidUrlAddress::class);
            it('rejects a website', fn () => validate(''))->throws(InvalidUrlAddress::class, 'Validation failed:expected an absolute URL');
            PHP,
        'call:throws#1/value:\'Validation failed:expected an absolute URL\'',
    ],
]);

it('names the test-level throws() of no covering test when two tests cover the candidate', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('validates a website', fn () => validate(''));
            it('rejects a website', fn () => validate('a'))->throws(InvalidUrlAddress::class);
            it('accepts a website', fn () => validate('b'));
            PHP,
        linesByTest: [
            'it validates a website' => ['src/Validator.php:5', 'src/Validator.php:6'],
            'it rejects a website' => ['src/Validator.php:5', 'src/Validator.php:7'],
            'it accepts a website' => ['src/Validator.php:6', 'src/Validator.php:8'],
        ],
    );
    $json = decodedJson((string) json_encode($report->candidates[0]));

    expect($report->candidates)->toHaveCount(1)
        ->and(valueAt($json, 'test'))->toBe('it validates a website')
        ->and(valueAt($json, 'coveredBy'))->toBe(['it rejects a website', 'it accepts a website'])
        ->and(valueAt($json, 'action'))->toBe('review')
        ->and(valueAt($json, 'coveringDiffersBy'))->toBe([])
        ->and(valueAt($json, 'steps'))->toBe([
            'Check whether differsBy holds an assertion the covering tests lack: another result, input class, ordering or rejection.',
            'Delete this test only when it does not; otherwise keep it.',
            Action::VERIFY_STEP,
        ]);
});

it('still merges a test whose covering test expects the same exception through its test-level throws()', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('rejects an empty website', fn () => validate(''))->throws(InvalidUrlAddress::class, 'The website is invalid.');
            it('rejects a short website', fn () => validate('a'))->throws(InvalidUrlAddress::class, 'The website is invalid.');
            PHP,
        linesByTest: [
            'it rejects an empty website' => ['src/Validator.php:5'],
            'it rejects a short website' => ['src/Validator.php:5', 'src/Validator.php:6'],
        ],
    );
    $json = decodedJson((string) json_encode($report->candidates[0]));

    expect($report->candidates)->toHaveCount(1)
        ->and(valueAt($json, 'differsBy'))->toBe(['in:function:validate#0/value:\'\''])
        ->and(valueAt($json, 'coveringDiffersBy'))->toBe([])
        ->and(valueAt($json, 'action'))->toBe('merge-into-dataset');
});

it('asks to merge a test whose expected value replaces the value under which its covering test expects a key', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('builds plain options', fn () => expect(build(1))->toBe(['options' => []]));
            it('builds colored options', fn () => expect(build(2))->toBe(['options' => ['color' => 'red']]));
            PHP,
        linesByTest: [
            'it builds plain options' => ['src/Builder.php:5'],
            'it builds colored options' => ['src/Builder.php:5', 'src/Builder.php:6'],
        ],
    );
    $json = decodedJson((string) json_encode($report->candidates[0]));

    expect($report->candidates)->toHaveCount(1)
        ->and(valueAt($json, 'test'))->toBe('it builds plain options')
        ->and(valueAt($json, 'differsBy'))->toBe([
            'in:function:build#0/value:1',
            'in:expect:function:build/call:toBe#0/value:\'options\'/value:[]',
        ])
        ->and(valueAt($json, 'coveringDiffersBy'))->toBe([])
        ->and(valueAt($json, 'action'))->toBe('merge-into-dataset');
});

it('asks to merge a test whose expected empty list replaces the list in which its covering test expects elements', function (string $source): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: [
            'it builds no items' => ['src/Builder.php:5'],
            'it builds two items' => ['src/Builder.php:5', 'src/Builder.php:6'],
        ],
    );
    $json = decodedJson((string) json_encode($report->candidates[0]));

    expect($report->candidates)->toHaveCount(1)
        ->and(valueAt($json, 'test'))->toBe('it builds no items')
        ->and(valueAt($json, 'coveringDiffersBy'))->toBe([])
        ->and(valueAt($json, 'action'))->toBe('merge-into-dataset');
})->with([
    'empty list as the whole expected value' => [
        <<<'PHP'
            <?php
            it('builds no items', fn () => expect(build(1))->toBe([]));
            it('builds two items', fn () => expect(build(2))->toBe(['x', 'y']));
            PHP,
    ],
    'empty list under a key' => [
        <<<'PHP'
            <?php
            it('builds no items', fn () => expect(build(1))->toBe(['items' => []]));
            it('builds two items', fn () => expect(build(2))->toBe(['items' => ['x', 'y']]));
            PHP,
    ],
]);

it('asks to merge a test that passes an enum value to the argument its covering test passes another value to', function (
    string $source,
    string $enumValue,
): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: ['it passes the enum value' => ['src/Import.php:5'], 'it passes another value' => ['src/Import.php:5', 'src/Import.php:6']],
    );
    $json = decodedJson((string) json_encode($report->candidates[0]));

    expect($report->candidates)->toHaveCount(1)
        ->and(valueAt($json, 'test'))->toBe('it passes the enum value')
        ->and(valueAt($json, 'differsBy'))->toBe([$enumValue])
        ->and(valueAt($json, 'action'))->toBe('merge-into-dataset')
        ->and(valueAt($json, 'coveringDiffersBy'))->toBe([]);
})->with([
    'enum name against the enum value passed straight to a call' => [
        <<<'PHP'
            <?php
            it('passes the enum value', fn () => store(CouponType::FIXED->name));
            it('passes another value', fn () => store(CouponType::FIXED->value));
            PHP,
        'in:function:store#0/const:CouponType::FIXED->name',
    ],
    'enum value against a literal as an item of a list passed to a call' => [
        <<<'PHP'
            <?php
            it('passes the enum value', fn () => store([CouponType::FIXED->value]));
            it('passes another value', fn () => store(['fixed']));
            PHP,
        'in:function:store#0/const:CouponType::FIXED->value',
    ],
    'enum value against a literal passed straight to a call' => [
        <<<'PHP'
            <?php
            it('passes the enum value', fn () => $this->post('/import', CouponType::FIXED->value)->assertOk());
            it('passes another value', fn () => $this->post('/import', 'percentual')->assertOk());
            PHP,
        'in:call:post#1/const:CouponType::FIXED->value',
    ],
    'enum value against a literal under a key of an array passed to a call' => [
        <<<'PHP'
            <?php
            it('passes the enum value', fn () => $this->post('/import', ['default_type' => CouponType::FIXED->value])->assertOk());
            it('passes another value', fn () => $this->post('/import', ['default_type' => 'percentual'])->assertOk());
            PHP,
        'in:call:post#1/value:\'default_type\'/const:CouponType::FIXED->value',
    ],
    'enum value against the enum case as an item of a list passed to a call' => [
        <<<'PHP'
            <?php
            it('passes the enum value', fn () => store([CouponType::FIXED->value]));
            it('passes another value', fn () => store([CouponType::FIXED]));
            PHP,
        'in:function:store#0/const:CouponType::FIXED->value',
    ],
    'enum value against the enum case passed straight to a call' => [
        <<<'PHP'
            <?php
            it('passes the enum value', fn () => store(CouponType::FIXED->value));
            it('passes another value', fn () => store(CouponType::FIXED));
            PHP,
        'in:function:store#0/const:CouponType::FIXED->value',
    ],
    'enum value against the enum case under a key of an array passed to a call' => [
        <<<'PHP'
            <?php
            it('passes the enum value', fn () => $this->post('/import', ['type' => CouponType::FIXED->value])->assertOk());
            it('passes another value', fn () => $this->post('/import', ['type' => CouponType::FIXED])->assertOk());
            PHP,
        'in:call:post#1/value:\'type\'/const:CouponType::FIXED->value',
    ],
]);

it('still deletes a test whose values a dataset row or a local variable passes to the same arguments', function (
    string $source,
    string $test,
    string $covering,
): void {
    $project = temporaryDirectory();
    mkdir($project . '/tests');
    file_put_contents($project . '/tests/CalculatorTest.php', $source);

    $reports = new RedundancyAnalyzer(new FingerprintCache($project))->analyze(['tests/CalculatorTest.php' => [
        'tests/CalculatorTest.php :: ' . $test => ['src/Calculator.php:10' => true],
        'tests/CalculatorTest.php :: ' . $covering => ['src/Calculator.php:10' => true, 'src/Calculator.php:20' => true],
    ],
    ], map: null);

    expect($reports[0]->candidates)->toHaveCount(1)
        ->and($reports[0]->candidates[0]->testKey)->toBe('tests/CalculatorTest.php :: ' . $test)
        ->and($reports[0]->candidates[0]->differsBy)->toBe([])
        ->and($reports[0]->candidates[0]->action())->toBe(Action::Delete);
})->with([
    'pest dataset row against a test that holds the value in a local variable' => [
        <<<'PHP'
            <?php
            it('notifies', function (string $email): void {
                notify($email);
            })->with(['the owner' => ['owner@example.com']]);
            it('notifies the owner', function (): void {
                $email = 'owner@example.com';
                notify($email);
            });
            PHP,
        'it notifies the owner',
        'it notifies › the owner',
    ],
    'pest dataset row of one value' => [
        <<<'PHP'
            <?php
            it('doubles', function (int $value): void {
                expect(double($value))->toBe(4);
            })->with(['two' => 2]);
            it('doubles two', function (): void {
                expect(double(2))->toBe(4);
            });
            PHP,
        'it doubles two',
        'it doubles › two',
    ],
    'pest dataset row whose parameter a closure use, a catch and a static declare again' => [
        <<<'PHP'
            <?php
            it('adds', function (int $first, int $sum): void {
                $add = function () use ($first): int {
                    try {
                        return add($first);
                    } catch (Throwable $first) {
                        static $first;

                        return 0;
                    }
                };

                expect($add())->toBe($sum);
            })->with(['one' => [1, 2]]);
            it('adds one', function (): void {
                $add = function () use ($first): int {
                    try {
                        return add(1);
                    } catch (Throwable $first) {
                        static $first;

                        return 0;
                    }
                };

                expect($add())->toBe(2);
            });
            PHP,
        'it adds one',
        'it adds › one',
    ],
    'phpunit data provider row' => [
        <<<'PHP'
            <?php
            final class CalculatorTest extends TestCase
            {
                #[DataProvider('sums')]
                public function testAdds(int $first, int $second, int $sum): void { self::assertSame($sum, add($first, $second)); }
                public static function sums(): iterable { yield 'one and two' => [1, 2, 3]; }
                public function testAddsOneAndTwo(): void { self::assertSame(3, add(1, 2)); }
            }
            PHP,
        'testAddsOneAndTwo',
        'testAdds › one and two',
    ],
    'test that holds the value in a local variable against a test that passes it inline' => [
        <<<'PHP'
            <?php
            it('notifies the owner', function (): void {
                $email = 'owner@example.com';
                notify($email);
            });
            it('notifies the owner inline', function (): void {
                notify('owner@example.com');
            });
            PHP,
        'it notifies the owner',
        'it notifies the owner inline',
    ],
]);

it('asks for a review only when a test makes a call or creates an object a different number of times than its covering test', function (
    string $source,
    string $test,
    string $covering,
    array $differsBy,
    Action $action,
): void {
    $project = temporaryDirectory();
    mkdir($project . '/tests');
    file_put_contents($project . '/tests/CountTest.php', $source);

    $reports = new RedundancyAnalyzer(new FingerprintCache($project))->analyze(['tests/CountTest.php' => [
        'tests/CountTest.php :: ' . $test => ['src/Subscription.php:10' => true],
        'tests/CountTest.php :: ' . $covering => ['src/Subscription.php:10' => true, 'src/Subscription.php:20' => true],
    ],
    ], map: null);

    expect($reports[0]->candidates)->toHaveCount(1)
        ->and($reports[0]->candidates[0]->testKey)->toBe('tests/CountTest.php :: ' . $test)
        ->and($reports[0]->candidates[0]->differsBy)->toBe($differsBy)
        ->and($reports[0]->candidates[0]->action())->toBe($action);
})->with([
    'a cleanup run once against twice through an action held in a class constant' => [
        <<<'PHP'
            <?php
            it('clears every mirrored row', function (): void {
                (self::HANDLER)('acme');
                expect(mirroredProductIds())->toBe(['KEEP']);
            });
            it('stays idempotent when the cleanup runs twice', function (): void {
                (self::HANDLER)('acme');
                (self::HANDLER)('acme');
                expect(mirroredProductIds())->toBe(['KEEP']);
            });
            PHP,
        'it clears every mirrored row',
        'it stays idempotent when the cleanup runs twice',
        [],
        Action::Review,
    ],
    'a cleanup run once against twice through an action held in an array element' => [
        <<<'PHP'
            <?php
            it('clears every mirrored row', function (): void {
                ($this->actions['cleanUp'])('acme');
                expect(mirroredProductIds())->toBe(['KEEP']);
            });
            it('stays idempotent when the cleanup runs twice', function (): void {
                ($this->actions['cleanUp'])('acme');
                ($this->actions['cleanUp'])('acme');
                expect(mirroredProductIds())->toBe(['KEEP']);
            });
            PHP,
        'it clears every mirrored row',
        'it stays idempotent when the cleanup runs twice',
        [],
        Action::Review,
    ],
    'a cleanup run once against twice through an action held in an element of a class constant' => [
        <<<'PHP'
            <?php
            it('clears every mirrored row', function (): void {
                (Actions::ALL['cleanUp'])('acme');
                expect(mirroredProductIds())->toBe(['KEEP']);
            });
            it('stays idempotent when the cleanup runs twice', function (): void {
                (Actions::ALL['cleanUp'])('acme');
                (Actions::ALL['cleanUp'])('acme');
                expect(mirroredProductIds())->toBe(['KEEP']);
            });
            PHP,
        'it clears every mirrored row',
        'it stays idempotent when the cleanup runs twice',
        [],
        Action::Review,
    ],
    'a cleanup run once against twice through an action held in a property' => [
        <<<'PHP'
            <?php
            it('clears every mirrored row', function (): void {
                ($this->cleanUp)($this->account);
                expect(mirroredProductIds())->toBe(['KEEP']);
            });
            it('stays idempotent when the cleanup runs twice', function (): void {
                ($this->cleanUp)($this->account);
                ($this->cleanUp)($this->account);
                expect(mirroredProductIds())->toBe(['KEEP']);
            });
            PHP,
        'it clears every mirrored row',
        'it stays idempotent when the cleanup runs twice',
        [],
        Action::Review,
    ],
    'a cleanup run once against twice through a nullsafe method call' => [
        <<<'PHP'
            <?php
            it('clears every mirrored row', function (): void {
                $this->action?->handle('acme');
                expect(mirroredProductIds())->toBe(['KEEP']);
            });
            it('stays idempotent when the cleanup runs twice', function (): void {
                $this->action?->handle('acme');
                $this->action?->handle('acme');
                expect(mirroredProductIds())->toBe(['KEEP']);
            });
            PHP,
        'it clears every mirrored row',
        'it stays idempotent when the cleanup runs twice',
        [],
        Action::Review,
    ],
    'a contact on one list against a contact on two lists' => [
        <<<'PHP'
            <?php
            it('deactivates a contact unsubscribed from its only subscribed list', function (): void {
                $list = Lists::factory()->create();
                $subscriber = Subscriber::factory()->create(['active' => 1]);
                Connection::factory()->create(['email' => $subscriber->email, 'list_id' => $list->id]);
                expectCounterDelta(-1);
            });
            it('activates an inactive contact still subscribed in another list', function (): void {
                $list = Lists::factory()->create();
                $otherList = Lists::factory()->create();
                $subscriber = Subscriber::factory()->create(['active' => 0]);
                foreach ([$list, $otherList] as $subscribedList) {
                    Connection::factory()->create(['email' => $subscriber->email, 'list_id' => $subscribedList->id]);
                }
                expectCounterDelta(1);
            });
            PHP,
        'it deactivates a contact unsubscribed from its only subscribed list',
        'it activates an inactive contact still subscribed in another list',
        [
            'in:call:create#0/value:\'list_id\'/element:variable:list/property:id',
            'in:call:create#0/value:\'active\'/value:1',
            'call:Connection::factory',
            'in:function:expectCounterDelta#0/value:-1',
        ],
        Action::Review,
    ],
    'an address stored twice against once' => [
        <<<'PHP'
            <?php
            it('stores the same address twice', function (): void {
                store('a@example.com');
                store('a@example.com');
                expect(stored())->toBe(1);
            });
            it('stores an address once', function (): void {
                store('a@example.com');
                expect(stored())->toBe(2);
            });
            PHP,
        'it stores the same address twice',
        'it stores an address once',
        ['function:store', 'in:function:store#0/value:\'a@example.com\'', 'in:expect:function:stored/call:toBe#0/value:1'],
        Action::Review,
    ],
    'an import run once against twice through a command held in an array element under a key with a colon' => [
        <<<'PHP'
            <?php
            it('imports the products', function (): void {
                ($this->commands['import:products'])('acme');
                expect(importedProductIds())->toBe(['KEEP']);
            });
            it('stays idempotent when the import runs twice', function (): void {
                ($this->commands['import:products'])('acme');
                ($this->commands['import:products'])('acme');
                expect(importedProductIds())->toBe(['KEEP']);
            });
            PHP,
        'it imports the products',
        'it stays idempotent when the import runs twice',
        [],
        Action::Review,
    ],
    'a string resolved once against twice through an action held in a property' => [
        <<<'PHP'
            <?php
            it('resolves multiple tags in one string', function (): void {
                expect((self::$resolve)('campaign_{name}_{date}', $this->campaign))->toBe('campaign_Summer Sale_2026-03-10');
            });
            it('resolves the same string to the same value twice', function (): void {
                $first = (self::$resolve)('campaign_{name}', $this->campaign);
                expect((self::$resolve)('campaign_{name}', $this->campaign))->toBe($first);
            });
            PHP,
        'it resolves multiple tags in one string',
        'it resolves the same string to the same value twice',
        [
            'in:function:self::$resolve#0/value:\'campaign_{name}_{date}\'',
            'in:expect:function:self::$resolve/call:toBe#0/value:\'campaign_Summer Sale_2026-03-10\'',
        ],
        Action::Review,
    ],
    'a string resolved once against twice through a nullsafe method call' => [
        <<<'PHP'
            <?php
            it('resolves multiple tags in one string', function (): void {
                expect($this->action?->handle('campaign_{name}_{date}', $this->campaign))->toBe('campaign_Summer Sale_2026-03-10');
            });
            it('resolves the same string to the same value twice', function (): void {
                $first = $this->action?->handle('campaign_{name}', $this->campaign);
                expect($this->action?->handle('campaign_{name}', $this->campaign))->toBe($first);
            });
            PHP,
        'it resolves multiple tags in one string',
        'it resolves the same string to the same value twice',
        [
            'in:call:handle#0/value:\'campaign_{name}_{date}\'',
            'in:expect:variable:this/property:action/call:handle/call:toBe#0/value:\'campaign_Summer Sale_2026-03-10\'',
        ],
        Action::Review,
    ],
    'one import against the three imports in a loop of the issue' => [
        <<<'PHP'
            <?php
            it('imports three batches in a loop', function (): void {
                foreach (range(1, 3) as $i) {
                    Batch::import();
                }
            });
            it('imports one batch', function (): void {
                Batch::import();
            });
            PHP,
        'it imports one batch',
        'it imports three batches in a loop',
        ['call:Batch::import'],
        Action::Review,
    ],
    'one imported batch against three' => [
        <<<'PHP'
            <?php
            it('imports one batch', function (): void {
                expect(import([new Batch()]))->toBe(1);
            });
            it('imports three batches', function (): void {
                expect(import([new Batch(), new Batch(), new Batch()]))->toBe(3);
            });
            PHP,
        'it imports one batch',
        'it imports three batches',
        ['in:expect:function:import/call:toBe#0/value:1'],
        Action::Review,
    ],
    'the contact of a dataset row the test reads twice against a contact a test creates once' => [
        <<<'PHP'
            <?php
            it('subscribes', function (Contact $contact): void {
                subscribe($contact);
                expect(subscribed($contact))->toBeTrue();
            })->with(['a contact' => [new Contact()]]);
            it('subscribes a contact', function (): void {
                $contact = new Contact();
                subscribe($contact);
                expect(subscribed($contact))->toBeTrue();
            });
            PHP,
        'it subscribes › a contact',
        'it subscribes a contact',
        [],
        Action::Delete,
    ],
    'the same value twice against two values' => [
        <<<'PHP'
            <?php
            it('adds one to one', function (): void {
                expect(add(1, 1))->toBe(2);
            });
            it('adds one to two', function (): void {
                expect(add(1, 2))->toBe(3);
            });
            PHP,
        'it adds one to one',
        'it adds one to two',
        ['in:function:add#1/value:1', 'in:expect:function:add/call:toBe#0/value:2'],
        Action::MergeIntoDataset,
    ],
    'the three imports in a loop of the issue against one import' => [
        <<<'PHP'
            <?php
            it('imports three batches in a loop', function (): void {
                foreach (range(1, 3) as $i) {
                    Batch::import();
                }
            });
            it('imports one batch', function (): void {
                Batch::import();
            });
            PHP,
        'it imports three batches in a loop',
        'it imports one batch',
        ['function:range', 'in:function:range#0/value:1', 'in:function:range#1/value:3', 'loop:call:Batch::import'],
        Action::Review,
    ],
    'three imports in a loop against one import' => [
        <<<'PHP'
            <?php
            it('imports three batches in a loop', function (): void {
                foreach ([1, 2, 3] as $id) {
                    Batch::import($id);
                }
                expect(imported())->toBe(3);
            });
            it('imports one batch', function (): void {
                Batch::import(1);
                expect(imported())->toBe(1);
            });
            PHP,
        'it imports three batches in a loop',
        'it imports one batch',
        ['value:list-item', 'value:1', 'value:2', 'value:3', 'loop:call:Batch::import', 'in:expect:function:imported/call:toBe#0/value:3'],
        Action::Review,
    ],
    'two contacts against the one contact of a dataset row the test reads twice' => [
        <<<'PHP'
            <?php
            it('subscribes', function (Contact $contact): void {
                subscribe($contact);
                expect(subscribed($contact))->toBeTrue();
            })->with(['a contact' => [new Contact()]]);
            it('subscribes two contacts', function (): void {
                subscribe(new Contact());
                expect(subscribed(new Contact()))->toBeTrue();
            });
            PHP,
        'it subscribes two contacts',
        'it subscribes › a contact',
        ['new:Contact'],
        Action::Review,
    ],
]);

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
    $steps = $action->steps('it covers', coveringDiffersBy: []);

    expect($steps)->toHaveCount(3)
        ->and($steps[2])->toBe(Action::VERIFY_STEP);
})->with(Action::cases());

it('asks for a review of a test without an assertion whose covering test arranges more data without literals', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('approves a domain', function (): void {
                $domain = createDomain(fake()->domainName());

                approveDomain($domain->name);

                expect($domain->refresh()->provisioning)->toBeFalse();
            });
            it('approves a domain that does not exist', function (): void {
                approveDomain(fake()->domainName());
            });
            PHP,
        linesByTest: [
            'it approves a domain' => ['src/Domains.php:5', 'src/Domains.php:6'],
            'it approves a domain that does not exist' => ['src/Domains.php:5'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it approves a domain that does not exist')
        ->and($report->candidates[0]->differsBy)->toBe([])
        ->and($report->candidates[0]->action())->toBe(Action::Review)
        ->and($report->candidates[0]->coveringTokensForReview())->toBe(['function:createDomain', 'call:refresh'])
        ->and($report->candidates[0]->steps())->toBe([
            'This test has no assertion, so it asserts only that the code under test throws no exception for its input. '
                . 'The covering test "it approves a domain" arranges the calls and values in coveringDiffersBy '
                . '(function:createDomain, call:refresh), and this test does not: '
                . 'without them, the code under test gets an empty or missing input. '
                . 'Check whether that empty or missing input is a case of its own, for example a record that does not exist; '
                . 'keep this test when it is, and delete it only when it is not.',
            Action::VERIFY_STEP,
        ]);
});

it('still deletes a test without an assertion whose covering test only asserts more about the same input', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('approves example.com', function (): void {
                $approval = approveDomain('example.com');

                expect($approval)->toBe('approved');
            });
            it('approves example.com without an error', function (): void {
                $approval = approveDomain('example.com');
            });
            PHP,
        linesByTest: [
            'it approves example.com' => ['src/Domains.php:5', 'src/Domains.php:6'],
            'it approves example.com without an error' => ['src/Domains.php:5'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it approves example.com without an error')
        ->and($report->candidates[0]->coveringDifference)->toBe(
            ['expect:function:approveDomain/call:toBe', 'function:expect', 'in:expect:function:approveDomain/call:toBe#0/value:\'approved\''],
        )
        ->and($report->candidates[0]->action())->toBe(Action::Delete);
});

it('asks for a review of a test that omits an array key its covering dataset row sets through a parameter', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('finds pipelines with domain in email', function (): void {
                $domainName = fake()->unique()->domainName();
                $senderEmail = sprintf('email@%s', $domainName);
                $manager->createAction($account->name, collect([
                    'type' => 'mail',
                    'properties' => ['senderMail' => $senderEmail],
                ]));

                expect($this->facade->findPipelinesWithDomainInEmail($account->name, $domainName))->toHaveCount(1);
            });
            it('finds pipelines with domain in email by step visibility', function (bool $hidden, int $expectedCount): void {
                $domainName = fake()->unique()->domainName();
                $manager->createAction($account->name, collect([
                    'type' => 'mail',
                    'hidden' => $hidden,
                    'properties' => ['senderMail' => sprintf('email@%s', $domainName)],
                ]));

                expect($this->facade->findPipelinesWithDomainInEmail($account->name, $domainName))->toHaveCount($expectedCount);
            })->with([
                'hidden step' => [true, 0],
                'visible step' => [false, 1],
            ]);
            PHP,
        linesByTest: [
            'it finds pipelines with domain in email' => ['src/Pipelines.php:5'],
            'it finds pipelines with domain in email by step visibility › visible step' => ['src/Pipelines.php:5', 'src/Pipelines.php:6'],
        ],
    );
    $coveringTest = '"it finds pipelines with domain in email by step visibility › visible step"';
    $hiddenKey = 'in:call:createAction#1/function:collect#0/key:value:\'hidden\'';

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it finds pipelines with domain in email')
        ->and($report->candidates[0]->differsBy)->toBe([])
        ->and($report->candidates[0]->action())->toBe(Action::Review)
        ->and($report->candidates[0]->coveringTokensForReview())->toBe([
            $hiddenKey,
            'in:call:createAction#1/function:collect#0/value:\'hidden\'/value:false',
        ])
        ->and($report->candidates[0]->steps())->toBe([
            sprintf(Action::MISSING_KEY_STEP, $coveringTest, $hiddenKey),
            sprintf(Action::MISSING_ARGUMENT_STEP, $coveringTest, 'in:call:createAction#1/function:collect#0/value:\'hidden\'/value:false'),
            Action::VERIFY_STEP,
        ]);
});

it('asks for a review of a test that differs only in data from a covering test that sets an array key it lacks', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('rejects a fixed type without a currency', function (): void {
                $this->post($url, [
                    'file_url' => 'import-code-only.csv',
                    'default_type' => 'fixed',
                    'default_value' => 100,
                ])->assertStatus(422)->assertJsonPath('error', $missingCurrency);
            });
            it('rejects a percentual type with a minimum spend without a currency', function (): void {
                $this->post($url, [
                    'file_url' => 'import-code-only.csv',
                    'default_type' => 'percentual',
                    'default_value' => 50,
                    'default_min_spend' => 200,
                ])->assertStatus(422)->assertJsonPath('error', $missingCurrency);
            });
            PHP,
        linesByTest: [
            'it rejects a fixed type without a currency' => ['src/Import.php:5'],
            'it rejects a percentual type with a minimum spend without a currency' => ['src/Import.php:5', 'src/Import.php:6'],
        ],
    );
    $json = decodedJson((string) json_encode($report->candidates[0]));

    expect($report->candidates)->toHaveCount(1)
        ->and(valueAt($json, 'test'))->toBe('it rejects a fixed type without a currency')
        ->and(valueAt($json, 'differsBy'))->toBe([
            'in:call:post#1/value:\'default_type\'/value:\'fixed\'',
            'in:call:post#1/value:\'default_value\'/value:100',
        ])
        ->and(valueAt($json, 'action'))->toBe('review')
        ->and(valueAt($json, 'coveringDiffersBy'))->toBe(['in:call:post#1/key:value:\'default_min_spend\''])
        ->and(valueAt($json, 'steps'))->toBe([
            sprintf(
                Action::MISSING_KEY_STEP,
                '"it rejects a percentual type with a minimum spend without a currency"',
                'in:call:post#1/key:value:\'default_min_spend\'',
            ),
            'Check whether differsBy holds an assertion the covering tests lack: another result, input class, ordering or rejection.',
            'Delete this test only when it does not; otherwise keep it.',
            Action::VERIFY_STEP,
        ]);
});

it('asks for a review of a test that differs only in data from a covering test that passes an argument it lacks', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('stores three', function (): void {
                expect(store(3))->toBeTrue();
            });
            it('stores one with two', function (): void {
                expect(store(1, 2))->toBeTrue();
            });
            PHP,
        linesByTest: [
            'it stores three' => ['src/Store.php:5'],
            'it stores one with two' => ['src/Store.php:5', 'src/Store.php:6'],
        ],
    );
    $json = decodedJson((string) json_encode($report->candidates[0]));

    expect($report->candidates)->toHaveCount(1)
        ->and(valueAt($json, 'test'))->toBe('it stores three')
        ->and(valueAt($json, 'differsBy'))->toBe(['in:function:store#0/value:3'])
        ->and(valueAt($json, 'action'))->toBe('review')
        ->and(valueAt($json, 'coveringDiffersBy'))->toBe(['in:function:store#1/value:2'])
        ->and(valueAt($json, 'steps'))->toBe([
            sprintf(Action::MISSING_ARGUMENT_STEP, '"it stores one with two"', 'in:function:store#1/value:2'),
            'Check whether differsBy holds an assertion the covering tests lack: another result, input class, ordering or rejection.',
            'Delete this test only when it does not; otherwise keep it.',
            Action::VERIFY_STEP,
        ]);
});

it('asks for a review of a test that differs only in data from a covering test that passes an argument it lacks to a nested call', function (
    string $source,
    string $missingArgument,
): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: ['it stores the short input' => ['src/Store.php:5'], 'it stores the long input' => ['src/Store.php:5', 'src/Store.php:6']],
    );
    $json = decodedJson((string) json_encode($report->candidates[0]));

    expect($report->candidates)->toHaveCount(1)
        ->and(valueAt($json, 'test'))->toBe('it stores the short input')
        ->and(valueAt($json, 'action'))->toBe('review')
        ->and(valueAt($json, 'coveringDiffersBy'))->toBe([$missingArgument])
        ->and(valueAt($json, 'steps'))->toBe([
            sprintf(Action::MISSING_ARGUMENT_STEP, '"it stores the long input"', $missingArgument),
            'Check whether differsBy holds an assertion the covering tests lack: another result, input class, ordering or rejection.',
            'Delete this test only when it does not; otherwise keep it.',
            Action::VERIFY_STEP,
        ]);
})->with([
    'call inside an array passed to a call' => [
        <<<'PHP'
            <?php
            it('stores the short input', fn () => expect(store(make(['a' => inner(3)])))->toBeTrue());
            it('stores the long input', fn () => expect(store(make(['a' => inner(1, 2)])))->toBeTrue());
            PHP,
        'in:function:store#0/function:make#0/value:\'a\'/function:inner#1/value:2',
    ],
    'call inside an array passed to a call with a literal argument' => [
        <<<'PHP'
            <?php
            it('stores the short input', function (): void {
                $this->post('/u', collect(['when' => Carbon::create(2020, 1)]))->assertOk();
            });
            it('stores the long input', function (): void {
                $this->post('/u', collect(['when' => Carbon::create(2021, 1, 15)]))->assertOk();
            });
            PHP,
        'in:call:post#1/function:collect#0/value:\'when\'/call:Carbon::create#2/value:15',
    ],
    'call inside a nested array' => [
        <<<'PHP'
            <?php
            it('stores the short input', fn () => expect(store(['a' => make(['b' => inner(3)])]))->toBeTrue());
            it('stores the long input', fn () => expect(store(['a' => make(['b' => inner(1, 2)])]))->toBeTrue());
            PHP,
        'in:function:store#0/value:\'a\'/function:make#0/value:\'b\'/function:inner#1/value:2',
    ],
]);

it('keeps a test that passes the same item several times in one list against a test that passes it once', function (
    string $source,
    array $differsBy,
    Action $action,
): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: ['it repeats the item' => ['src/Batch.php:5'], 'it passes the item once' => ['src/Batch.php:5']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it repeats the item')
        ->and($report->candidates[0]->differsBy)->toBe($differsBy)
        ->and($report->candidates[0]->action())->toBe($action);
})->with([
    'repeated literal' => [
        <<<'PHP'
            <?php
            it('repeats the item', fn () => expect(uniqueItems(['a', 'a', 'a']))->toBe(['a']));
            it('passes the item once', fn () => expect(uniqueItems(['a']))->toBe(['a']));
            PHP,
        ['in:function:uniqueItems#0/value:list-item', 'in:function:uniqueItems#0/value:\'a\''],
        Action::MergeIntoDataset,
    ],
    'repeated variable' => [
        <<<'PHP'
            <?php
            it('repeats the item', function (): void {
                $click = new PushClickTokenData('acme', $this->email, 'myapp://product/1', campaignId: 1);

                expect($this->cacheManager->claimUnregistered(collect([$click, $click, $click])))->toHaveCount(1);
            });
            it('passes the item once', function (): void {
                $clicks = collect([new PushClickTokenData('acme', $this->email, 'myapp://product/1', campaignId: 1)]);

                $claimed = $this->cacheManager->claimUnregistered($clicks);
                $this->cacheManager->releaseClaims($claimed);

                expect($this->cacheManager->claimUnregistered($clicks))->toHaveCount(1);
            });
            PHP,
        [
            'in:call:claimUnregistered#0/function:collect#0/value:list-item/element:variable:click',
            'in:call:claimUnregistered#0/function:collect#0/value:list-item',
        ],
        Action::Review,
    ],
]);

it('counts a skipped file in the summary and leaves it out of the files', function (): void {
    $json = decodedJson((string) json_encode(new AgentReport([
        FileReport::skipped('tests/OneTest.php'),
        new FileReport('tests/FooTest.php', 2, 5, [], []),
    ], 1, staleMap: null)));

    expect(valueAt($json, 'summary.testFiles'))->toBe(2)
        ->and(valueAt($json, 'summary.testCases'))->toBe(3)
        ->and(valueAt($json, 'summary.skippedFiles'))->toBe(1)
        ->and(valueAt($json, 'summary.testsWithoutSourceLines'))->toBe(0)
        ->and($json['files'])->toBe([]);
});

it('keeps a dataset row or a test whose array has its keys in another order than its covering test', function (
    string $source,
    string $candidate,
    string $covering,
    array $differsBy,
): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: [$candidate => ['src/Notifier.php:10'], $covering => ['src/Notifier.php:10', 'src/Notifier.php:20']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe($candidate)
        ->and($report->candidates[0]->differsBy)->toBe($differsBy)
        ->and($report->candidates[0]->coveringTokensForReview())->toBe([])
        ->and($report->candidates[0]->action())->toBe(Action::Review);
})->with([
    'dataset rows' => [
        <<<'PHP'
            <?php
            it('notifies', function (array $storedRows): void {
                foreach ($storedRows as $storedItemId => $name) {
                    notify($storedItemId, $name);
                }
            })->with([
                'clean row inserted before a both-sides one' => [['AA' => 'exact', ' AA ' => 'variant']],
                'clean row inserted after a both-sides one' => [[' AA ' => 'variant', 'AA' => 'exact']],
            ]);
            PHP,
        'it notifies › clean row inserted before a both-sides one',
        'it notifies › clean row inserted after a both-sides one',
        ['keys:value:\'AA\'>value:\' AA \''],
    ],
    'tests' => [
        <<<'PHP'
            <?php
            it('stores one order', fn () => store(['a' => 1, 'b' => 2]));
            it('covers', fn () => store(['b' => 2, 'a' => 1]));
            PHP,
        'it stores one order',
        'it covers',
        ['in:function:store#0/keys:value:\'a\'>value:\'b\''],
    ],
]);

it('leaves the order of the keys of a covering test out of coveringDiffersBy', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('stores two keys', fn () => store(['a' => 1, 'c' => 3]));
            it('covers', fn () => store(['a' => 1, 'b' => 2, 'c' => 3]));
            PHP,
        linesByTest: ['it stores two keys' => ['src/Store.php:10'], 'it covers' => ['src/Store.php:10', 'src/Store.php:20']],
    );

    expect($report->candidates[0]->differsBy)->toBe([])
        ->and($report->candidates[0]->coveringDifference)->toBe([
            'in:function:store#0/key:value:\'b\'',
            'in:function:store#0/value:\'b\'/value:2',
        ]);
});

it('lists no order token for a key that the covering test lacks, because the key is listed already', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('stores three keys', fn () => expect(store(['a' => 1, 'b' => 2, 'c' => 3]))->toBeTrue());
            it('stores two keys', fn () => expect(store(['a' => 1, 'b' => 2]))->toBeTrue());
            PHP,
        linesByTest: [
            'it stores three keys' => ['src/Store.php:10'],
            'it stores two keys' => ['src/Store.php:10', 'src/Store.php:20'],
        ],
    );

    expect($report->candidates[0]->testCase())->toBe('it stores three keys')
        ->and($report->candidates[0]->differsBy)->toBe([
            'in:function:store#0/key:value:\'c\'',
            'in:function:store#0/value:\'c\'/value:3',
        ])
        ->and($report->candidates[0]->action())->toBe(Action::MergeIntoDataset);
});

it('lists no order token for a key that a sibling row lacks, because the key is listed already', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('stores', fn (array $row) => expect(store($row))->toBeTrue())->with([
                'three' => [['a' => 1, 'b' => 2, 'c' => 3]],
                'two' => [['a' => 1, 'b' => 2]],
            ]);
            PHP,
        linesByTest: [
            'it stores › three' => ['src/Store.php:10'],
            'it stores › two' => ['src/Store.php:10', 'src/Store.php:20'],
        ],
    );

    expect($report->candidates[0]->testCase())->toBe('it stores › three')
        ->and($report->candidates[0]->differsBy)->not->toContain('in:function:store#0/keys:value:\'a\'>value:\'c\'')
        ->and($report->candidates[0]->action())->toBe(Action::ReviewDatasetRow);
});

it('lists the order token of a key whose pair the covering test has in another order', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('stores a key more in another order', fn () => store(['b' => 2, 'a' => 1, 'c' => 3]));
            it('stores', fn () => store(['a' => 1, 'b' => 2]));
            PHP,
        linesByTest: [
            'it stores a key more in another order' => ['src/Store.php:10'],
            'it stores' => ['src/Store.php:10', 'src/Store.php:20'],
        ],
    );

    expect($report->candidates[0]->differsBy)->toContain('in:function:store#0/keys:value:\'b\'>value:\'a\'');
});

it('keeps a dataset row or a test whose list has its elements in another order than its covering test', function (
    string $source,
    string $candidate,
    string $covering,
    array $differsBy,
): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: [$candidate => ['src/Feed.php:10'], $covering => ['src/Feed.php:10', 'src/Feed.php:20']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe($candidate)
        ->and($report->candidates[0]->differsBy)->toBe($differsBy)
        ->and($report->candidates[0]->coveringTokensForReview())->toBe([])
        ->and($report->candidates[0]->action())->toBe(Action::Review);
})->with([
    'dataset rows' => [
        <<<'PHP'
            <?php
            it('does nothing when the licence returns the stored selection', function (array $shopIds): void {
                $action->handle($shopIds);
            })->with([
                'the same selection' => [[3, 5]],
                'the same selection in another order' => [[5, 3]],
            ]);
            PHP,
        'it does nothing when the licence returns the stored selection › the same selection',
        'it does nothing when the licence returns the stored selection › the same selection in another order',
        ['in:call:handle#0/items:value:3>value:5'],
    ],
    'dataset rows of arrays' => [
        <<<'PHP'
            <?php
            it('imports the rows', function (array $rows): void {
                $importer->import($rows);
            })->with([
                'ascending ids' => [[['id' => 1], ['id' => 2]]],
                'descending ids' => [[['id' => 2], ['id' => 1]]],
            ]);
            PHP,
        'it imports the rows › ascending ids',
        'it imports the rows › descending ids',
        ['in:call:import#0/items:[\'id\' => 1]>[\'id\' => 2]'],
    ],
    'lists of lists' => [
        <<<'PHP'
            <?php
            it('validates the email in the first row', fn () => validateEmailRow([['email', 'a'], ['b', 'c']]));
            it('validates the email in the last row', fn () => validateEmailRow([['b', 'c'], ['email', 'a']]));
            PHP,
        'it validates the email in the first row',
        'it validates the email in the last row',
        ['in:function:validateEmailRow#0/items:[\'email\', \'a\']>[\'b\', \'c\']'],
    ],
    'new expressions with arguments' => [
        <<<'PHP'
            <?php
            it('sums the amounts in ascending order', fn () => total([new Money(1), new Money(2)]));
            it('sums the amounts in descending order', fn () => total([new Money(2), new Money(1)]));
            PHP,
        'it sums the amounts in ascending order',
        'it sums the amounts in descending order',
        ['in:function:total#0/items:new Money(1)>new Money(2)'],
    ],
    'operators' => [
        <<<'PHP'
            <?php
            it('joins the first name first', fn () => fullName([$first . $last, $title]));
            it('joins the title first', fn () => fullName([$title, $first . $last]));
            PHP,
        'it joins the first name first',
        'it joins the title first',
        ['in:function:fullName#0/items:$first . $last>variable:title'],
    ],
    'static calls with arguments' => [
        <<<'PHP'
            <?php
            it('sums the amounts in ascending order', fn () => total([Money::of(1), Money::of(2)]));
            it('sums the amounts in descending order', fn () => total([Money::of(2), Money::of(1)]));
            PHP,
        'it sums the amounts in ascending order',
        'it sums the amounts in descending order',
        ['in:function:total#0/items:Money::of(1)>Money::of(2)'],
    ],
    'tests' => [
        <<<'PHP'
            <?php
            it('returns the feeds of several lists in ascending order', fn () => feed([1, 2, 3]));
            it('returns the feeds of several lists in descending order', fn () => feed([3, 2, 1]));
            PHP,
        'it returns the feeds of several lists in ascending order',
        'it returns the feeds of several lists in descending order',
        [
            'in:function:feed#0/items:value:1>value:2',
            'in:function:feed#0/items:value:1>value:3',
            'in:function:feed#0/items:value:2>value:3',
        ],
    ],
]);

it('deletes a test whose list of arrays or calls is the list of its covering test written in another form', function (
    string $list,
    string $coveringList,
): void {
    $report = analyzeTokenTest(
        source: <<<PHP
            <?php
            it('imports the rows', fn () => import({$list}));
            it('imports the same rows', fn () => import({$coveringList}));
            PHP,
        linesByTest: ['it imports the rows' => ['src/Import.php:10'], 'it imports the same rows' => ['src/Import.php:10', 'src/Import.php:20']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it imports the rows')
        ->and($report->candidates[0]->differsBy)->toBe([])
        ->and($report->candidates[0]->action())->toBe(Action::Delete);
})->with([
    'a comment inside a row' => ['[[/* first */ \'id\' => 1], [\'id\' => 2]]', '[[\'id\' => 1], [\'id\' => 2]]'],
    'a constant in upper case' => ['[new Flag(TRUE), new Flag(false)]', '[new Flag(true), new Flag(false)]'],
    'a hexadecimal integer' => ['[[\'n\' => 0x10], [\'n\' => 1]]', '[[\'n\' => 16], [\'n\' => 1]]'],
    'a string in double quotes' => ['[[\'id\' => "a"], [\'id\' => \'b\']]', '[[\'id\' => \'a\'], [\'id\' => \'b\']]'],
]);

it('merges into a dataset a test whose list of arrays or calls has another value than the list of its covering test', function (
    string $source,
    string $candidate,
    string $covering,
    string $dataToken,
): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: [$candidate => ['src/Money.php:10'], $covering => ['src/Money.php:10', 'src/Money.php:20']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe($candidate)
        ->and($report->candidates[0]->differsBy)->toBe([$dataToken])
        ->and($report->candidates[0]->action())->toBe(Action::MergeIntoDataset);
})->with([
    'new expressions with another argument' => [
        <<<'PHP'
            <?php
            it('sums two amounts', fn () => total([new Money(1), new Money(2)]));
            it('sums two other amounts', fn () => total([new Money(1), new Money(3)]));
            PHP,
        'it sums two amounts',
        'it sums two other amounts',
        'in:new:Money#0/value:2',
    ],
    'rows with another value' => [
        <<<'PHP'
            <?php
            it('imports two rows', fn () => import([['id' => 1], ['id' => 2]]));
            it('imports two other rows', fn () => import([['id' => 1], ['id' => 3]]));
            PHP,
        'it imports two rows',
        'it imports two other rows',
        'in:value:\'id\'/value:2',
    ],
    'static calls with another argument' => [
        <<<'PHP'
            <?php
            it('sums two amounts', fn () => total([Money::of(1), Money::of(2)]));
            it('sums two other amounts', fn () => total([Money::of(1), Money::of(3)]));
            PHP,
        'it sums two amounts',
        'it sums two other amounts',
        'in:call:Money::of#0/value:2',
    ],
]);

it('reviews a test whose list has the elements of the list of its covering test in another order and another value differs', function (
    string $source,
    string $orderToken,
): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: ['it handles the list' => ['src/List.php:10'], 'it handles the other list' => ['src/List.php:10', 'src/List.php:20']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it handles the list')
        ->and($report->candidates[0]->differsBy)->toContain($orderToken)
        ->and($report->candidates[0]->action())->toBe(Action::Review);
})->with([
    'another argument next to the rows' => [
        <<<'PHP'
            <?php
            it('handles the list', fn () => import([['id' => 1], ['id' => 2]], 'a'));
            it('handles the other list', fn () => import([['id' => 2], ['id' => 1]], 'b'));
            PHP,
        'in:function:import#0/items:[\'id\' => 1]>[\'id\' => 2]',
    ],
    'another argument of another call' => [
        <<<'PHP'
            <?php
            it('handles the list', function (): void {
                store([3, 5]);
                flag(true);
            });
            it('handles the other list', function (): void {
                store([5, 3]);
                flag(false);
            });
            PHP,
        'in:function:store#0/items:value:3>value:5',
    ],
    'another element next to the swapped elements' => [
        <<<'PHP'
            <?php
            it('handles the list', fn () => store([1, 2, 4]));
            it('handles the other list', fn () => store([2, 1, 3]));
            PHP,
        'in:function:store#0/items:value:1>value:2',
    ],
    'another expected value' => [
        <<<'PHP'
            <?php
            it('handles the list', fn () => expect(sortIds([3, 5]))->toBe('x'));
            it('handles the other list', fn () => expect(sortIds([5, 3]))->toBe('y'));
            PHP,
        'in:function:sortIds#0/items:value:3>value:5',
    ],
]);

it('keeps the dataset rows that pass the same list in another order than the row that covers them', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('passes when the email column is present', function (array $rows): void {
                validateEmailColumn($rows);
            })->with([
                'first column' => [[['email', 'a', 'b']]],
                'last column' => [[['a', 'b', 'email']]],
                'middle column' => [[['a', 'email', 'b']]],
            ]);
            PHP,
        linesByTest: [
            'it passes when the email column is present › first column' => ['src/Validator.php:10'],
            'it passes when the email column is present › last column' => ['src/Validator.php:10'],
            'it passes when the email column is present › middle column' => ['src/Validator.php:10', 'src/Validator.php:20'],
        ],
    );

    expect(array_map(
        static fn (Candidate $candidate): array => [$candidate->testCase(), $candidate->differsBy, $candidate->action()],
        $report->candidates,
    ))->toBe([
        ['it passes when the email column is present › first column', ['items:value:\'email\'>value:\'a\''], Action::Review],
        ['it passes when the email column is present › last column', ['items:value:\'b\'>value:\'email\''], Action::Review],
    ]);
});

it('lists no order token for a list element that the covering test lacks, because the element is listed already', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('stores three ids', fn () => expect(store([1, 2, 3]))->toBeTrue());
            it('stores two ids', fn () => expect(store([1, 2]))->toBeTrue());
            PHP,
        linesByTest: [
            'it stores three ids' => ['src/Store.php:10'],
            'it stores two ids' => ['src/Store.php:10', 'src/Store.php:20'],
        ],
    );

    expect($report->candidates[0]->testCase())->toBe('it stores three ids')
        ->and($report->candidates[0]->differsBy)->toBe(['in:function:store#0/value:list-item', 'in:function:store#0/value:3'])
        ->and($report->candidates[0]->action())->toBe(Action::MergeIntoDataset);
});

it('leaves the order of the list elements of a covering test out of coveringDiffersBy', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('stores two ids', fn () => store([1, 3]));
            it('covers', fn () => store([1, 2, 3]));
            PHP,
        linesByTest: ['it stores two ids' => ['src/Store.php:10'], 'it covers' => ['src/Store.php:10', 'src/Store.php:20']],
    );

    expect($report->candidates[0]->differsBy)->toBe([])
        ->and($report->candidates[0]->coveringDifference)->toBe(['in:function:store#0/value:list-item', 'in:function:store#0/value:2']);
});

it('reviews a test that arranges the records of its covering test in another order', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('keeps only the records above the compared float', function (): void {
                $matching = Model::factory()->create(['c_fields' => json_encode(10.5)]);
                $withNull = Model::factory()->create(['c_fields' => null]);

                expect(Model::query()->aboveFloat(10.0)->pluck('id')->all())->toContain($matching->id)->not->toContain($withNull->id);
            });
            it('excludes null values regardless of the insertion order', function (): void {
                $withNull = Model::factory()->create(['c_fields' => null]);
                $matching = Model::factory()->create(['c_fields' => json_encode(10.5)]);

                expect(Model::query()->aboveFloat(10.0)->pluck('id')->all())->toContain($matching->id)->not->toContain($withNull->id);
            });
            PHP,
        linesByTest: [
            'it keeps only the records above the compared float' => ['src/Scope.php:10'],
            'it excludes null values regardless of the insertion order' => ['src/Scope.php:10', 'src/Scope.php:20'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it keeps only the records above the compared float')
        ->and($report->candidates[0]->differsBy)->toBe([
            'calls:Model::factory()->create([\'c_fields\' => json_encode(10.5)])>Model::factory()->create([\'c_fields\' => null])',
        ])
        ->and($report->candidates[0]->coveringTokensForReview())->toBe([])
        ->and($report->candidates[0]->action())->toBe(Action::Review);
});

it('reviews a dataset row that arranges the records of its sibling row in another order', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('stores', function (int $first, int $second): void {
                store($first);
                store($second);
            })->with([
                'ascending' => [1, 2],
                'descending' => [2, 1],
            ]);
            PHP,
        linesByTest: ['it stores › ascending' => ['src/Store.php:10'], 'it stores › descending' => ['src/Store.php:10', 'src/Store.php:20']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->differsBy)->toBe(['calls:store(1)>store(2)'])
        ->and($report->candidates[0]->action())->toBe(Action::Review);
});

it('deletes a test that arranges the records of its covering test in the same order under other variable names', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('keeps only the records above the compared float', function (): void {
                $matching = Model::factory()->create(['c_fields' => json_encode(10.5)]);
                $withNull = Model::factory()->create(['c_fields' => null]);

                expect(Model::query()->aboveFloat(10.0)->count())->toBe(1);
            });
            it('keeps only the records above the compared float again', function (): void {
                $first = Model::factory()->create(['c_fields' => json_encode(10.5)]);
                $second = Model::factory()->create(['c_fields' => null]);

                expect(Model::query()->aboveFloat(10.0)->count())->toBe(1);
            });
            PHP,
        linesByTest: [
            'it keeps only the records above the compared float' => ['src/Scope.php:10'],
            'it keeps only the records above the compared float again' => ['src/Scope.php:10', 'src/Scope.php:20'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->differsBy)->toBe([])
        ->and($report->candidates[0]->action())->toBe(Action::Delete);
});

it('lists no order token for an arranging call that the covering test lacks, because the call is listed already', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('stores another record', function (): void {
                store(1);
                store(3);
            });
            it('stores two records', function (): void {
                store(1);
                store(2);
            });
            PHP,
        linesByTest: ['it stores another record' => ['src/Store.php:10'], 'it stores two records' => ['src/Store.php:10', 'src/Store.php:20']],
    );

    expect($report->candidates[0]->testCase())->toBe('it stores another record')
        ->and($report->candidates[0]->differsBy)->toBe(['in:function:store#0/value:3'])
        ->and($report->candidates[0]->action())->toBe(Action::MergeIntoDataset);
});

it('lists a covering argument whose string has the prefix of an order token in it', function (string $path): void {
    $report = analyzeTokenTest(
        source: <<<PHP
            <?php
            it('fetches all', fn () => fetchAll('x'));
            it('fetches all from a path', fn () => fetchAll('x', '{$path}'));
            PHP,
        linesByTest: ['it fetches all' => ['src/Api.php:10'], 'it fetches all from a path' => ['src/Api.php:10', 'src/Api.php:20']],
    );

    expect($report->candidates[0]->testCase())->toBe('it fetches all')
        ->and($report->candidates[0]->coveringDifference)->toBe(['in:function:fetchAll#1/value:\'' . $path . '\''])
        ->and($report->candidates[0]->action())->toBe(Action::Review);
})->with([
    'calls' => ['/v1/calls:list'],
    'items' => ['/v1/items:batchGet'],
    'keys' => ['/v1/keys:lookup'],
]);

const SHARED_ROW_STEP = 'This row belongs to the named dataset "collidingRows", which these tests also use: "it second", "it third". '
    . 'Deleting the row deletes it from each of them. '
    . 'Delete the row only when each of them stays covered without it; otherwise keep it, or give this test its own dataset.';

it('reviews, and does not delete, a row of a named dataset that other tests also use', function (): void {
    $delete = sharedRow(['it first › two'], []);

    expect($delete->action())->toBe(Action::Review)
        ->and($delete->steps())->toBe([
            SHARED_ROW_STEP,
            'Confirm that "it first › two" together assert the same input and result.',
            Action::VERIFY_STEP,
        ]);
});

it('names the tests that use the dataset in the steps of every other action for a dataset row', function (
    Candidate $candidate,
    Action $action,
    array $steps,
): void {
    expect($candidate->action())->toBe($action)
        ->and($candidate->steps())->toBe($steps);
})->with([
    'a covering test that sets a key the row lacks' => [
        sharedRow(['it first › two'], [], coveringDifference: ['in:function:store#0/key:value:\'b\'']),
        Action::Review,
        [
            SHARED_ROW_STEP,
            sprintf(Action::MISSING_KEY_STEP, '"it first › two"', 'in:function:store#0/key:value:\'b\''),
            Action::VERIFY_STEP,
        ],
    ],
    'a row that could not be parsed' => [
        sharedRow(['it first › two'], differsBy: null),
        Action::Review,
        [
            'This test or its dataset row could not be parsed; compare it with coveredBy by hand.',
            SHARED_ROW_STEP,
            'Check whether differsBy holds an assertion the covering tests lack: another result, input class, ordering or rejection.',
            'Delete this test only when it does not; otherwise keep it.',
            Action::VERIFY_STEP,
        ],
    ],
    'data that a sibling row lacks' => [
        sharedRow(['it first › two'], ['value:1']),
        Action::ReviewDatasetRow,
        [
            SHARED_ROW_STEP,
            'Compare this row with "it first › two".',
            'Delete the row when its input is the same class; keep it for an empty, boundary, invalid or other-tenant input.',
            Action::VERIFY_STEP,
        ],
    ],
    'lines that only the union of the covering tests runs' => [
        sharedRow(['it first › two', 'it third'], [], unionOnlyLines: ['src/A.php:3']),
        Action::Review,
        [
            SHARED_ROW_STEP,
            sprintf(Action::UNION_ONLY_LINES_STEP, '"it first › two", "it third"'),
            Action::VERIFY_STEP,
        ],
    ],
]);

it('keeps the action of a dataset row that no other test shares', function (): void {
    $candidate = new Candidate('tests/FooTest.php :: it first › one', ['it first › two'], [], [], [], assertsNothing: false);

    expect($candidate->action())->toBe(Action::Delete)
        ->and($candidate->steps()[1])->toBe('Delete this test.');
});

it('reports a row of a named dataset that another test uses as review, not delete, and names that test', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            dataset('collidingRows', ['one' => [1], 'two' => [1]]);

            it('first', fn (int $row) => expect(store($row))->toBeTrue())->with('collidingRows');
            it('second', fn (int $row) => expect(drop($row))->toBeTrue())->with('collidingRows');
            PHP,
        linesByTest: [
            'it first › one' => ['src/Store.php:5'],
            'it first › two' => ['src/Store.php:5'],
            'it second › one' => ['src/Drop.php:5'],
            'it second › two' => ['src/Drop.php:5'],
        ],
    );

    expect(array_map(static fn (Candidate $candidate): string => $candidate->testCase(), $report->candidates))
        ->toBe(['it first › one', 'it second › one'])
        ->and($report->candidates[0]->differsBy)->toBe([])
        ->and($report->candidates[0]->action())->toBe(Action::Review)
        ->and($report->candidates[0]->steps()[0])->toContain('"collidingRows"', '"it second"')
        ->and($report->candidates[1]->steps()[0])->toContain('"it first"');
});

it('reports a row of a named dataset that another test combines with a dataset of its own as review, not delete', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            dataset('collidingRows', ['one' => [1], 'two' => [1]]);
            dataset('sizes', ['small' => [1]]);

            it('first', fn (int $row) => expect(store($row))->toBeTrue())->with('collidingRows');
            it('second', fn (int $size, int $row) => expect(drop($size, $row))->toBeTrue())->with('sizes')->with('collidingRows');
            PHP,
        linesByTest: [
            'it first › one' => ['src/Store.php:5'],
            'it first › two' => ['src/Store.php:5'],
            'it second › small' => ['src/Drop.php:5'],
        ],
    );

    expect($report->candidates[0]->testCase())->toBe('it first › one')
        ->and($report->candidates[0]->action())->toBe(Action::Review)
        ->and($report->candidates[0]->steps()[0])->toContain('"collidingRows"', '"it second"');
});

it('reports a row of a named dataset that another test passes as a later argument of one with call as review, not delete', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            dataset('collidingRows', ['one' => [1], 'two' => [1]]);
            dataset('sizes', ['small' => [1]]);

            it('first', fn (int $row) => expect(store($row))->toBeTrue())->with('collidingRows');
            it('second', fn (int $size, int $row) => expect(drop($size, $row))->toBeTrue())->with('sizes', 'collidingRows');
            PHP,
        linesByTest: [
            'it first › one' => ['src/Store.php:5'],
            'it first › two' => ['src/Store.php:5'],
            'it second › small' => ['src/Drop.php:5'],
        ],
    );

    expect($report->candidates[0]->testCase())->toBe('it first › one')
        ->and($report->candidates[0]->action())->toBe(Action::Review)
        ->and($report->candidates[0]->steps()[0])->toContain('"collidingRows"', '"it second"');
});

it('reports a test that passes the values of a row as delete, covered by that row', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP_SOURCE'
            <?php
            it('matches on the plain birthday field name', function (): void {
                expect(birthdaysOn('2024-06-15'))->toHaveCount(2);
            });

            it('matches the expected number of records for a static date', function (string $date, int $expectedCount): void {
                expect(birthdaysOn($date))->toHaveCount($expectedCount);
            })->with([
                'June 15th' => ['2024-06-15', 2],
                'December 25th' => ['2024-12-25', 1],
            ]);
            PHP_SOURCE,
        linesByTest: [
            'it matches on the plain birthday field name' => ['src/Birthdays.php:5'],
            'it matches the expected number of records for a static date › June 15th' => ['src/Birthdays.php:5'],
            'it matches the expected number of records for a static date › December 25th' => ['src/Birthdays.php:5'],
        ],
    );

    $plain = $report->candidates[0];

    expect($plain->testCase())->toBe('it matches on the plain birthday field name')
        ->and($plain->coveredBy)->toBe(['it matches the expected number of records for a static date › June 15th'])
        ->and($plain->differsBy)->toBe([])
        ->and($plain->action())->toBe(Action::Delete)
        ->and($report->candidates[1]->coveredBy)->toBe(['it matches the expected number of records for a static date › December 25th'])
        ->and($report->candidates[1]->action())->toBe(Action::ReviewDatasetRow);
});

it('reports a test that passes the values of a kept row as delete, covered by that row and not by another row', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP_SOURCE'
            <?php
            it('matches on the plain birthday field name', function (): void {
                expect(birthdaysOn('2024-06-15'))->toHaveCount(2);
            });

            it('matches the expected number of records for a static date', function (string $date, int $expectedCount): void {
                expect(birthdaysOn($date))->toHaveCount($expectedCount);
            })->with([
                'June 15th' => ['2024-06-15', 2],
                'December 25th' => ['2024-12-25', 1],
            ]);
            PHP_SOURCE,
        linesByTest: [
            'it matches on the plain birthday field name' => ['src/Birthdays.php:5'],
            'it matches the expected number of records for a static date › December 25th' => ['src/Birthdays.php:5', 'src/Birthdays.php:9'],
            'it matches the expected number of records for a static date › June 15th' => ['src/Birthdays.php:5', 'src/Birthdays.php:7'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->coveredBy)->toBe(['it matches the expected number of records for a static date › June 15th'])
        ->and($report->candidates[0]->action())->toBe(Action::Delete);
});

it('asks for a review of a test whose covering test arranges a call or an argument position that the test lacks', function (
    string $source,
    array $coveringDiffersBy,
): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: ['it covers the plain case' => ['src/Handler.php:5'], 'it covers the arranged case' => ['src/Handler.php:5', 'src/Handler.php:6']],
    );
    $json = decodedJson((string) json_encode($report->candidates[0]));

    expect($report->candidates)->toHaveCount(1)
        ->and(valueAt($json, 'test'))->toBe('it covers the plain case')
        ->and(valueAt($json, 'differsBy'))->toBe([])
        ->and(valueAt($json, 'action'))->toBe('review')
        ->and(valueAt($json, 'coveringDiffersBy'))->toBe($coveringDiffersBy);
})->with([
    'argument position of a call that the covering test also reads in an assertion' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                $repo->restore($id);

                expect($repo->exists($id))->toBeTrue();
            });
            it('covers the arranged case', function (): void {
                $repo->restore($id, $user);

                expect($repo->exists($id))->toBeTrue();
                expect($repo->restore($id))->toBeFalse();
            });
            PHP_SOURCE,
        ['in:call:restore#1/non-literal'],
    ],
    'call made more times' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                streamOutput();

                expect(level())->toBe(0);
            });
            it('covers the arranged case', function (): void {
                ob_start();
                ob_start();
                echo 'streamed';
                streamOutput();

                expect(level())->toBe(0);
            });
            PHP_SOURCE,
        ['function:ob_start'],
    ],
    'call read in an assertion in a loop with a non-literal argument' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                handle($event);
            });
            it('covers the arranged case', function (): void {
                handle($event);

                foreach ($users as $user) {
                    expect(roleOf($user))->toBe('admin');
                }
            });
            PHP_SOURCE,
        ['loop:function:roleOf'],
    ],
    'call read in an assertion with a non-literal argument' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                handle($event);
            });
            it('covers the arranged case', function (): void {
                handle($event);

                expect(roleOf($user))->toBe('admin');
            });
            PHP_SOURCE,
        ['function:roleOf'],
    ],
    'extra argument held in a variable that holds a call' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                $logged = logEvent($account, $campaign, $contact);

                expect($logged)->toBeTrue();
            });
            it('covers the arranged case', function (): void {
                $url = fake()->url();
                $logged = logEvent($account, $campaign, $contact, $url);

                expect($logged)->toBeTrue();
            });
            PHP_SOURCE,
        ['in:function:logEvent#3/non-literal', 'call:url', 'function:fake'],
    ],
    'extra argument that reads a property' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                $counted = recalculate($job, 5);

                expect($counted)->toBe(1);
            });
            it('covers the arranged case', function (): void {
                $counted = recalculate($job, 5, $campaign->id);

                expect($counted)->toBe(1);
            });
            PHP_SOURCE,
        ['in:function:recalculate#2/non-literal'],
    ],
    'extra arrangement call' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                $domain = createDomain();

                expect(deleteDomain($domain))->toBeTrue();
            });
            it('covers the arranged case', function (): void {
                $domain = createDomain();
                createVerifiedDomain();

                expect(deleteDomain($domain))->toBeTrue();
            });
            PHP_SOURCE,
        ['function:createVerifiedDomain'],
    ],
    'mock that throws' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                $mail = Mockery::mock(MailService::class);
                $mail->shouldReceive('send')->once();
                app()->instance(MailService::class, $mail);

                handle($event);

                expect(activeState())->toBe(0);
            });
            it('covers the arranged case', function (): void {
                $mail = Mockery::mock(MailService::class);
                $mail->shouldReceive('send')->once()->andThrow(new InvalidDomain());
                app()->instance(MailService::class, $mail);

                handle($event);

                expect(activeState())->toBe(0);
            });
            PHP_SOURCE,
        ['call:andThrow', 'new:InvalidDomain'],
    ],
]);

it('asks for a review of a test whose argument holds a property that the covering test wraps in a call', function (string $source): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: ['it covers the plain case' => ['src/Handler.php:5'], 'it covers the arranged case' => ['src/Handler.php:5', 'src/Handler.php:6']],
    );
    $json = decodedJson((string) json_encode($report->candidates[0]));

    expect($report->candidates)->toHaveCount(1)
        ->and(valueAt($json, 'test'))->toBe('it covers the plain case')
        ->and(valueAt($json, 'differsBy'))->toBe(
            ['in:function:keepSubscribed#0/function:collect#0/value:list-item/element:variable:subscriber/property:email'],
        )
        ->and(valueAt($json, 'action'))->toBe('review');
})->with([
    'wrapping call' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                $kept = keepSubscribed(collect([$subscriber->email]));

                expect($kept)->toBeTrue();
            });
            it('covers the arranged case', function (): void {
                $kept = keepSubscribed(collect([strtoupper($subscriber->email)]));

                expect($kept)->toBeTrue();
            });
            PHP_SOURCE,
    ],
    'wrapping call inside an expectation' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                expect(keepSubscribed(collect([$subscriber->email])))->toBeTrue();
            });
            it('covers the arranged case', function (): void {
                expect(keepSubscribed(collect([strtoupper($subscriber->email)])))->toBeTrue();
            });
            PHP_SOURCE,
    ],
]);

it('still deletes a test whose covering test only reads a call in an assertion it adds', function (string $assertion): void {
    $report = analyzeTokenTest(
        source: sprintf(<<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                handle($event);

                expect($event->handled)->toBeTrue();
            });
            it('covers the asserting case', function (): void {
                handle($event);

                expect($event->handled)->toBeTrue();
                %s
            });
            PHP_SOURCE, $assertion),
        linesByTest: ['it covers the plain case' => ['src/Handler.php:5'], 'it covers the asserting case' => ['src/Handler.php:5', 'src/Handler.php:6']],
    );
    $json = decodedJson((string) json_encode($report->candidates[0]));

    expect($report->candidates)->toHaveCount(1)
        ->and(valueAt($json, 'test'))->toBe('it covers the plain case')
        ->and(valueAt($json, 'action'))->toBe('delete')
        ->and(valueAt($json, 'coveringDiffersBy'))->toBe([]);
})->with([
    'function call passed to a negated expectation' => ['expect(activeState())->not->toBe(1);'],
    'function call passed to a PHPUnit assertion' => ['$this->assertNotSame(1, activeState());'],
    'function call passed to expect' => ['expect(activeState())->toBe(0);'],
    'function call with an argument passed to expect' => ['expect(roleOf($user))->toBe(\'admin\');'],
    'function call with an argument passed to expect in a loop' => [
        'foreach ($users as $user) { expect(roleOf($user))->toBe(\'admin\'); }',
    ],
    'method call on a receiver passed to expect' => ['expect($user->fresh()->name)->toBe(\'x\');'],
    'method call passed to a PHPUnit assertion' => ['$this->assertSame(5, $order->total());'],
    'method chain passed to and' => ['expect($event)->and($list->items()->count())->toBe(2);'],
]);

it('still deletes a test whose covering test makes the same calls with the same variables and properties at the same positions', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                $domain = createDomain();
                $counted = recalculate($job, $campaign->id);

                expect($counted)->toBe(1);
            });
            it('covers the arranged case', function (): void {
                $domain = createDomain();
                $counted = recalculate($job, $campaign->id);

                expect($counted)->toBe(1);
                expect($counted)->not->toBeNull();
            });
            PHP_SOURCE,
        linesByTest: ['it covers the plain case' => ['src/Handler.php:5'], 'it covers the arranged case' => ['src/Handler.php:5', 'src/Handler.php:6']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->differsBy)->toBe([])
        ->and($report->candidates[0]->action())->toBe(Action::Delete);
});

it('asks for a review of a test that passes a non-literal argument its covering test lacks', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP_SOURCE'
            <?php
            it('covers the arranged case', function (): void {
                $counted = recalculate($job, 5, $campaign->id);

                expect($counted)->toBe(1);
            });
            it('covers the plain case', function (): void {
                $counted = recalculate($job, 5);

                expect($counted)->toBe(1);
                expect($job->refresh())->not->toBeNull();
            });
            PHP_SOURCE,
        linesByTest: ['it covers the arranged case' => ['src/Handler.php:5'], 'it covers the plain case' => ['src/Handler.php:5', 'src/Handler.php:6']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it covers the arranged case')
        ->and($report->candidates[0]->differsBy)->toBe(
            ['in:function:recalculate#2/non-literal', 'in:function:recalculate#2/variable:campaign/property:id'],
        )
        ->and($report->candidates[0]->action())->toBe(Action::Review);
});

it('names the arrangement the covering test adds to a test that asserts something', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                $domain = createDomain();

                expect(deleteDomain($domain))->toBeTrue();
            });
            it('covers the arranged case', function (): void {
                $domain = createDomain();
                createVerifiedDomain();

                expect(deleteDomain($domain))->toBeTrue();
            });
            PHP_SOURCE,
        linesByTest: ['it covers the plain case' => ['src/Handler.php:5'], 'it covers the arranged case' => ['src/Handler.php:5', 'src/Handler.php:6']],
    );

    expect($report->candidates[0]->steps())->toBe([
        sprintf(Action::MISSING_CALL_STEP, '"it covers the arranged case"', 'function:createVerifiedDomain'),
        Action::VERIFY_STEP,
    ]);
});

it('still merges into a test that takes its argument as a parameter a test with a literal at that position', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP_SOURCE'
            <?php
            it('stores five', function (): void {
                expect(store(5))->toBeTrue();
            });
            it('stores a number', function (int $number): void {
                expect(store($number))->toBeTrue();
            })->with('numbers');
            PHP_SOURCE,
        linesByTest: ['it stores five' => ['src/Store.php:5'], 'it stores a number' => ['src/Store.php:5', 'src/Store.php:6']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it stores five')
        ->and($report->candidates[0]->differsBy)->toBe(['in:function:store#0/value:5'])
        ->and($report->candidates[0]->coveringTokensForReview())->toBe([])
        ->and($report->candidates[0]->action())->toBe(Action::MergeIntoDataset);
});

it('asks for a review of a test that differs only in data from a covering test that passes a parameter at a position it lacks', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP_SOURCE'
            <?php
            it('stores five', function (): void {
                expect(store(5))->toBeTrue();
            });
            it('stores a number with a limit', function (int $number, int $limit): void {
                expect(store($number, $limit))->toBeTrue();
            })->with('numbers');
            PHP_SOURCE,
        linesByTest: ['it stores five' => ['src/Store.php:5'], 'it stores a number with a limit' => ['src/Store.php:5', 'src/Store.php:6']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->coveringTokensForReview())->toBe(['in:function:store#1/non-literal'])
        ->and($report->candidates[0]->action())->toBe(Action::Review);
});

it('asks for a review of a test that passes another variable or property to the code under test than its covering test', function (
    string $source,
    array $differsBy,
): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: ['it covers the plain case' => ['src/Builder.php:5'], 'it covers the other case' => ['src/Builder.php:5', 'src/Builder.php:6']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it covers the plain case')
        ->and($report->candidates[0]->differsBy)->toBe($differsBy)
        ->and($report->candidates[0]->action())->toBe(Action::Review);
})->with([
    'another property' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                expect(connect($this->enShop))->toBe(2);
            });
            it('covers the other case', function (): void {
                expect(connect($this->mainShop))->toBe(1);
            });
            PHP_SOURCE,
        ['in:function:connect#0/variable:this/property:enShop', 'in:expect:function:connect/call:toBe#0/value:2'],
    ],
    'another property cast' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                expect(connect((int) $this->enShop))->toBe(2);
            });
            it('covers the other case', function (): void {
                expect(connect((int) $this->mainShop))->toBe(2);
            });
            PHP_SOURCE,
        ['in:function:connect#0/variable:this/property:enShop'],
    ],
    'another static property' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                expect(connect(self::$enShop))->toBe(2);
            });
            it('covers the other case', function (): void {
                expect(connect(self::$mainShop))->toBe(1);
            });
            PHP_SOURCE,
        ['in:function:connect#0/property:self::enShop', 'in:expect:function:connect/call:toBe#0/value:2'],
    ],
    'another variable' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                expect(connect($enShop))->toBe(2);
            });
            it('covers the other case', function (): void {
                expect(connect($reconnected))->toBe(1);
            });
            PHP_SOURCE,
        ['in:function:connect#0/variable:enShop', 'in:expect:function:connect/call:toBe#0/value:2'],
    ],
    'another variable in a ternary' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                expect(connect($primary ? $enShop : $fallback))->toBe(2);
            });
            it('covers the other case', function (): void {
                expect(connect($primary ? $reconnected : $fallback))->toBe(2);
            });
            PHP_SOURCE,
        ['in:function:connect#0/operator:ternary#1/operand:variable:enShop'],
    ],
]);

it('asks for a review of a test that passes a literal where its covering test passes a variable or a property', function (
    string $source,
    array $coveringTokensForReview,
): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: ['it covers the plain case' => ['src/Pipeline.php:5'], 'it covers the other case' => ['src/Pipeline.php:5', 'src/Pipeline.php:6']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->coveringTokensForReview())->toBe($coveringTokensForReview)
        ->and($report->candidates[0]->action())->toBe(Action::Review);
})->with([
    'property of a record the covering test creates' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                expect(dispatchBulkStep('happypet'))->toBe(1);
            });
            it('covers the other case', function (): void {
                expect(dispatchBulkStep($account->name))->toBe(2);
            });
            PHP_SOURCE,
        ['in:function:dispatchBulkStep#0/variable:account/property:name'],
    ],
    'property passed by name' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                expect(articlesFor(articleId: 5))->toHaveCount(1);
            });
            it('covers the other case', function (): void {
                expect(articlesFor(articleId: $article->post_id))->toHaveCount(2);
            });
            PHP_SOURCE,
        ['in:function:articlesFor#articleId/variable:article/property:post_id'],
    ],
]);

it('asks for a review of a test that asserts on another variable or property than its covering test', function (string $source, array $differsBy): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: ['it covers the plain case' => ['src/Store.php:5'], 'it covers the other case' => ['src/Store.php:5', 'src/Store.php:6']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it covers the plain case')
        ->and($report->candidates[0]->differsBy)->toBe($differsBy)
        ->and($report->candidates[0]->action())->toBe(Action::Review);
})->with([
    'another negated property as the expected value' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                expect(balanceOf($account))->toBe(-$account->debit);
            });
            it('covers the other case', function (): void {
                expect(balanceOf($account))->toBe(-$account->credit);
            });
            PHP_SOURCE,
        ['in:expect:function:balanceOf/call:toBe#0/operator:unaryminus#0/operand:variable:account/property:debit'],
    ],
    'another property as the expected value' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                expect($manager->connection())->toBe($this->redisConnection);
            });
            it('covers the other case', function (): void {
                expect($manager->connection())->toBe($second);
            });
            PHP_SOURCE,
        ['in:expect:variable:manager/call:connection/call:toBe#0/variable:this/property:redisConnection'],
    ],
    'another property cast as the expected string' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                expect(resolveUtm($campaign))->toBe((string) $campaign->title);
            });
            it('covers the other case', function (): void {
                expect(resolveUtm($campaign))->toBe((string) $campaign->name);
            });
            PHP_SOURCE,
        ['in:expect:function:resolveUtm/call:toBe#0/variable:campaign/property:title'],
    ],
    'another property interpolated in the expected string' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                expect(resolveUtm($campaign))->toBe("no_segment_tag_{$campaign->title}");
            });
            it('covers the other case', function (): void {
                expect(resolveUtm($campaign))->toBe("tag_{$campaign->name}");
            });
            PHP_SOURCE,
        [
            'in:expect:function:resolveUtm/call:toBe#0/operator:interpolatedstring#0/value:\'no_segment_tag_\'',
            'in:expect:function:resolveUtm/call:toBe#0/operator:interpolatedstring#1/operand:variable:campaign/property:title',
            'value:\'no_segment_tag_\'',
        ],
    ],
    'another property joined to a string as the expected value' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                expect(resolveUtm($campaign))->toBe('no_segment_tag_' . $campaign->title);
            });
            it('covers the other case', function (): void {
                expect(resolveUtm($campaign))->toBe('tag_' . $campaign->name);
            });
            PHP_SOURCE,
        [
            'in:expect:function:resolveUtm/call:toBe#0/operator:concat#0/value:\'no_segment_tag_\'',
            'in:expect:function:resolveUtm/call:toBe#0/operator:concat#1/operand:variable:campaign/property:title',
            'value:\'no_segment_tag_\'',
        ],
    ],
    'variable the covering test expects only negated' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                store($account, $canvaUserId);

                expect(linksOf($account))->toContain($canvaUserId);
            });
            it('covers the other case', function (): void {
                store($account, $canvaUserId);

                expect(linksOf($account))->toContain($otherCanvaUserId)->not->toContain($canvaUserId);
            });
            PHP_SOURCE,
        ['in:expect:function:linksOf/call:toContain#0/variable:canvaUserId'],
    ],
]);

it('asks for a review of a test whose interpolated string has another literal text than its covering test', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP_SOURCE'
            <?php
            it('greets the user', function (): void {
                expect(greet($this->user))->toBe("Hello {$this->user->name}");
            });
            it('greets the user briefly', function (): void {
                expect(greet($this->user))->toBe("Hi {$this->user->name}");
                expect(other())->toBeTrue();
            });
            PHP_SOURCE,
        linesByTest: ['it greets the user' => ['src/Greeting.php:5'], 'it greets the user briefly' => ['src/Greeting.php:5', 'src/Greeting.php:6']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it greets the user')
        ->and($report->candidates[0]->differsBy)->toBe([
            'in:expect:function:greet/call:toBe#0/operator:interpolatedstring#0/value:\'Hello \'',
            'value:\'Hello \'',
        ])
        ->and($report->candidates[0]->action())->toBe(Action::Review);
});

it('asks for a review of a test whose argument has another operator or other places of its operands than its covering test', function (
    string $source,
    string ...$differsBy,
): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: ['it covers the plain case' => ['src/Store.php:5'], 'it covers the other case' => ['src/Store.php:5', 'src/Store.php:6']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it covers the plain case')
        ->and($report->candidates[0]->differsBy)->toBe($differsBy)
        ->and($report->candidates[0]->action())->toBe(Action::Review);
})->with([
    'another binary operator' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                foo($x + 1);
                expect(true)->toBeTrue();
            });
            it('covers the other case', function (): void {
                foo($x - 1);
                expect(true)->toBeTrue();
            });
            PHP_SOURCE,
        'in:function:foo#0/operator:plus',
        'in:function:foo#0/operator:plus#0/operand:variable:x',
        'in:function:foo#0/operator:plus#1/value:1',
    ],
    'another operator between literals' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                foo(1 + 2);
                expect(true)->toBeTrue();
            });
            it('covers the other case', function (): void {
                foo(1 - 2);
                expect(true)->toBeTrue();
            });
            PHP_SOURCE,
        'in:function:foo#0/operator:plus',
        'in:function:foo#0/operator:plus#0/value:1',
        'in:function:foo#0/operator:plus#1/value:2',
    ],
    'another unary operator' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                foo(!$x);
                expect(true)->toBeTrue();
            });
            it('covers the other case', function (): void {
                foo(-$x);
                expect(true)->toBeTrue();
            });
            PHP_SOURCE,
        'in:function:foo#0/operator:booleannot',
        'in:function:foo#0/operator:booleannot#0/operand:variable:x',
    ],
    'one literal operand fewer next to a call than the covering test' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                foo(f() . 'a');
                expect(true)->toBeTrue();
            });
            it('covers the other case', function (): void {
                foo(f() . 'a' . 'b');
                expect(true)->toBeTrue();
            });
            PHP_SOURCE,
        'in:function:foo#0/operator:concat#1/value:\'a\'',
    ],
    'one literal operand fewer than the covering test' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                foo(1 + 2);
                expect(true)->toBeTrue();
            });
            it('covers the other case', function (): void {
                foo(1 + 2 + 3);
                expect(true)->toBeTrue();
            });
            PHP_SOURCE,
        'in:function:foo#0/operator:plus#0/value:1',
        'in:function:foo#0/operator:plus#1/value:2',
    ],
    'one operand fewer than the covering test' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                foo($x . $y);
                expect(true)->toBeTrue();
            });
            it('covers the other case', function (): void {
                foo($x . $y . $z);
                expect(true)->toBeTrue();
            });
            PHP_SOURCE,
        'in:function:foo#0/operator:concat#0/operand:variable:x',
        'in:function:foo#0/operator:concat#1/operand:variable:y',
    ],
    'swapped names in an interpolated string' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                foo("{$x}-{$y}");
                expect(true)->toBeTrue();
            });
            it('covers the other case', function (): void {
                foo("{$y}-{$x}");
                expect(true)->toBeTrue();
            });
            PHP_SOURCE,
        'in:function:foo#0/operator:interpolatedstring#0/operand:variable:x',
        'in:function:foo#0/operator:interpolatedstring#2/operand:variable:y',
    ],
    'swapped operands' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                foo($x . $y);
                expect(true)->toBeTrue();
            });
            it('covers the other case', function (): void {
                foo($y . $x);
                expect(true)->toBeTrue();
            });
            PHP_SOURCE,
        'in:function:foo#0/operator:concat#0/operand:variable:x',
        'in:function:foo#0/operator:concat#1/operand:variable:y',
    ],
    'swapped ternary branches' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the plain case', function (): void {
                expect(f())->toBe($c ? $x : $y);
            });
            it('covers the other case', function (): void {
                expect(f())->toBe($c ? $y : $x);
            });
            PHP_SOURCE,
        'in:expect:function:f/call:toBe#0/operator:ternary#1/operand:variable:x',
        'in:expect:function:f/call:toBe#0/operator:ternary#2/operand:variable:y',
    ],
]);

it('asks for a review of a test that spreads an argument its covering test passes plain, or the other way round', function (
    string $candidateStatement,
    string $coveringStatement,
    array $differsBy,
): void {
    $report = analyzeTokenTest(
        source: sprintf(<<<'PHP_SOURCE'
            <?php
            it('a', function (): void {
                %s
                expect(true)->toBeTrue();
            });
            it('b', function (): void {
                %s
                expect(true)->toBeTrue();
            });
            PHP_SOURCE, $candidateStatement, $coveringStatement),
        linesByTest: ['it a' => ['src/Store.php:5'], 'it b' => ['src/Store.php:5']],
    );

    expect(array_map(
        static fn (Candidate $candidate): array => [$candidate->testCase(), $candidate->differsBy, $candidate->action()],
        $report->candidates,
    ))->toBe([['it a', $differsBy, Action::Review]]);
})->with([
    'a plain variable' => [
        'foo($x);',
        'foo(...$x);',
        ['in:function:foo#0/variable:x'],
    ],
    'a spread call' => [
        'foo(...f());',
        'foo(f());',
        ['in:function:foo#0/operator:spread'],
    ],
    'a spread constant' => [
        'foo(...Foo::ITEMS);',
        'foo(Foo::ITEMS);',
        ['in:function:foo#0/operator:spread', 'in:function:foo#0/operator:spread#0/const:Foo::ITEMS'],
    ],
    'a spread list' => [
        'foo(...[1, 2]);',
        'foo([1, 2]);',
        ['in:function:foo#0/operator:spread'],
    ],
    'a spread variable' => [
        'foo(...$x);',
        'foo($x);',
        ['in:function:foo#0/operator:spread', 'in:function:foo#0/operator:spread#0/operand:variable:x'],
    ],
]);

it('asks for a review of a test that passes plain an argument with no name that its covering test spreads', function (string $argument): void {
    $report = analyzeTokenTest(
        source: sprintf(<<<'PHP_SOURCE'
            <?php
            it('a', function (): void {
                foo(%1$s);
                expect(true)->toBeTrue();
            });
            it('b', function (): void {
                foo(...%1$s);
                expect(true)->toBeTrue();
            });
            PHP_SOURCE, $argument),
        linesByTest: ['it a' => ['src/Store.php:5'], 'it b' => ['src/Store.php:5']],
    );

    expect(array_map(
        static fn (Candidate $candidate): array => [
            $candidate->testCase(),
            $candidate->action(),
            $candidate->coveringTokensForReview(),
            $candidate->steps(),
        ],
        $report->candidates,
    ))->toBe([
        [
            'it a',
            Action::Review,
            ['in:function:foo#0/operator:spread'],
            [sprintf(Action::MISSING_ARGUMENT_STEP, '"it b"', 'in:function:foo#0/operator:spread'), Action::VERIFY_STEP],
        ],
    ]);
})->with([
    'a call' => ['f()'],
    'a call with a variable argument' => ['f($x)'],
    'a constant' => ['Foo::ITEMS'],
    'a list' => ['[1, 2]'],
    'a method call' => ['$o->m()'],
    'a nested call' => ['bar(f())'],
    'a new object' => ['new Foo()'],
    'a static method call' => ['Foo::m()'],
]);

it('names a spread that only the covering test has as a missing argument only when it is not an argument of an assertion', function (
    string $candidateStatement,
    string $coveringStatement,
    string $spread,
    string $firstStep,
): void {
    $report = analyzeTokenTest(
        source: sprintf(<<<'PHP_SOURCE'
            <?php
            it('a', function (): void {
                %s
                expect(true)->toBeTrue();
            });
            it('b', function (): void {
                %s
                expect(true)->toBeTrue();
            });
            PHP_SOURCE, $candidateStatement, $coveringStatement),
        linesByTest: ['it a' => ['src/Store.php:5'], 'it b' => ['src/Store.php:5']],
    );

    expect(array_map(
        static fn (Candidate $candidate): array => [$candidate->testCase(), $candidate->coveringTokensForReview(), $candidate->steps()[0]],
        $report->candidates,
    ))->toBe([['it a', [$spread], $firstStep]]);
})->with([
    'an argument of a call' => [
        'foo(f(), 1);',
        'foo(...f(), 2);',
        'in:function:foo#0/operator:spread',
        sprintf(Action::MISSING_ARGUMENT_STEP, '"it b"', 'in:function:foo#0/operator:spread'),
    ],
    'an argument of an assertion' => [
        'expect(f())->toBe(1);',
        'expect(...f())->toBe(2);',
        'in:function:expect#0/operator:spread',
        'Check whether differsBy holds an assertion the covering tests lack: another result, input class, ordering or rejection.',
    ],
]);

it('asks to merge a test whose literal operands its covering test passes at swapped places', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP_SOURCE'
            <?php
            it('subtracts the larger amount', function (): void {
                foo(1 - 2);
            });
            it('subtracts the smaller amount', function (): void {
                foo(2 - 1);
            });
            PHP_SOURCE,
        linesByTest: ['it subtracts the larger amount' => ['src/Store.php:5'], 'it subtracts the smaller amount' => ['src/Store.php:5']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it subtracts the larger amount')
        ->and($report->candidates[0]->differsBy)->toBe(['in:function:foo#0/operator:minus#0/value:1', 'in:function:foo#0/operator:minus#1/value:2'])
        ->and($report->candidates[0]->action())->toBe(Action::MergeIntoDataset);
});

it('asks for a review of a test that passes optional arguments its covering test omits and relies on their defaults', function (
    string $source,
    string ...$differsBy,
): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: ['it covers the full case' => ['src/Data.php:5'], 'it covers the default case' => ['src/Data.php:5', 'src/Data.php:6']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it covers the full case')
        ->and($report->candidates[0]->differsBy)->toBe($differsBy)
        ->and($report->candidates[0]->coveringTokensForReview())->toBe([])
        ->and($report->candidates[0]->action())->toBe(Action::Review)
        ->and($report->candidates[0]->steps())->toBe([
            sprintf(Action::OMITTED_ARGUMENT_STEP, '"it covers the default case"', implode(', ', $differsBy)),
            ...Action::Review->steps('"it covers the default case"', []),
        ]);
})->with([
    'named arguments of a constructor' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the full case', function (): void {
                $data = new PredictedData(date: '2026-01-01', newCustomersForecastCount: 24, newCustomersAvgClv: 315.5);

                expect($data->toArray())->toBe(['count' => 24]);
            });
            it('covers the default case', function (): void {
                $data = new PredictedData(date: '2026-01-01');

                expect($data->toArray())->toBe(['count' => 24]);
            });
            PHP_SOURCE,
        'in:new:PredictedData#newCustomersForecastCount/value:24',
        'in:new:PredictedData#newCustomersAvgClv/value:315.5',
    ],
    'positional argument of a function' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the full case', function (): void {
                expect(store('john@example.com', 20, 'opened'))->toBe(1);
            });
            it('covers the default case', function (): void {
                expect(store('john@example.com', 20))->toBe(1);
            });
            PHP_SOURCE,
        'in:function:store#2/value:\'opened\'',
    ],
    'positional argument of a function called in a loop' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the full case', function (): void {
                foreach ([1, 2] as $limit) {
                    expect(store('john@example.com', $limit, 'opened'))->toBe(1);
                }
            });
            it('covers the default case', function (): void {
                foreach ([1, 2] as $limit) {
                    expect(store('john@example.com', $limit))->toBe(1);
                }
            });
            PHP_SOURCE,
        'in:function:store#2/value:\'opened\'',
    ],
    'positional argument of a variable that holds the action' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the full case', function (): void {
                expect(($action)('john@example.com', 20, 'opened'))->toBe(1);
            });
            it('covers the default case', function (): void {
                expect(($action)('john@example.com', 20))->toBe(1);
            });
            PHP_SOURCE,
        'in:function:$action#2/value:\'opened\'',
    ],
]);

it('does not name an argument of a call that the covering test never makes as omitted', function (string $source): void {
    $report = analyzeTokenTest(
        source: $source,
        linesByTest: ['it covers the full case' => ['src/Data.php:5'], 'it covers the other case' => ['src/Data.php:5', 'src/Data.php:6']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->omittedByCovering)->toBe([])
        ->and($report->candidates[0]->action())->toBe(Action::Review)
        ->and(array_any(
            $report->candidates[0]->steps(),
            static fn (string $step): bool => str_starts_with($step, 'The covering test') && str_contains($step, 'omits the arguments'),
        ))->toBeFalse();
})->with([
    'call that only the candidate makes' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the full case', function (): void {
                logIt('x');

                expect(store('a', 1))->toBe(1);
            });
            it('covers the other case', function (): void {
                expect(store('a', 2))->toBe(1);
            });
            PHP_SOURCE,
    ],
    'variable that holds another callable in the covering test' => [
        <<<'PHP_SOURCE'
            <?php
            it('covers the full case', function (): void {
                expect($sut(1))->toBe(1);
            });
            it('covers the other case', function (): void {
                expect($action(1))->toBe(1);
            });
            PHP_SOURCE,
    ],
]);

it('still merges a test into a covering test that passes the same optional arguments with other values', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP_SOURCE'
            <?php
            it('covers the full case', function (): void {
                $data = new PredictedData(date: '2026-01-01', newCustomersForecastCount: 24);

                expect($data->toArray())->toBe(['count' => 24]);
            });
            it('covers the other case', function (): void {
                $data = new PredictedData(date: '2026-01-01', newCustomersForecastCount: 30);

                expect($data->toArray())->toBe(['count' => 24]);
            });
            PHP_SOURCE,
        linesByTest: ['it covers the full case' => ['src/Data.php:5'], 'it covers the other case' => ['src/Data.php:5', 'src/Data.php:6']],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->differsBy)->toBe(['in:new:PredictedData#newCustomersForecastCount/value:24'])
        ->and($report->candidates[0]->action())->toBe(Action::MergeIntoDataset);
});

it('decides on a test whose covering test fills the differing slot of an assertion with an array that holds a runtime expression', function (
    string $expectedKey,
    Action $action,
    array $coveringDiffersBy,
): void {
    $report = analyzeTokenTest(
        source: sprintf(<<<'PHP_SOURCE'
            <?php
            it('records the custom fields of an updated contact', function (): void {
                $subscriber = Subscriber::factory()->create();

                $response = updateBulk([['email' => $subscriber->email, 'custom_fields' => ['field1' => 'value1']]]);

                expect($response->getUpdatedEmailsWithCustomFields())->toBe(%s);
            });
            it('records nothing when custom_fields is an empty array', function (): void {
                $subscriber = Subscriber::factory()->create();

                $response = updateBulk([['email' => $subscriber->email, 'custom_fields' => []]]);

                expect($response->getUpdatedEmailsWithCustomFields())->toBe([]);
            });
            PHP_SOURCE, $expectedKey),
        linesByTest: [
            'it records the custom fields of an updated contact' => ['src/Handler.php:5', 'src/Handler.php:6'],
            'it records nothing when custom_fields is an empty array' => ['src/Handler.php:5'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it records nothing when custom_fields is an empty array')
        ->and($report->candidates[0]->coveringTokensForReview())->toBe($coveringDiffersBy)
        ->and($report->candidates[0]->action())->toBe($action);
})->with([
    'literal array key' => [
        '[\'a@b.cz\' => [\'field1\' => \'value1\']]',
        Action::MergeIntoDataset,
        [],
    ],
    'property as an array key' => [
        '[$subscriber->email => [\'field1\' => \'value1\']]',
        Action::Review,
        ['in:expect:variable:response/call:getUpdatedEmailsWithCustomFields/call:toBe#0/element:variable:subscriber/property:email'],
    ],
    'property as an array value' => [
        '[\'email\' => $subscriber->email]',
        Action::Review,
        ['in:expect:variable:response/call:getUpdatedEmailsWithCustomFields/call:toBe#0/value:\'email\'/element:variable:subscriber/property:email'],
    ],
    'variable as an array key' => [
        '[$email => [\'field1\' => \'value1\']]',
        Action::Review,
        ['in:expect:variable:response/call:getUpdatedEmailsWithCustomFields/call:toBe#0/element:variable:email'],
    ],
]);

it('decides on a test by what the covering test passes in the slot that differs', function (
    string $coveringStatement,
    string $candidateStatement,
    Action $action,
): void {
    $report = analyzeTokenTest(
        source: sprintf(<<<'PHP_SOURCE'
            <?php
            it('covers the arranged case', function (): void {
                %s
            });
            it('covers the plain case', function (): void {
                %s
            });
            PHP_SOURCE, $coveringStatement, $candidateStatement),
        linesByTest: [
            'it covers the arranged case' => ['src/Handler.php:5', 'src/Handler.php:6'],
            'it covers the plain case' => ['src/Handler.php:5'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it covers the plain case')
        ->and($report->candidates[0]->action())->toBe($action);
})->with([
    'a closure under a key of a call against a literal that the covering test passes' => [
        'foo([\'k\' => bar(\'a\')]);',
        'foo([\'k\' => bar(fn () => 1)]);',
        Action::Review,
    ],
    'a list that holds a variable under a key of a call against an empty list' => [
        'foo([\'k\' => bar([$x])]);',
        'foo([\'k\' => bar([])]);',
        Action::Review,
    ],
    'an element read of a variable against a list that holds the variable' => [
        'expect(f())->toBe($x[0]);',
        'expect(f())->toBe([$x]);',
        Action::Review,
    ],
    'an element read of a variable against the variable itself' => [
        'expect(f())->toBe($rows[0]);',
        'expect(f())->toBe($rows);',
        Action::Review,
    ],
    'an element read of a variable of a call against the variable itself' => [
        'foo($map[$key]);',
        'foo($map);',
        Action::Review,
    ],
    'an element read under a key of a call against a literal' => [
        'foo([\'k\' => bar($r[\'a\'])]);',
        'foo([\'k\' => bar(\'a\')]);',
        Action::Review,
    ],
    'a property that moves from the key to the value of an assertion map' => [
        'expect(f())->toBe([$a->id => \'x\']);',
        'expect(f())->toBe([\'x\' => $a->id]);',
        Action::Review,
    ],
    'array element in an array of an assertion against an empty array' => [
        'expect(f())->toBe([$row[\'email\'] => 1]);',
        'expect(f())->toBe([]);',
        Action::Review,
    ],
    'array element in an assertion against a literal' => [
        'expect(f())->toBe($row[\'email\']);',
        'expect(f())->toBe(\'a\');',
        Action::Review,
    ],
    'array of a call that holds a property key against an empty array' => [
        'foo([$subscriber->email => 1]);',
        'foo([]);',
        Action::Review,
    ],
    'array of a call that holds a variable against a literal' => [
        'foo([\'k\' => $x, \'n\' => 1]);',
        'foo([\'k\' => \'lit\', \'n\' => 1]);',
        Action::Review,
    ],
    'a variable against a list that holds the variable in a call' => [
        'foo($x);',
        'foo([$x]);',
        Action::Review,
    ],
    'a variable against a list that holds the variable in an assertion' => [
        'expect(f())->toBe($x);',
        'expect(f())->toBe([$x]);',
        Action::Review,
    ],
    'a variable against an element read of the variable' => [
        'expect(f())->toBe($rows);',
        'expect(f())->toBe($rows[0]);',
        Action::Review,
    ],
    'a variable against the variable joined to a literal in an assertion' => [
        'expect(f())->toBe($x);',
        'expect(f())->toBe($x . \'a\');',
        Action::Review,
    ],
    'a variable as the key of a map against the variable itself' => [
        'expect(f())->toBe([$x => 1]);',
        'expect(f())->toBe($x);',
        Action::Review,
    ],
    'a variable in a list against the variable in a nested list' => [
        'expect(f())->toBe([$x]);',
        'expect(f())->toBe([[$x]]);',
        Action::Review,
    ],
    'a variable in a list against the variable itself' => [
        'expect(f())->toBe([$x]);',
        'expect(f())->toBe($x);',
        Action::Review,
    ],
    'a variable in an interpolated string of a call against the variable itself' => [
        'foo("{$x}{$y}");',
        'foo($x);',
        Action::Review,
    ],
    'a variable in an operator in a list of a call against the variable in the list' => [
        'foo([$x . $y]);',
        'foo([$x]);',
        Action::Review,
    ],
    'a variable in an operator of a call against the variable itself' => [
        'foo($x . $y);',
        'foo($x);',
        Action::Review,
    ],
    'a variable in an operator of an assertion against the variable itself' => [
        'expect(f())->toBe($x . $y);',
        'expect(f())->toBe($x);',
        Action::Review,
    ],
    'a variable in an operator under a key of a call against a literal' => [
        'foo([\'k\' => $x . \'a\']);',
        'foo([\'k\' => \'b\']);',
        Action::Review,
    ],
    'a variable in a ternary of an assertion against the variable itself' => [
        'expect(f())->toBe($c ? $x : 1);',
        'expect(f())->toBe($x);',
        Action::Review,
    ],
    'a variable in a unary operator of a call against the variable itself' => [
        'foo(-$x);',
        'foo($x);',
        Action::Review,
    ],
    'a variable that moves from the key to the value of an assertion map' => [
        'expect(f())->toBe([$x => 1]);',
        'expect(f())->toBe([1 => $x]);',
        Action::Review,
    ],
    'a variable that moves to another key of an assertion map' => [
        'expect(f())->toBe([\'a\' => $x, \'b\' => 1]);',
        'expect(f())->toBe([\'a\' => 1, \'b\' => $x]);',
        Action::Review,
    ],
    'a variable that swaps places with a literal in a list of a call' => [
        'transfer([$a, 1]);',
        'transfer([1, $a]);',
        Action::Review,
    ],
    'a variable under a key of a call against a literal' => [
        'foo([\'k\' => bar($x)]);',
        'foo([\'k\' => bar(\'a\')]);',
        Action::Review,
    ],
    'a variable under a key that contains a hash against a literal' => [
        'foo([\'a#b\' => $x]);',
        'foo([\'a#b\' => 1]);',
        Action::Review,
    ],
    'a variable under a key that is a hash against a literal' => [
        'foo([\'#\' => $x]);',
        'foo([\'#\' => 1]);',
        Action::Review,
    ],
    'a variable under a key that starts with a hash in an assertion map against a literal' => [
        'expect(f())->toBe([\'#id\' => $x]);',
        'expect(f())->toBe([\'#id\' => 1]);',
        Action::Review,
    ],
    'a variable under a key with a newline of a call against a literal' => [
        'foo(["a\nb" => bar($x)]);',
        'foo(["a\nb" => bar(\'a\')]);',
        Action::Review,
    ],
    'a variable under a nested key that contains a hash against a literal' => [
        'foo([\'a\' => [\'b#\' => $x]]);',
        'foo([\'a\' => [\'b#\' => 1]]);',
        Action::Review,
    ],
    'enum case value in an operator against a literal' => [
        'foo(Status::Active->value . \'x\');',
        'foo(\'a\' . \'x\');',
        Action::MergeIntoDataset,
    ],
    'enum case value in an operator against a runtime property' => [
        'foo($order->value . \'x\');',
        'foo(Status::Active->value . \'x\');',
        Action::Review,
    ],
    'two variables that swap keys that contain a hash' => [
        'transfer([\'f#\' => $a, \'t#\' => $b]);',
        'transfer([\'f#\' => $b, \'t#\' => $a]);',
        Action::Review,
    ],
    'two variables that swap places in a list of a call' => [
        'transfer([$a, $b]);',
        'transfer([$b, $a]);',
        Action::Review,
    ],
    'two variables that swap places in a list of an assertion' => [
        'expect(f())->toBe([$a, $b]);',
        'expect(f())->toBe([$b, $a]);',
        Action::Review,
    ],
    'two variables that swap places in a list under a key of a call' => [
        'transfer([\'k\' => [$a, $b]]);',
        'transfer([\'k\' => [$b, $a]]);',
        Action::Review,
    ],
    'two variables that swap the key and the value of a map of a call' => [
        'foo([$k => $x]);',
        'foo([$x => $k]);',
        Action::Review,
    ],
    'two variables that swap the keys of a map of a call' => [
        'transfer([\'from\' => $a, \'to\' => $b]);',
        'transfer([\'from\' => $b, \'to\' => $a]);',
        Action::Review,
    ],
    'two variables that swap the keys of an assertion map' => [
        'expect(f())->toBe([\'from\' => $a, \'to\' => $b]);',
        'expect(f())->toBe([\'from\' => $b, \'to\' => $a]);',
        Action::Review,
    ],
]);

it('reviews, and does not merge, a test that passes a value where its covering test passes a closure', function (
    string $candidateBody,
    string $coveringBody,
    string $closureToken,
): void {
    $report = analyzeTestPair($candidateBody, $coveringBody);

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it a')
        ->and($report->candidates[0]->coveringTokensForReview())->toBe([$closureToken])
        ->and($report->candidates[0]->action())->toBe(Action::Review);
})->with([
    'a closure as an item of a list argument' => [
        'foo([\'a\']);',
        'foo([fn () => 1]);',
        'in:function:foo#0/value:list-item/element:closure',
    ],
    'a closure under a key of an array argument' => [
        'foo([\'k\' => \'a\']);',
        'foo([\'k\' => fn () => 1]);',
        'in:function:foo#0/value:\'k\'/element:closure',
    ],
    'a closure under a nested key' => [
        'foo([\'k\' => [\'j\' => \'a\']]);',
        'foo([\'k\' => [\'j\' => fn () => 1]]);',
        'in:function:foo#0/value:\'k\'/value:\'j\'/element:closure',
    ],
    'an anonymous function as the argument' => ['foo(\'a\');', 'foo(function () { return 1; });', 'in:function:foo#0/closure'],
    'an arrow function as a named argument' => ['foo(cb: \'a\');', 'foo(cb: fn () => 1);', 'in:function:foo#cb/closure'],
    'an arrow function as an operand of an operator' => [
        'foo($a ?? \'a\');',
        'foo($a ?? fn () => 1);',
        'in:function:foo#0/operator:coalesce#1/operand:closure',
    ],
    'an arrow function as the argument' => ['foo(\'a\');', 'foo(fn () => 1);', 'in:function:foo#0/closure'],
    'an arrow function as the argument of a method' => ['$x->foo(\'a\');', '$x->foo(fn () => 1);', 'in:call:foo#0/closure'],
    'an arrow function as the argument of an assertion' => [
        'expect(f())->toBe(\'a\');',
        'expect(f())->toBe(fn () => 1);',
        'in:expect:function:f/call:toBe#0/closure',
    ],
    'an arrow function as the argument of a new expression' => ['new Foo(\'a\');', 'new Foo(fn () => 1);', 'in:new:Foo#0/closure'],
    'an arrow function as the second argument' => ['foo(1, \'a\');', 'foo(1, fn () => 1);', 'in:function:foo#1/closure'],
]);

it('merges into a dataset a test that passes another value where its covering test passes a closure at another position', function (): void {
    $report = analyzeTestPair('foo(fn () => 1, \'a\');', 'foo(fn () => 1, \'b\');');

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->differsBy)->toBe(['in:function:foo#1/value:\'a\''])
        ->and($report->candidates[0]->coveringTokensForReview())->toBe([])
        ->and($report->candidates[0]->action())->toBe(Action::MergeIntoDataset);
});

it('reviews, never deletes, a test that passes a closure where its covering test passes the equal literal', function (
    string $candidateBody,
    string $coveringBody,
    string $closureToken,
): void {
    $report = analyzeTestPair($candidateBody, $coveringBody);

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it a')
        ->and($report->candidates[0]->differsBy)->toBe([$closureToken])
        ->and($report->candidates[0]->action())->toBe(Action::Review);
})->with([
    'a closure as the argument of an assertion' => [
        'expect(f())->toBe(fn () => 1);',
        'expect(f())->toBe(1);',
        'in:expect:function:f/call:toBe#0/closure',
    ],
    'a closure under a key of an array argument' => [
        'foo([\'k\' => fn () => 1]);',
        'foo([\'k\' => 1]);',
        'in:function:foo#0/value:\'k\'/element:closure',
    ],
]);

it('reviews, and does not merge, a test that passes a closure where its covering test passes another value', function (
    string $candidateBody,
    string $coveringBody,
    string $closureToken,
): void {
    $report = analyzeTestPair($candidateBody, $coveringBody);

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it a')
        ->and($report->candidates[0]->differsBy)->toContain($closureToken)
        ->and($report->candidates[0]->action())->toBe(Action::Review);
})->with([
    'a closure as an operand of an operator' => [
        'foo($a ?? fn () => 2);',
        'foo($a ?? 1);',
        'in:function:foo#0/operator:coalesce#1/operand:closure',
    ],
    'a closure as the argument of an assertion' => [
        'expect(f())->toBe(fn () => 2);',
        'expect(f())->toBe(1);',
        'in:expect:function:f/call:toBe#0/closure',
    ],
    'a closure under a key of an array argument' => [
        'foo([\'k\' => fn () => 2]);',
        'foo([\'k\' => 1]);',
        'in:function:foo#0/value:\'k\'/element:closure',
    ],
]);

it('does not delete or merge a test that differs from its covering test only in a cast', function (
    string $candidateBody,
    string $coveringBody,
    array $differsBy,
    array $coveringDiffersBy,
): void {
    $report = analyzeTestPair($candidateBody, $coveringBody);

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it a')
        ->and($report->candidates[0]->differsBy)->toBe($differsBy)
        ->and($report->candidates[0]->coveringTokensForReview())->toBe($coveringDiffersBy)
        ->and($report->candidates[0]->action())->toBe(Action::Review);
})->with([
    'a cast of an array key that the covering test does not cast' => [
        'store([(string) $k => 1]);',
        'store([$k => 1]);',
        ['in:function:store#0/key:cast:string', 'in:function:store#0/cast:string/variable:k/value:1'],
        [],
    ],
    'a cast of a property to an integer where the covering test passes the property' => [
        'connect((int) $this->enShop);',
        'connect($this->enShop);',
        ['in:function:connect#0/cast:int'],
        [],
    ],
    'a cast that the covering test moves to another argument' => [
        'store((int) $a, $b);',
        'store($a, (int) $b);',
        ['in:function:store#0/cast:int'],
        [],
    ],
    'a cast that the covering test moves to another list item' => [
        'store([(object) [\'a\' => 1], [\'b\' => 2]]);',
        'store([[\'a\' => 1], (object) [\'b\' => 2]]);',
        [
            'in:cast:object/key:value:\'a\'',
            'in:cast:object/value:\'a\'/value:1',
            'key:value:\'b\'',
            'in:value:\'b\'/value:2',
        ],
        ['key:value:\'a\'', 'in:cast:object/key:value:\'b\''],
    ],
    'a cast that the covering test moves to another property argument' => [
        'connect((int) $this->a, $this->b);',
        'connect($this->a, (int) $this->b);',
        ['in:function:connect#0/cast:int'],
        [],
    ],
    'a cast to an object in a list where the covering test passes an empty list' => [
        'expect(enrich(new LengthAwarePaginator([(object) [\'code\' => null]], 1, 50)))->toBeEmpty();',
        'expect(enrich(new LengthAwarePaginator([], 0, 50)))->toBeEmpty();',
        [
            'in:function:enrich#0/new:LengthAwarePaginator#0/value:list-item',
            'in:function:enrich#0/new:LengthAwarePaginator#0/cast:object',
            'in:cast:object/key:value:\'code\'',
            'in:cast:object/value:\'code\'/value:null',
            'in:function:enrich#0/new:LengthAwarePaginator#1/value:1',
        ],
        [],
    ],
    'an array in a list where the covering test casts it to an object' => [
        'store([[\'code\' => null]]);',
        'store([(object) [\'code\' => null]]);',
        ['key:value:\'code\'', 'in:value:\'code\'/value:null'],
        ['in:function:store#0/cast:object', 'in:cast:object/key:value:\'code\''],
    ],
]);

it('merges, never deletes, a test whose cast arguments, cast subjects or cast keys hold the values of its covering test swapped', function (
    string $candidateBody,
    string $coveringBody,
    array $differsBy,
): void {
    $report = analyzeTestPair($candidateBody, $coveringBody);

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it a')
        ->and($report->candidates[0]->differsBy)->toBe($differsBy)
        ->and($report->candidates[0]->action())->toBe(Action::MergeIntoDataset);
})->with([
    'cast calls as the subjects of assertSame()' => [
        'assertSame(\'x\', (string) $r->body()); assertSame(\'y\', (string) $r->status());',
        'assertSame(\'y\', (string) $r->body()); assertSame(\'x\', (string) $r->status());',
        [
            'in:expect:cast:string/variable:r/call:body/function:assertSame#0/value:\'x\'',
            'in:expect:cast:string/variable:r/call:status/function:assertSame#0/value:\'y\'',
        ],
    ],
    'cast calls as the subjects of expect()' => [
        'expect((string) $r->body())->toBe(\'x\'); expect((string) $r->status())->toBe(\'y\');',
        'expect((string) $r->body())->toBe(\'y\'); expect((string) $r->status())->toBe(\'x\');',
        [
            'in:expect:cast:string/variable:r/call:body/call:toBe#0/value:\'x\'',
            'in:expect:cast:string/variable:r/call:status/call:toBe#0/value:\'y\'',
        ],
    ],
    'cast constants as array keys' => [
        'store([(string) Foo::A => 1, (string) Foo::B => 2]);',
        'store([(string) Foo::A => 2, (string) Foo::B => 1]);',
        ['in:function:store#0/cast:string/const:Foo::A/value:1', 'in:function:store#0/cast:string/const:Foo::B/value:2'],
    ],
    'cast literals as named arguments' => [
        'store(id: (int) \'5\', n: (int) \'6\');',
        'store(id: (int) \'6\', n: (int) \'5\');',
        ['in:function:store#id/cast:int/value:\'5\'', 'in:function:store#n/cast:int/value:\'6\''],
    ],
    'cast literals as the arguments of a new expression' => [
        'new Foo((int) \'5\', (int) \'6\');',
        'new Foo((int) \'6\', (int) \'5\');',
        ['in:new:Foo#0/cast:int/value:\'5\'', 'in:new:Foo#1/cast:int/value:\'6\''],
    ],
    'cast literals as the arguments of one call' => [
        'store((int) \'5\', (int) \'6\');',
        'store((int) \'6\', (int) \'5\');',
        ['in:function:store#0/cast:int/value:\'5\'', 'in:function:store#1/cast:int/value:\'6\''],
    ],
    'cast literals as the arguments of two calls' => [
        'store((int) \'5\'); update((int) \'6\');',
        'store((int) \'6\'); update((int) \'5\');',
        ['in:function:store#0/cast:int/value:\'5\'', 'in:function:update#0/cast:int/value:\'6\''],
    ],
    'cast literals as the expected values of assertions' => [
        'expect($r->a())->toBe((int) \'1\'); expect($r->b())->toBe((int) \'2\');',
        'expect($r->a())->toBe((int) \'2\'); expect($r->b())->toBe((int) \'1\');',
        [
            'in:expect:variable:r/call:a/call:toBe#0/cast:int/value:\'1\'',
            'in:expect:variable:r/call:b/call:toBe#0/cast:int/value:\'2\'',
        ],
    ],
]);

it('merges, never deletes, a test whose subjects without a token of their own pair the values of its covering test swapped', function (
    string $candidateBody,
    string $coveringBody,
    array $differsBy,
): void {
    $report = analyzeTestPair($candidateBody, $coveringBody);

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it a')
        ->and($report->candidates[0]->differsBy)->toBe($differsBy)
        ->and($report->candidates[0]->action())->toBe(Action::MergeIntoDataset);
})->with([
    'closures that return literals as arguments' => [
        'store(fn () => 1, fn () => 2);',
        'store(fn () => 2, fn () => 1);',
        ['in:function:store#0/value:1', 'in:function:store#1/value:2'],
    ],
    'closures with a return statement as arguments' => [
        'store(function () { return 1; }, function () { return 2; });',
        'store(function () { return 2; }, function () { return 1; });',
        ['in:function:store#0/value:1', 'in:function:store#1/value:2'],
    ],
    'constants as the subjects of expect()' => [
        'expect(Foo::A)->toBe(1); expect(Foo::B)->toBe(2);',
        'expect(Foo::A)->toBe(2); expect(Foo::B)->toBe(1);',
        ['in:expect:const:Foo::A/call:toBe#0/value:1', 'in:expect:const:Foo::B/call:toBe#0/value:2'],
    ],
    'element reads as the subjects of expect()' => [
        'expect($r[\'a\'])->toBe(1); expect($r[\'b\'])->toBe(2);',
        'expect($r[\'a\'])->toBe(2); expect($r[\'b\'])->toBe(1);',
        ['in:expect:$r[\'a\']/call:toBe#0/value:1', 'in:expect:$r[\'b\']/call:toBe#0/value:2'],
    ],
    'enum case values as the actual values of assertSame()' => [
        '$this->assertSame(1, Status::A->value); $this->assertSame(2, Status::B->value);',
        '$this->assertSame(2, Status::A->value); $this->assertSame(1, Status::B->value);',
        ['in:expect:Status::A->value/call:assertSame#0/value:1', 'in:expect:Status::B->value/call:assertSame#0/value:2'],
    ],
    'enum case values as the subjects of expect()' => [
        'expect(Status::A->value)->toBe(1); expect(Status::B->value)->toBe(2);',
        'expect(Status::A->value)->toBe(2); expect(Status::B->value)->toBe(1);',
        ['in:expect:Status::A->value/call:toBe#0/value:1', 'in:expect:Status::B->value/call:toBe#0/value:2'],
    ],
    'lists as the subjects of expect()' => [
        'expect([1])->toBe(3); expect([2])->toBe(4);',
        'expect([1])->toBe(4); expect([2])->toBe(3);',
        ['in:expect:[1]/call:toBe#0/value:3', 'in:expect:[2]/call:toBe#0/value:4'],
    ],
    'literals as the actual values of assertSame()' => [
        '$this->assertSame(5, \'5\'); $this->assertSame(6, \'6\');',
        '$this->assertSame(6, \'5\'); $this->assertSame(5, \'6\');',
        ['in:expect:value:\'5\'/call:assertSame#0/value:5', 'in:expect:value:\'6\'/call:assertSame#0/value:6'],
    ],
    'literals as the subjects of an and() chain' => [
        'expect(\'5\')->toBe(5)->and(\'6\')->toBe(6);',
        'expect(\'5\')->toBe(6)->and(\'6\')->toBe(5);',
        ['in:expect:value:\'5\'/call:toBe#0/value:5', 'in:expect:value:\'6\'/call:toBe#0/value:6'],
    ],
    'literals as the subjects of expect()' => [
        'expect(\'5\')->toBe(5); expect(\'6\')->toBe(6);',
        'expect(\'5\')->toBe(6); expect(\'6\')->toBe(5);',
        ['in:expect:value:\'5\'/call:toBe#0/value:5', 'in:expect:value:\'6\'/call:toBe#0/value:6'],
    ],
    'literals the test assigns to the subjects of expect()' => [
        '$a = 5; $b = 6; expect($a)->toBe(5); expect($b)->toBe(6);',
        '$a = 5; $b = 6; expect($a)->toBe(6); expect($b)->toBe(5);',
        ['in:expect:value:5/call:toBe#0/value:5', 'in:expect:value:6/call:toBe#0/value:6'],
    ],
    'maps as the subjects of expect()' => [
        'expect([\'a\' => 1])->toBe(3); expect([\'a\' => 2])->toBe(4);',
        'expect([\'a\' => 1])->toBe(4); expect([\'a\' => 2])->toBe(3);',
        ['in:expect:[\'a\' => 1]/call:toBe#0/value:3', 'in:expect:[\'a\' => 2]/call:toBe#0/value:4'],
    ],
    'variables as array keys' => [
        'store([$a => 1, $b => 2]);',
        'store([$a => 2, $b => 1]);',
        ['in:function:store#0/variable:a/value:1', 'in:function:store#0/variable:b/value:2'],
    ],
    'variables as the actual values of assertSame() next to a message variable' => [
        '$this->assertSame(1, $a, $message); $this->assertSame(2, $b, $message);',
        '$this->assertSame(2, $a, $message); $this->assertSame(1, $b, $message);',
        ['in:expect:variable:a/call:assertSame#0/value:1', 'in:expect:variable:b/call:assertSame#0/value:2'],
    ],
    'variables as the subjects of expect()' => [
        'expect($a)->toBe(1); expect($b)->toBe(2);',
        'expect($a)->toBe(2); expect($b)->toBe(1);',
        ['in:expect:variable:a/call:toBe#0/value:1', 'in:expect:variable:b/call:toBe#0/value:2'],
    ],
]);

it('pairs literal subjects with their values only in a test that asserts two or more of them', function (
    string $candidateBody,
    string $coveringBody,
    array $differsBy,
    Action $action,
): void {
    $report = analyzeTestPair($candidateBody, $coveringBody);

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->differsBy)->toBe($differsBy)
        ->and($report->candidates[0]->action())->toBe($action);
})->with([
    'one literal subject against another literal subject' => [
        'expect(\'7\')->toBe(7);',
        'expect(\'5\')->toBe(5);',
        ['in:function:expect#0/value:\'7\'', 'in:call:toBe#0/value:7'],
        Action::MergeIntoDataset,
    ],
    'two literal subjects against two other literal subjects' => [
        'expect(\'7\')->toBe(7); expect(\'8\')->toBe(8);',
        'expect(\'5\')->toBe(5); expect(\'6\')->toBe(6);',
        [
            'in:function:expect#0/value:\'7\'',
            'in:expect:value:\'7\'/call:toBe#0/value:7',
            'in:function:expect#0/value:\'8\'',
            'in:expect:value:\'8\'/call:toBe#0/value:8',
        ],
        Action::Review,
    ],
]);

it('reviews, never deletes, a test whose subjects differ only in an argument or a receiver and pair swapped values', function (
    string $candidateBody,
    string $coveringBody,
    array $differsBy,
): void {
    $report = analyzeTestPair($candidateBody, $coveringBody);

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it a')
        ->and($report->candidates[0]->differsBy)->toBe($differsBy)
        ->and($report->candidates[0]->action())->toBe(Action::Review);
})->with([
    'calls that encode a list with another literal' => [
        'expect(json_encode([$a, 1]))->toBe(\'x\'); expect(json_encode([$a, 2]))->toBe(\'y\');',
        'expect(json_encode([$a, 1]))->toBe(\'y\'); expect(json_encode([$a, 2]))->toBe(\'x\');',
        [
            'bound:expect:function:json_encode#0:[$a, 1]/call:toBe#0:\'x\'',
            'bound:expect:function:json_encode#0:[$a, 2]/call:toBe#0:\'y\'',
        ],
    ],
    'calls that encode a list with another variable' => [
        'expect(json_encode([$a]))->toBe(\'x\'); expect(json_encode([$b]))->toBe(\'y\');',
        'expect(json_encode([$a]))->toBe(\'y\'); expect(json_encode([$b]))->toBe(\'x\');',
        [
            'bound:expect:function:json_encode#0:value:list-item/element:variable:a/call:toBe#0:\'x\'',
            'bound:expect:function:json_encode#0:value:list-item/element:variable:b/call:toBe#0:\'y\'',
        ],
    ],
    'calls that encode another variable' => [
        'expect(json_encode($a))->toBe(\'x\'); expect(json_encode($b))->toBe(\'y\');',
        'expect(json_encode($a))->toBe(\'y\'); expect(json_encode($b))->toBe(\'x\');',
        [
            'bound:expect:function:json_encode#0:variable:a/call:toBe#0:\'x\'',
            'bound:expect:function:json_encode#0:variable:b/call:toBe#0:\'y\'',
        ],
    ],
    'calls that encode the result of a call on another variable' => [
        'expect(json_encode(bar($a)))->toBe(\'x\'); expect(json_encode(bar($b)))->toBe(\'y\');',
        'expect(json_encode(bar($a)))->toBe(\'y\'); expect(json_encode(bar($b)))->toBe(\'x\');',
        ['bound:expect:function:json_encode#0:bar($a)/call:toBe#0:\'x\'', 'bound:expect:function:json_encode#0:bar($b)/call:toBe#0:\'y\''],
    ],
    'calls that expect closures with other calls' => [
        'expect(json_encode($a))->toBe(fn () => foo()); expect(json_encode($b))->toBe(fn () => bar());',
        'expect(json_encode($a))->toBe(fn () => bar()); expect(json_encode($b))->toBe(fn () => foo());',
        [
            'bound:expect:function:json_encode#0:variable:a/call:toBe#0:fn() => foo()',
            'bound:expect:function:json_encode#0:variable:b/call:toBe#0:fn() => bar()',
        ],
    ],
    'calls that expect operands that hold closures with other calls' => [
        'expect(g($a))->toBe($c ?? fn () => foo()); expect(g($b))->toBe($c ?? fn () => bar());',
        'expect(g($a))->toBe($c ?? fn () => bar()); expect(g($b))->toBe($c ?? fn () => foo());',
        [
            'bound:expect:function:g#0:variable:a/call:toBe#0:$c ?? fn() => foo()',
            'bound:expect:function:g#0:variable:b/call:toBe#0:$c ?? fn() => bar()',
        ],
    ],
    'calls that measure other elements' => [
        'expect(strlen($r[\'a\']))->toBe(1); expect(strlen($r[\'b\']))->toBe(2);',
        'expect(strlen($r[\'a\']))->toBe(2); expect(strlen($r[\'b\']))->toBe(1);',
        ['bound:expect:function:strlen#0:$r[\'a\']/call:toBe#0:1', 'bound:expect:function:strlen#0:$r[\'b\']/call:toBe#0:2'],
    ],
    'cast elements' => [
        'expect((int) $r[\'a\'])->toBe(1); expect((int) $r[\'b\'])->toBe(2);',
        'expect((int) $r[\'a\'])->toBe(2); expect((int) $r[\'b\'])->toBe(1);',
        ['bound:expect:cast:int/$r[\'a\']/call:toBe#0:1', 'bound:expect:cast:int/$r[\'b\']/call:toBe#0:2'],
    ],
    'cast enum case reads' => [
        'expect((string) Status::A->value)->toBe(\'1\'); expect((string) Status::B->value)->toBe(\'2\');',
        'expect((string) Status::A->value)->toBe(\'2\'); expect((string) Status::B->value)->toBe(\'1\');',
        ['bound:expect:cast:string/Status::A->value/call:toBe#0:\'1\'', 'bound:expect:cast:string/Status::B->value/call:toBe#0:\'2\''],
    ],
    'cast lists' => [
        'expect((object) [1])->toEqual(1); expect((object) [2])->toEqual(2);',
        'expect((object) [1])->toEqual(2); expect((object) [2])->toEqual(1);',
        ['bound:expect:cast:object/[1]/call:toEqual#0:1', 'bound:expect:cast:object/[2]/call:toEqual#0:2'],
    ],
    'cast literals' => [
        'expect((int) \'5\')->toBe(5); expect((int) \'6\')->toBe(6);',
        'expect((int) \'5\')->toBe(6); expect((int) \'6\')->toBe(5);',
        ['bound:expect:cast:int/value:\'5\'/call:toBe#0:5', 'bound:expect:cast:int/value:\'6\'/call:toBe#0:6'],
    ],
    'cast results of methods called with other literals' => [
        'expect((int) $m->get(1))->toBe(1); expect((int) $m->get(2))->toBe(2);',
        'expect((int) $m->get(1))->toBe(2); expect((int) $m->get(2))->toBe(1);',
        ['bound:expect:cast:int/variable:m/call:get#0:1/call:toBe#0:1', 'bound:expect:cast:int/variable:m/call:get#0:2/call:toBe#0:2'],
    ],
    'closures that return calls with other literals' => [
        'expect(fn () => import(1))->toThrow(A::class); expect(fn () => import(2))->toThrow(B::class);',
        'expect(fn () => import(2))->toThrow(A::class); expect(fn () => import(1))->toThrow(B::class);',
        ['bound:expect:function:import#0:1/call:toThrow#0:A::class', 'bound:expect:function:import#0:2/call:toThrow#0:B::class'],
    ],
    'methods called on other enum cases' => [
        'expect(Status::A->label())->toBe(\'a\'); expect(Status::B->label())->toBe(\'b\');',
        'expect(Status::A->label())->toBe(\'b\'); expect(Status::B->label())->toBe(\'a\');',
        ['bound:expect:const:Status::A/call:label/call:toBe#0:\'a\'', 'bound:expect:const:Status::B/call:label/call:toBe#0:\'b\''],
    ],
    'methods called with other elements' => [
        'expect($m->get($r[\'a\']))->toBe(1); expect($m->get($r[\'b\']))->toBe(2);',
        'expect($m->get($r[\'a\']))->toBe(2); expect($m->get($r[\'b\']))->toBe(1);',
        ['bound:expect:variable:m/call:get#0:$r[\'a\']/call:toBe#0:1', 'bound:expect:variable:m/call:get#0:$r[\'b\']/call:toBe#0:2'],
    ],
]);

it('does not delete a test that passes the same properties of other or swapped objects as its covering test', function (
    string $candidateStatement,
    string $coveringStatement,
    array $differsBy,
    Action $action,
): void {
    $report = analyzeTokenTest(
        source: sprintf(<<<'PHP_SOURCE'
            <?php
            it('a', function (): void {
                %s
                expect(true)->toBeTrue();
            });
            it('b', function (): void {
                %s
                expect(true)->toBeTrue();
            });
            PHP_SOURCE, $candidateStatement, $coveringStatement),
        linesByTest: ['it a' => ['src/Transfer.php:5'], 'it b' => ['src/Transfer.php:5']],
    );

    expect(array_map(
        static fn (Candidate $candidate): array => [$candidate->testCase(), $candidate->differsBy, $candidate->action()],
        $report->candidates,
    ))->toBe([['it a', $differsBy, $action]]);
})->with([
    'an assertion on a nullsafe property of another object the test assigns once' => [
        '$x = $a?->id; expect($x)->toBe(1);',
        '$x = $b?->id; expect($x)->toBe(1);',
        ['expect:variable:a/property:id/call:toBe', 'in:expect:variable:a/property:id/call:toBe#0/value:1'],
        Action::Review,
    ],
    'an assertion on a property of another object' => [
        'expect($a->id)->toBe(1);',
        'expect($b->id)->toBe(1);',
        [
            'in:function:expect#0/variable:a/property:id',
            'expect:variable:a/property:id/call:toBe',
            'in:expect:variable:a/property:id/call:toBe#0/value:1',
        ],
        Action::Review,
    ],
    'an assertion on a static property of another class the test assigns once' => [
        '$x = A::$id; expect($x)->toBe(1);',
        '$x = B::$id; expect($x)->toBe(1);',
        ['expect:property:A::id/call:toBe', 'in:expect:property:A::id/call:toBe#0/value:1'],
        Action::Review,
    ],
    'a property of another object passed to the code under test' => [
        'transfer($a->id);',
        'transfer($b->id);',
        ['in:function:transfer#0/variable:a/property:id'],
        Action::Review,
    ],
    'nullsafe properties of two objects that swap places in a list' => [
        'transfer([$a?->id, $b?->id]);',
        'transfer([$b?->id, $a?->id]);',
        ['in:function:transfer#0/items:variable:a/property:id>variable:b/property:id'],
        Action::Review,
    ],
    'properties of two objects that swap places in a list' => [
        'transfer([$a->id, $b->id]);',
        'transfer([$b->id, $a->id]);',
        ['in:function:transfer#0/items:variable:a/property:id>variable:b/property:id'],
        Action::Review,
    ],
    'properties of two objects that swap places in a list the other way round' => [
        'transfer([$b->id, $a->id]);',
        'transfer([$a->id, $b->id]);',
        ['in:function:transfer#0/items:variable:b/property:id>variable:a/property:id'],
        Action::Review,
    ],
    'properties of two objects that swap their values as array keys' => [
        'transfer([$a->id => 1, $b->id => 2]);',
        'transfer([$a->id => 2, $b->id => 1]);',
        ['in:function:transfer#0/variable:a/property:id/value:1', 'in:function:transfer#0/variable:b/property:id/value:2'],
        Action::MergeIntoDataset,
    ],
    'properties of two objects that swap the keys of a map' => [
        'transfer([$a->id => 1, $b->id => 1]);',
        'transfer([$b->id => 1, $a->id => 1]);',
        ['in:function:transfer#0/keys:variable:a/property:id>variable:b/property:id'],
        Action::Review,
    ],
    'properties of two properties that swap places in a list' => [
        'transfer([$this->a->id, $this->b->id]);',
        'transfer([$this->b->id, $this->a->id]);',
        ['in:function:transfer#0/items:variable:this/property:a/property:id>variable:this/property:b/property:id'],
        Action::Review,
    ],
    'static properties of two classes that swap places in a list' => [
        'transfer([A::$id, B::$id]);',
        'transfer([B::$id, A::$id]);',
        ['in:function:transfer#0/items:property:A::id>property:B::id'],
        Action::Review,
    ],
]);

it('asks to add a row to the dataset of a covering dataset row, not to turn it into a dataset test', function (): void {
    $dataset = candidate('it works', ['it covers › basic'], ['value:1'])->steps();
    $plain = candidate('it works', ['it covers'], ['value:1'])->steps();

    expect($dataset[0])->toBe('Add a row with the values in differsBy to the dataset of "it covers".')
        ->and($dataset[1])->toBe('Delete this test.')
        ->and($plain[0])->toBe('Turn "it covers" into one test with a dataset and add a row with the values in differsBy.');
});

it('names the dataset of the covering row in the merge step of an analysed test', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('runs for any shape', function (string $name): void {
                expect(run($name))->toBeTrue();
            })->with([
                'basic domain' => ['example.com'],
            ]);
            it('notifies nobody when empty', function (): void {
                expect(run(''))->toBeTrue();
            });
            PHP,
        linesByTest: [
            'it notifies nobody when empty' => ['src/D.php:5'],
            'it runs for any shape › basic domain' => ['src/D.php:5'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->action())->toBe(Action::MergeIntoDataset)
        ->and($report->candidates[0]->steps()[0])
        ->toBe('Add a row with the values in differsBy to the dataset of "it runs for any shape".');
});

it('names the named dataset of the covering row and the other tests that use it in the merge step', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            dataset('shapes', ['basic domain' => ['example.com']]);

            it('runs for any shape', function (string $name): void {
                expect(run($name))->toBeTrue();
            })->with('shapes');
            it('also reads every shape', function (string $name): void {
                expect(read($name))->toBeTrue();
            })->with('shapes');
            it('also writes every shape', function (string $name): void {
                expect(write($name))->toBeTrue();
            })->with('shapes');
            it('notifies nobody when empty', function (): void {
                expect(run(''))->toBeTrue();
            });
            PHP,
        linesByTest: [
            'it notifies nobody when empty' => ['src/D.php:5'],
            'it runs for any shape › basic domain' => ['src/D.php:5'],
            'it also reads every shape › basic domain' => ['src/R.php:5'],
            'it also writes every shape › basic domain' => ['src/W.php:5'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->action())->toBe(Action::MergeIntoDataset)
        ->and($report->candidates[0]->steps())->toBe([
            'Add a row with the values in differsBy to the named dataset "shapes", '
                . 'which these tests also use: "it also reads every shape", "it also writes every shape". '
                . 'The row is added to each of them. '
                . 'Add the row only when each of them stays correct with it; otherwise give "it runs for any shape" its own dataset.',
            'Delete this test.',
            Action::VERIFY_STEP,
        ]);
});

it('names a test that combines the named dataset of the covering row with another dataset in the merge step', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            dataset('shapes', ['basic domain' => ['example.com']]);
            dataset('sizes', ['small' => [1]]);

            it('runs for any shape', function (string $name): void {
                expect(run($name))->toBeTrue();
            })->with('shapes');
            it('also reads', function (int $size, string $name): void {
                expect(read($size, $name))->toBeTrue();
            })->with('sizes')->with('shapes');
            it('notifies nobody when empty', function (): void {
                expect(run(''))->toBeTrue();
            });
            PHP,
        linesByTest: [
            'it notifies nobody when empty' => ['src/D.php:5'],
            'it runs for any shape › basic domain' => ['src/D.php:5'],
            'it also reads › small / basic domain' => ['src/R.php:5'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->action())->toBe(Action::MergeIntoDataset)
        ->and($report->candidates[0]->steps()[0])->toBe(
            sprintf(Action::ADD_SHARED_ROW_STEP, 'shapes', '"it also reads"', 'it runs for any shape'),
        );
});

it('names a test that passes the named dataset of the covering row as a later argument of one with call in the merge step', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            dataset('shapes', ['basic domain' => ['example.com']]);
            dataset('sizes', ['small' => [1]]);

            it('runs for any shape', function (string $name): void {
                expect(run($name))->toBeTrue();
            })->with('shapes');
            it('also reads', function (int $size, string $name): void {
                expect(read($size, $name))->toBeTrue();
            })->with('sizes', 'shapes');
            it('notifies nobody when empty', function (): void {
                expect(run(''))->toBeTrue();
            });
            PHP,
        linesByTest: [
            'it notifies nobody when empty' => ['src/D.php:5'],
            'it runs for any shape › basic domain' => ['src/D.php:5'],
            'it also reads › small / basic domain' => ['src/R.php:5'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->action())->toBe(Action::MergeIntoDataset)
        ->and($report->candidates[0]->steps()[0])->toBe(
            sprintf(Action::ADD_SHARED_ROW_STEP, 'shapes', '"it also reads"', 'it runs for any shape'),
        );
});

it('keeps the plain merge step for a named dataset that only the covering test uses', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            dataset('shapes', ['basic domain' => ['example.com']]);

            it('runs for any shape', function (string $name): void {
                expect(run($name))->toBeTrue();
            })->with('shapes');
            it('notifies nobody when empty', function (): void {
                expect(run(''))->toBeTrue();
            });
            PHP,
        linesByTest: [
            'it notifies nobody when empty' => ['src/D.php:5'],
            'it runs for any shape › basic domain' => ['src/D.php:5'],
        ],
    );

    expect($report->candidates[0]->action())->toBe(Action::MergeIntoDataset)
        ->and($report->candidates[0]->steps()[0])
        ->toBe('Add a row with the values in differsBy to the dataset of "it runs for any shape".');
});

it('deletes a test whose values equal a row of the covering dataset and keeps that row', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('runs for any shape', function (string $name): void {
                expect(run($name))->toBeTrue();
            })->with([
                'basic domain' => ['example.com'],
                'empty domain name' => [''],
            ]);
            it('notifies nobody when empty', function (): void {
                expect(run(''))->toBeTrue();
            });
            PHP,
        linesByTest: [
            'it runs for any shape › empty domain name' => ['src/D.php:5'],
            'it notifies nobody when empty' => ['src/D.php:5', 'src/D.php:6'],
            'it runs for any shape › basic domain' => ['src/D.php:5', 'src/D.php:6', 'src/D.php:7'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it notifies nobody when empty')
        ->and($report->candidates[0]->action())->toBe(Action::Delete)
        ->and($report->candidates[0]->coveredBy)->toBe(['it runs for any shape › empty domain name'])
        ->and($report->candidates[0]->steps())->toBe([
            sprintf(Action::CONFIRM_STEP, '"it runs for any shape › empty domain name"'),
            'Delete this test.',
            Action::VERIFY_STEP,
        ]);
});

it('covers a dataset row by an equal sibling row and keeps that sibling for the equal standalone test', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('runs for any shape', function (string $name): void {
                expect(run($name))->toBeTrue();
            })->with([
                'basic domain' => ['example.com'],
                'empty domain name' => [''],
                'blank domain name' => [''],
            ]);
            it('notifies nobody when empty', function (): void {
                expect(run(''))->toBeTrue();
            });
            PHP,
        linesByTest: [
            'it runs for any shape › empty domain name' => ['src/D.php:5'],
            'it runs for any shape › blank domain name' => ['src/D.php:5'],
            'it notifies nobody when empty' => ['src/D.php:5', 'src/D.php:6'],
            'it runs for any shape › basic domain' => ['src/D.php:5', 'src/D.php:6', 'src/D.php:7'],
        ],
    );

    expect(array_map(static fn (Candidate $candidate): string => $candidate->testCase(), $report->candidates))
        ->toBe(['it runs for any shape › empty domain name', 'it notifies nobody when empty'])
        ->and($report->candidates[0]->coveredBy)->toBe(['it runs for any shape › blank domain name'])
        ->and($report->candidates[1]->action())->toBe(Action::Delete)
        ->and($report->candidates[1]->coveredBy)->toBe(['it runs for any shape › blank domain name']);
});

it('reports a dataset row covered by a kept standalone test that runs a line of its own', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('runs for any shape', function (string $name): void {
                expect(run($name))->toBeTrue();
            })->with([
                'basic domain' => ['example.com'],
                'empty domain name' => [''],
            ]);
            it('notifies nobody when empty', function (): void {
                expect(run(''))->toBeTrue();
            });
            PHP,
        linesByTest: [
            'it runs for any shape › empty domain name' => ['src/D.php:5'],
            'it notifies nobody when empty' => ['src/D.php:5', 'src/D.php:6'],
            'it runs for any shape › basic domain' => ['src/D.php:5', 'src/D.php:7'],
        ],
    );

    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe('it runs for any shape › empty domain name')
        ->and($report->candidates[0]->action())->toBe(Action::Delete)
        ->and($report->candidates[0]->coveredBy)->toBe(['it notifies nobody when empty']);
});
