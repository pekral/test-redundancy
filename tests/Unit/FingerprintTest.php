<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Fingerprint\ContractFingerprints;
use Pekral\TestRedundancy\Fingerprint\FingerprintCache;
use Pekral\TestRedundancy\Fingerprint\TokenCollector;
use PhpParser\ParserFactory;

/**
 * @return array<string, array<string, int>>
 */
function fingerprintsOf(string $code): array
{
    $file = (string) tempnam(sys_get_temp_dir(), 'fingerprint');
    file_put_contents($file, $code);
    $fingerprints = ContractFingerprints::of($file);
    unlink($file);

    return $fingerprints;
}

it('collects every node that carries a contract', function (): void {
    $statements = new ParserFactory()->createForHostVersion()->parse(
        '<?php expect(abs(-5))->toBe(true); Foo::create(new Bar(), Baz::VALUE, null, 1.5, "text", $dynamic(), '
        . '$object->$method(), (new $class)(), $class::$method(), $class::CONSTANT, -$value);',
    );

    expect(array_keys(TokenCollector::collect($statements)))->toEqualCanonicalizing([
        'function:expect',
        'call:toBe',
        'function:abs',
        'value:-5',
        'value:true',
        'call:Foo::create',
        'new:Bar',
        'const:Baz::VALUE',
        'value:null',
        'value:1.5',
        'value:\'text\'',
    ])->and(TokenCollector::collect(nodes: null))->toBe([]);
});

it('collects the Pest negation as part of the call it negates', function (): void {
    $parser = new ParserFactory()->createForHostVersion();

    expect(array_keys(TokenCollector::collect($parser->parse('<?php expect($value)->not->toBeEmpty()->toBeArray();'))))->toEqualCanonicalizing([
        'function:expect',
        'not:call:toBeEmpty',
        'call:toBeArray',
    ])->and(array_keys(TokenCollector::collect($parser->parse('<?php expect($value)->not()->toBeEmpty()->toBeArray();'))))->toEqualCanonicalizing([
        'function:expect',
        'not:call:toBeEmpty',
        'call:toBeArray',
    ])->and(array_keys(TokenCollector::collect($parser->parse('<?php expect($value)->toBeEmpty();'))))->toEqualCanonicalizing([
        'function:expect',
        'call:toBeEmpty',
    ]);
});

it('tells a literal used as an array key from the same literal used as a value', function (): void {
    $fingerprints = fingerprintsOf(<<<'PHP'
        <?php
        it('normalizes', function (mixed $input, mixed $expected): void {
            expect(normalize($input))->toBe($expected);
        })->with([
            'scalar string' => ['x', 'x'],
            'nested array' => [['z' => ['y' => 1, 'x' => 2]], ['z' => ['x' => 2, 'y' => 1]]],
            'false' => [false, false],
            'false key' => [[false => 1], [false => 1]],
        ]);
        PHP);
    $scalarString = $fingerprints['it normalizes › scalar string'];
    $nestedArray = $fingerprints['it normalizes › nested array'];

    expect(array_keys(array_diff_key($scalarString, $nestedArray)))->toBe(['value:\'x\''])
        ->and(array_keys(array_diff_key($nestedArray, $scalarString)))->toEqualCanonicalizing(
            ['key:value:\'z\'', 'key:value:\'y\'', 'key:value:\'x\'', 'value:1', 'value:2'],
        )
        ->and(array_keys(array_diff_key($fingerprints['it normalizes › false'], $fingerprints['it normalizes › false key'])))
        ->toBe(['value:false']);
});

it('counts the token value:[] once per empty array literal, however deeply it is nested', function (string $argument, int $count): void {
    $statements = new ParserFactory()->createForHostVersion()->parse('<?php store(' . $argument . ');');

    expect(TokenCollector::collect($statements))->toBe(['function:store' => 1, 'value:[]' => $count]);
})->with([
    'nested empty array' => ['[[[]]]', 1],
    'one empty array' => ['[]', 1],
    'two empty arrays' => ['[[], []]', 2],
]);

it('counts how many times a test uses each token', function (): void {
    $statements = new ParserFactory()->createForHostVersion()->parse(<<<'PHP'
        <?php
        $list = Lists::factory()->create();
        $otherList = Lists::factory()->create();
        import(new Batch(), new Batch());
        expect(add(1, 1))->toBe(2);
        PHP);

    expect(TokenCollector::collect($statements))->toBe([
        'call:create' => 2,
        'call:Lists::factory' => 2,
        'function:import' => 1,
        'new:Batch' => 2,
        'call:toBe' => 1,
        'function:expect' => 1,
        'function:add' => 1,
        'value:1' => 2,
        'value:2' => 1,
    ]);
});

it('counts a token of a dataset row together with the same token of the test body and its modifiers', function (): void {
    $fingerprints = fingerprintsOf(<<<'PHP'
        <?php
        it('adds', function (int $value): void {
            expect(add($value))->toBe(1);
        })->with(['one' => [1]])->group('math')->group('math');
        PHP);

    $row = $fingerprints['it adds › one'];

    expect([$row['value:1'], $row['call:group'], $row['value:\'math\'']])->toBe([2, 2, 2]);
});

it('fingerprints Pest tests, their dataset rows and chained modifiers', function (): void {
    $fingerprints = fingerprintsOf(<<<'PHP'
        <?php
        it('adds', function (int $sum): void {
            expect(add())->toBe($sum);
        })->with(['small' => [3], 'large' => [30], 4])->group('math');
        test('rejects', function (): void {
            fail();
        })->throws(InvalidArgumentException::class, 'Broken');
        it('reads a shared dataset', fn () => null)->with('shared');
        describe('ignored', fn () => null);
        it($dynamic, fn () => null);
        $object->it('ignored');
        PHP);

    expect(array_keys($fingerprints))->toBe(['it adds › small', 'it adds › large', 'rejects', 'it reads a shared dataset'])
        ->and(array_keys($fingerprints['it adds › small']))->toEqualCanonicalizing(
            ['function:expect', 'call:toBe', 'function:add', 'call:group', 'value:\'math\'', 'value:3'],
        )
        ->and($fingerprints['rejects'])->toHaveKeys(['call:throws', 'const:InvalidArgumentException::class', 'value:\'Broken\'']);
});

it('fingerprints PHPUnit tests and their data providers', function (): void {
    $fingerprints = fingerprintsOf(<<<'PHP'
        <?php
        final class GreeterTest extends TestCase
        {
            #[DataProvider('names')]
            public function testGreets(string $name): void { greet($name); }
            public static function names(): iterable { yield 'jane' => ['Jane']; yield 'john' => ['John']; }

            /** @dataProvider ages */
            public function testAges(int $age): void { age($age); }
            public static function ages(): array { return ['adult' => [30]]; }

            #[Test]
            public function greetsAStranger(): void { greet(''); }

            /** @test */
            public function greetsNobody(): void { greet(null); }

            #[DataProvider('missing')]
            public function testMissingProvider(): void {}

            public function testDynamicProvider(): void {}
            public static function dynamic(): array { return self::rows(); }

            public function helper(): void {}
            private function testPrivate(): void {}
        }
        PHP);

    expect(array_keys($fingerprints))->toBe(
        ['testGreets › jane', 'testGreets › john', 'testAges › adult', 'greetsAStranger', 'greetsNobody', 'testMissingProvider', 'testDynamicProvider'],
    )
        ->and(array_keys($fingerprints['testGreets › john']))->toEqualCanonicalizing(['function:greet', 'value:\'John\''])
        ->and(array_keys($fingerprints['testAges › adult']))->toEqualCanonicalizing(['function:age', 'value:30']);
});

it('reports what a test asserts beyond the tests that cover it', function (): void {
    $root = fixturePath('pest');
    $cache = new FingerprintCache($root);

    expect($cache->difference('tests/CalculatorTest.php :: it adds two numbers › negative numbers', ['tests/CalculatorTest.php :: it adds one and two']))
        ->toBe(['value:-1', 'value:-2', 'value:-3'])
        ->and(
            $cache->difference('tests/CalculatorTest.php :: it adds two numbers › small numbers', ['tests/CalculatorTest.php :: it adds one and two']),
        )->toBe(
            [],
        )
        ->and($cache->difference('tests/MissingTest.php :: it works', []))->toBeNull()
        ->and($cache->difference('tests/CalculatorTest.php :: it adds one and two', ['tests/CalculatorTest.php :: it is unknown']))->toBeNull();
});

it('measures what a test asserts beyond each covering test, never beyond their union', function (string $first, string $second): void {
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
    $cache = new FingerprintCache($project);

    $differsBy = $cache->difference('tests/DispatchTest.php :: it dispatches the first address with the second value', [$first, $second]);

    expect($differsBy)->toBe(['value:2']);
})->with([
    'smallest difference first' => [
        'tests/DispatchTest.php :: it dispatches to the first address',
        'tests/DispatchTest.php :: it dispatches to the second address',
    ],
    'smallest difference last' => [
        'tests/DispatchTest.php :: it dispatches to the second address',
        'tests/DispatchTest.php :: it dispatches to the first address',
    ],
]);

it('reports every token of a test when no test covers it', function (): void {
    $project = temporaryDirectory();
    mkdir($project . '/tests');
    file_put_contents($project . '/tests/DispatchTest.php', <<<'PHP'
        <?php
        it('dispatches', function (): void {
            dispatch('a');
        });
        PHP);

    $differsBy = new FingerprintCache($project)->difference('tests/DispatchTest.php :: it dispatches', []);

    expect($differsBy)->toEqualCanonicalizing(['function:dispatch', 'value:\'a\'']);
});

it('fingerprints Pest tests in a namespace, inside describe blocks and with a shared dataset', function (): void {
    $fingerprints = fingerprintsOf(<<<'PHP'
        <?php
        namespace Probe\Tests;

        dataset('numbers', ['one' => [1], 'two' => [2]]);
        dataset($dynamic, []);

        describe('adding', function (): void {
            it('adds', function (int $value): void {
                expect($value)->toBeInt();
            })->with('numbers');

            describe('nested', function (): void {
                test('deep', fn () => true);
            });
        });

        describe('arrow', fn () => null);
        it('uses an unknown dataset', fn () => null)->with('unknown');
        PHP);

    expect(array_keys($fingerprints))->toBe([
        '`adding` → it adds › one',
        '`adding` → it adds › two',
        '`adding` → `nested` → deep',
        'it uses an unknown dataset',
    ])
        ->and($fingerprints['`adding` → it adds › two'])->toHaveKey('value:2');
});
