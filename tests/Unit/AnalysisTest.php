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
 * @param list<string>|null $coveringDiffersBy
 */
function candidate(string $testCase, array $coveredBy, ?array $differsBy, ?array $coveringDiffersBy = []): Candidate
{
    return new Candidate('tests/FooTest.php :: ' . $testCase, $coveredBy, $differsBy, $coveringDiffersBy);
}

it('picks the action that fits a candidate', function (Candidate $candidate, Action $action): void {
    expect($candidate->action())->toBe($action);
})->with([
    'call in a sibling row' => [candidate('it works › a', ['it works › b'], ['value:1'], ['new:Money', 'value:2']), Action::ReviewDatasetRow],
    'call in the covering test' => [candidate('it works', ['it also works'], ['value:1'], ['value:2', 'call:forget']), Action::Review],
    'data in both tests' => [candidate('it works', ['it also works'], ['value:1'], ['value:2', 'key:const:Foo::BAR']), Action::MergeIntoDataset],
    'key in a sibling row' => [candidate('it works › a', ['it works › b'], ['key:value:\'a\'']), Action::ReviewDatasetRow],
    'key in one test of the same file' => [candidate('it works', ['it also works'], ['key:value:\'a\'', 'key:const:Foo::BAR']), Action::MergeIntoDataset],
    'literal under a key in both tests' => [
        candidate('it works', ['it also works'], ['in:value:\'a/b\'/value:false'], ['in:value:\'c\'/value:\'x\'/const:Foo::BAR']),
        Action::MergeIntoDataset,
    ],
    'new call' => [candidate('it works', ['it also works'], ['call:toThrow']), Action::Review],
    'nothing new' => [candidate('it works', ['it also works'], []), Action::Delete],
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

it('names every covering test, or the sibling row, in the first step', function (Candidate $candidate, string $firstStep): void {
    expect($candidate->steps()[0])->toBe($firstStep);
})->with([
    'delete' => [candidate('it works', ['it a', 'it b'], []), 'Confirm that "it a", "it b" together assert the same input and result.'],
    'sibling row' => [candidate('it works › empty', ['it a', 'it works › blank'], ['value:\'\'']), 'Compare this row with "it works › blank".'],
]);

it('serializes a candidate and a file report for an agent', function (): void {
    $candidate = new Candidate('tests/FooTest.php :: it works › empty', ['it works › blank'], ['value:\'\''], []);
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
                    'steps' => Action::ReviewDatasetRow->steps('"it works › blank"', coveringDiffersBy: []),
                    'coveredBy' => ['it works › blank'],
                    'coveringDiffersBy' => [],
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
        ->and($reports[0]->candidates[0]->differsBy)->toBe(['call:toBeEmpty'])
        ->and($reports[0]->candidates[0]->action())->toBe(Action::Review);
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
            'in:call:toBe#0/value:\'<html><body><h1>Test</h1></body></html>\'',
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
        ['in:value:\'tags\'/value:[]'],
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
        ['in:value:\'custom_fields\'/value:[]'],
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
        ->and($reports[0]->candidates[0]->differsBy)->toBe(['in:function:toggle#0/value:false', 'in:call:toBe#0/value:false'])
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
        ->and($reports[0]->candidates[0]->differsBy)->toBe(['in:value:\'a\'/value:[]'])
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
        ['in:value:\'sub_notify\'/value:\'owner@example.com\'', 'in:value:\'unsub_notify\'/value:null'],
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
        ['in:function:notify#0/value:false', 'in:call:toBe#0/value:true'],
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
        ['in:value:\'active\'/value:1', 'call:Connection::factory', 'in:function:expectCounterDelta#0/value:-1'],
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
        ['function:store', 'in:function:store#0/value:\'a@example.com\'', 'in:call:toBe#0/value:1'],
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
        ['in:call:toBe#0/value:1'],
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
        ['in:function:add#1/value:1', 'in:call:toBe#0/value:2'],
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
        ['value:1', 'value:2', 'value:3', 'loop:call:Batch::import', 'in:call:toBe#0/value:3'],
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
