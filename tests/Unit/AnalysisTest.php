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
 * @param list<string>|null $coveringDiffersBy
 * @param list<string> $unionOnlyLines
 */
function candidate(string $testCase, array $coveredBy, ?array $differsBy, ?array $coveringDiffersBy = [], array $unionOnlyLines = []): Candidate
{
    return new Candidate('tests/FooTest.php :: ' . $testCase, $coveredBy, $differsBy, $coveringDiffersBy, $unionOnlyLines, assertsNothing: false);
}

/**
 * @param list<string> $coveredBy
 * @param list<string>|null $differsBy
 * @param list<string> $coveringDiffersBy
 * @param list<string> $unionOnlyLines
 */
function sharedRow(array $coveredBy, ?array $differsBy, array $coveringDiffersBy = [], array $unionOnlyLines = []): Candidate
{
    $dataset = new SharedDataset('collidingRows', ['it second', 'it third']);

    return new Candidate(
        'tests/FooTest.php :: it first › one',
        $coveredBy,
        $differsBy,
        $coveringDiffersBy,
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
    'call in the covering test of a test that adds nothing' => [candidate('it works', ['it also works'], [], ['call:forget']), []],
    'data in the covering test' => [candidate('it works', ['it also works'], ['value:1'], ['value:2']), []],
    'unparsed' => [candidate('it works', ['it also works'], differsBy: null, coveringDiffersBy: null), []],
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
        ->and($report->candidates[0]->coveringDiffersBy)->toContain('expect:call:applyTo/call:format/call:toBe')
        ->and($report->candidates[0]->action())->toBe(Action::Review);
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
            'in:expect:property:manager/call:get/call:toBe#0/value:\'<html><body><h1>Test</h1></body></html>\'',
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
        ->and($report->candidates[0]->coveringDiffersBy)->toContain('in:function:register#0/key:value:\'tags\'')
        ->and($report->candidates[0]->action())->toBe(Action::Review);
});

it('still deletes a test whose covering test passes a value it lacks only to an assertion', function (
    FileReport $report,
    string $test,
    array $coveringDiffersBy,
): void {
    expect($report->candidates)->toHaveCount(1)
        ->and($report->candidates[0]->testCase())->toBe($test)
        ->and($report->candidates[0]->coveringDiffersBy)->toBe($coveringDiffersBy)
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
        ['expect:function:add/call:self::assertSame', 'in:expect:function:add/call:self::assertSame#0/value:3'],
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
    'list item int for a parameter of type string the covering closure puts in an expected list' => [
        <<<'PHP'
            <?php
            it('runs', fn (string $e) => expect(g())->toBe([$e]))->with(['row' => ['a']]);
            it('covers', fn () => expect(g())->toBe([2]));
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
    'list item string for a parameter without a type the covering closure puts in an expected list' => [
        <<<'PHP'
            <?php
            it('runs', fn ($e) => expect(g())->toBe([$e]))->with(['row' => ['a']]);
            it('covers', fn () => expect(g())->toBe(['b']));
            PHP,
        Action::MergeIntoDataset,
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

it('asks to merge a test whose covering test sets an array key it lacks only in an assertion', function (): void {
    $report = analyzeTokenTest(
        source: <<<'PHP'
            <?php
            it('builds an sms action', function (): void {
                expect(build('sms'))->toEqual(['type' => 'sms']);
            });
            it('builds a mail action', function (): void {
                expect(build('mail'))->toEqual(['type' => 'mail', 'properties' => ['senderMail' => 'a@example.com']]);
            });
            PHP,
        linesByTest: [
            'it builds an sms action' => ['src/Builder.php:5'],
            'it builds a mail action' => ['src/Builder.php:5', 'src/Builder.php:6'],
        ],
    );
    $json = decodedJson((string) json_encode($report->candidates[0]));

    expect($report->candidates)->toHaveCount(1)
        ->and(valueAt($json, 'test'))->toBe('it builds an sms action')
        ->and(valueAt($json, 'differsBy'))->toBe([
            'in:function:build#0/value:\'sms\'',
            'in:expect:function:build/call:toEqual#0/value:\'type\'/value:\'sms\'',
        ])
        ->and(valueAt($json, 'coveringDiffersBy'))->toBe([])
        ->and(valueAt($json, 'action'))->toBe('merge-into-dataset');
});

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
        ['in:call:create#0/value:\'active\'/value:1', 'call:Connection::factory', 'in:function:expectCounterDelta#0/value:-1'],
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
        ->and($report->candidates[0]->coveringDiffersBy)->toBe(
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
        ['in:call:claimUnregistered#0/function:collect#0/value:list-item'],
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
        ->and($report->candidates[0]->coveringDiffersBy)->toBe([
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
        sharedRow(['it first › two'], [], coveringDiffersBy: ['in:function:store#0/key:value:\'b\'']),
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
