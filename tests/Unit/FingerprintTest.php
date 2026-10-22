<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Fingerprint\ContractFingerprints;
use Pekral\TestRedundancy\Fingerprint\FingerprintCache;
use Pekral\TestRedundancy\Fingerprint\TokenCollector;
use PhpParser\ParserFactory;

/**
 * @return array<string, array<string, true>>
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
    $statements = (new ParserFactory())->createForHostVersion()->parse(
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
