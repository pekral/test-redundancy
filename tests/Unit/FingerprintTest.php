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
        'expect:function:abs/call:toBe',
        'function:abs',
        'in:function:abs#0/value:-5',
        'in:expect:function:abs/call:toBe#0/value:true',
        'call:Foo::create',
        'new:Bar',
        'in:call:Foo::create#1/const:Baz::VALUE',
        'in:call:Foo::create#2/value:null',
        'in:call:Foo::create#3/value:1.5',
        'in:call:Foo::create#4/value:\'text\'',
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

it('collects a Pest assertion written as a property like its call form', function (string $code, array $expected): void {
    $statements = new ParserFactory()->createForHostVersion()->parse('<?php ' . $code);

    expect(TokenCollector::collect($statements))->toBe($expected);
})->with([
    'assertion' => ['expect($value)->toBeEmpty;', ['call:toBeEmpty' => 1, 'function:expect' => 1]],
    'assertion after and' => ['\\expect($value)->and($other)->toBeTrue;', ['call:toBeTrue' => 1, 'call:and' => 1, 'function:expect' => 1]],
    'assertion on a higher-order property' => ['expect($user)->name->toBeString;', ['call:toBeString' => 1, 'function:expect' => 1]],
    'assertion on each item' => ['expect($items)->each->toBeInt;', ['call:toBeInt' => 1, 'call:each' => 1, 'function:expect' => 1]],
    'negated assertion' => ['expect($value)->not->toBeEmpty;', ['not:call:toBeEmpty' => 1, 'function:expect' => 1]],
]);

it('collects no property fetch that runs no Pest assertion', function (): void {
    $statements = new ParserFactory()->createForHostVersion()->parse(
        '<?php $user->name; $this->toBeEmpty; $users->each->toBeEmpty; $factory($value)->toBeEmpty; '
        . 'expect($user)->name->email; expect($user)->{$name};',
    );

    expect(TokenCollector::collect($statements))->toBe(['function:expect' => 2]);
});

it('collects the subject of an assertion in the assertion tokens', function (string $body, array $expected): void {
    $fingerprints = fingerprintsOf('<?php it(\'asserts\', function (): void { ' . $body . ' });');

    expect($fingerprints['it asserts'])->toEqual($expected);
})->with([
    'actual argument of a PHPUnit assertion' => ['self::assertSame(3, add(1, 2), \'sum\');', [
        'expect:function:add/call:self::assertSame' => 1,
        'function:add' => 1,
        'in:expect:function:add/call:self::assertSame#0/value:3' => 1,
        'in:expect:function:add/call:self::assertSame#2/value:\'sum\'' => 1,
        'in:function:add#0/value:1' => 1,
        'in:function:add#1/value:2' => 1,
    ],
    ],
    'actual argument without a token' => ['self::assertSame(foo(), $rows[\'id\']);', [
        'call:self::assertSame' => 1,
        'function:foo' => 1,
        'value:\'id\'' => 1,
    ],
    ],
    'actual argument written twice' => ['$x = 1; $x = bar(); self::assertEquals(new Money(5), $x);', [
        'call:self::assertEquals' => 1,
        'function:bar' => 1,
        'in:new:Money#0/value:5' => 1,
        'new:Money' => 1,
        'value:1' => 1,
    ],
    ],
    'call passed to expect' => ['expect(app(Templates::class)->create())->toBeGreaterThan(0);', [
        'call:create' => 1,
        'expect:call:create/call:toBeGreaterThan' => 1,
        'function:app' => 1,
        'function:expect' => 1,
        'in:expect:call:create/call:toBeGreaterThan#0/value:0' => 1,
        'in:function:app#0/const:Templates::class' => 1,
    ],
    ],
    'local variable the test assigns once' => ['$sum = add(1, 2); expect($sum)->toBe(3);', [
        'expect:function:add/call:toBe' => 1,
        'function:add' => 1,
        'function:expect' => 1,
        'in:expect:function:add/call:toBe#0/value:3' => 1,
        'in:function:add#0/value:1' => 1,
        'in:function:add#1/value:2' => 1,
    ],
    ],
    'property passed to expect' => ['expect($template->order)->toBeGreaterThan(0);', [
        'expect:property:order/call:toBeGreaterThan' => 1,
        'function:expect' => 1,
        'in:expect:property:order/call:toBeGreaterThan#0/value:0' => 1,
    ],
    ],
    'value passed to and' => ['expect($user)->toBeTrue()->and(load())->not->toBeEmpty();', [
        'call:and' => 1,
        'call:toBeTrue' => 1,
        'expect:function:load/not:call:toBeEmpty' => 1,
        'function:expect' => 1,
        'function:load' => 1,
    ],
    ],
]);

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

    expect(array_keys(array_diff_key($scalarString, $nestedArray)))->toBe(
        ['in:function:normalize#0/value:\'x\'', 'in:expect:function:normalize/call:toBe#0/value:\'x\''],
    )
        ->and(array_keys(array_diff_key($nestedArray, $scalarString)))->toEqualCanonicalizing(
            [
                'key:value:\'z\'',
                'key:value:\'y\'',
                'key:value:\'x\'',
                'in:function:normalize#0/value:\'z\'/value:\'y\'/value:1',
                'in:function:normalize#0/value:\'z\'/value:\'x\'/value:2',
                'in:expect:function:normalize/call:toBe#0/value:\'z\'/value:\'y\'/value:1',
                'in:expect:function:normalize/call:toBe#0/value:\'z\'/value:\'x\'/value:2',
            ],
        )
        ->and(array_keys(array_diff_key($fingerprints['it normalizes › false'], $fingerprints['it normalizes › false key'])))
        ->toBe(['in:function:normalize#0/value:false', 'in:expect:function:normalize/call:toBe#0/value:false']);
});

it('tells a literal under one array key from the same literal under another key', function (string $code, array $expected): void {
    $statements = new ParserFactory()->createForHostVersion()->parse('<?php ' . $code);

    expect(TokenCollector::collect($statements))->toEqual($expected);
})->with([
    'boolean under a key' => ['store([\'enabled\' => false]);', [
        'function:store' => 1,
        'in:function:store#0/value:\'enabled\'/value:false' => 1,
        'key:value:\'enabled\'' => 1,
    ],
    ],
    'boolean under another key' => ['store([\'visible\' => false]);', [
        'function:store' => 1,
        'in:function:store#0/value:\'visible\'/value:false' => 1,
        'key:value:\'visible\'' => 1,
    ],
    ],
    'class constant as a key' => ['store([Status::Active => true]);', [
        'function:store' => 1,
        'in:function:store#0/const:Status::Active/value:true' => 1,
        'key:const:Status::Active' => 1,
    ],
    ],
    'constant under a key' => ['store([\'status\' => Status::Active, \'flag\' => null]);', [
        'function:store' => 1,
        'in:function:store#0/value:\'flag\'/value:null' => 1,
        'in:function:store#0/value:\'status\'/const:Status::Active' => 1,
        'key:value:\'flag\'' => 1,
        'key:value:\'status\'' => 1,
    ],
    ],
    'empty array under a key' => ['store([\'tags\' => []]);', [
        'function:store' => 1,
        'in:function:store#0/value:\'tags\'/value:[]' => 1,
        'key:value:\'tags\'' => 1,
    ],
    ],
    'literal in a call under a key' => ['store([\'limit\' => min(5, 9)]);', [
        'function:min' => 1,
        'function:store' => 1,
        'in:function:store#0/value:\'limit\'/function:min#0/value:5' => 1,
        'in:function:store#0/value:\'limit\'/function:min#1/value:9' => 1,
        'key:value:\'limit\'' => 1,
    ],
    ],
    'literal in a list inside a list' => ['store([[1]]);', ['function:store' => 1, 'value:1' => 1]],
    'literal in a list under a key' => ['store([\'tags\' => [\'a\', \'b\']]);', [
        'function:store' => 1,
        'in:value:\'tags\'/value:\'a\'' => 1,
        'in:value:\'tags\'/value:\'b\'' => 1,
        'key:value:\'tags\'' => 1,
    ],
    ],
    'literal in a list without a key' => ['store([false, false]);', ['function:store' => 1, 'in:function:store#0/value:false' => 2]],
    'literal under nested keys' => ['store([\'user\' => [\'active\' => true]]);', [
        'function:store' => 1,
        'in:function:store#0/value:\'user\'/value:\'active\'/value:true' => 1,
        'key:value:\'active\'' => 1,
        'key:value:\'user\'' => 1,
    ],
    ],
    'negative number under a key' => ['store([\'balance\' => -5]);', [
        'function:store' => 1,
        'in:function:store#0/value:\'balance\'/value:-5' => 1,
        'key:value:\'balance\'' => 1,
    ],
    ],
    'string under a key' => ['store([\'name\' => \'x\']);', [
        'function:store' => 1,
        'in:function:store#0/value:\'name\'/value:\'x\'' => 1,
        'key:value:\'name\'' => 1,
    ],
    ],
]);

it('gives a literal passed straight to a call the position of its argument', function (string $code, array $expected): void {
    $statements = new ParserFactory()->createForHostVersion()->parse('<?php ' . $code);

    expect(TokenCollector::collect($statements))->toEqual($expected);
})->with([
    'argument of a dynamic call' => ['$store(true);', ['value:true' => 1]],
    'argument of a method call, a static call and a new expression' => ['$cart->add(1); Cart::make(-2); new Item(Size::Large);', [
        'call:add' => 1,
        'call:Cart::make' => 1,
        'in:call:add#0/value:1' => 1,
        'in:call:Cart::make#0/value:-2' => 1,
        'in:new:Item#0/const:Size::Large' => 1,
        'new:Item' => 1,
    ],
    ],
    'argument under a key' => ['store([\'limit\' => min(5)]);', [
        'function:min' => 1,
        'function:store' => 1,
        'in:function:store#0/value:\'limit\'/function:min#0/value:5' => 1,
        'key:value:\'limit\'' => 1,
    ],
    ],
    'first-class callable' => ['$trim = trim(...);', ['function:trim' => 1]],
    'literal inside an argument' => ['store([true], 1 + 2);', [
        'function:store' => 1,
        'in:function:store#0/value:true' => 1,
        'value:1' => 1,
        'value:2' => 1,
    ],
    ],
    'named argument' => ['store(enabled: true);', ['function:store' => 1, 'in:function:store#enabled/value:true' => 1]],
]);

it('carries every enclosing call in the call path of a value', function (string $code, array $expected): void {
    $statements = new ParserFactory()->createForHostVersion()->parse('<?php ' . $code);

    expect(TokenCollector::collect($statements))->toEqual($expected);
})->with([
    'map in a call passed to the same call' => ['json_encode(json_encode([\'test\' => true]));', [
        'function:json_encode' => 2,
        'in:function:json_encode#0/function:json_encode#0/value:\'test\'/value:true' => 1,
        'key:value:\'test\'' => 1,
    ],
    ],
    'value in a call an assertion receives' => ['expect(add(1, 2))->toBe(3);', [
        'expect:function:add/call:toBe' => 1,
        'function:add' => 1,
        'function:expect' => 1,
        'in:expect:function:add/call:toBe#0/value:3' => 1,
        'in:function:add#0/value:1' => 1,
        'in:function:add#1/value:2' => 1,
    ],
    ],
]);

it('counts the token value:[] once per empty array literal, however deeply it is nested', function (string $argument, int $count): void {
    $statements = new ParserFactory()->createForHostVersion()->parse('<?php $tags = ' . $argument . ';');

    expect(TokenCollector::collect($statements))->toBe(['value:[]' => $count]);
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
        'expect:function:add/call:toBe' => 1,
        'function:expect' => 1,
        'function:add' => 1,
        'in:function:add#0/value:1' => 1,
        'in:function:add#1/value:1' => 1,
        'in:expect:function:add/call:toBe#0/value:2' => 1,
    ]);
});

it('marks a call or new expression in a loop body, whose number of runs the fingerprint cannot count', function (string $code, array $expected): void {
    $statements = new ParserFactory()->createForHostVersion()->parse('<?php ' . $code);

    expect(TokenCollector::collect($statements))->toEqual($expected);
})->with([
    'do while' => ['do { Batch::import(); } while (false);', ['loop:call:Batch::import' => 1, 'value:false' => 1]],
    'for' => ['for ($i = 0; $i < 3; $i++) { $importer->import(); }', ['value:0' => 1, 'value:3' => 1, 'loop:call:import' => 1]],
    'foreach over range' => [
        'foreach (range(1, 3) as $i) { import(new Batch()); }',
        [
            'function:range' => 1,
            'in:function:range#0/value:1' => 1,
            'in:function:range#1/value:3' => 1,
            'loop:function:import' => 1,
            'loop:new:Batch' => 1,
        ],
    ],
    'literals in a loop body' => [
        'foreach ($ids as $id) { store([\'id\' => 5], null); }',
        ['loop:function:store' => 1, 'key:value:\'id\'' => 1, 'in:function:store#0/value:\'id\'/value:5' => 1, 'in:function:store#1/value:null' => 1],
    ],
    'nested loops' => ['foreach ($lists as $list) { foreach ($list as $item) { import(); } }', ['loop:function:import' => 1]],
    'while' => ['while (true) { Batch::import(); }', ['value:true' => 1, 'loop:call:Batch::import' => 1]],
]);

it('counts a token of a dataset row together with the same token of the test body and its modifiers', function (): void {
    $fingerprints = fingerprintsOf(<<<'PHP'
        <?php
        it('adds', function (int $value): void {
            expect(add($value))->toBe(add(1));
        })->with(['one' => [1]])->group('math')->group('math');
        PHP);

    $row = $fingerprints['it adds › one'];

    expect([$row['in:function:add#0/value:1'], $row['call:group'], $row['value:\'math\'']])->toBe([2, 2, 2]);
});

it('gives a map of literals a dataset row passes the argument position of the call it reaches', function (): void {
    $fingerprints = fingerprintsOf(<<<'PHP'
        <?php
        it('lists the changed keys', function (array $previous, array $current): void {
            changedKeys($previous, $current);
        })->with([
            'a key was added' => [['listId' => 1], ['listId' => 1, 'store' => 'shop']],
            'a key was removed' => [['listId' => 1, 'store' => 'shop'], ['listId' => 1]],
        ]);
        PHP);

    expect($fingerprints['it lists the changed keys › a key was added'])->toEqual([
        'function:changedKeys' => 1,
        'in:function:changedKeys#0/value:\'listId\'/value:1' => 1,
        'key:value:\'listId\'' => 2,
        'in:function:changedKeys#1/value:\'listId\'/value:1' => 1,
        'in:function:changedKeys#1/value:\'store\'/value:\'shop\'' => 1,
        'key:value:\'store\'' => 1,
    ]);
});

it('counts a literal a test assigns once to a local variable where the test reads the variable', function (string $body, array $expected): void {
    $fingerprints = fingerprintsOf('<?php it(\'notifies\', function (string $parameter): void { ' . $body . ' });');

    expect($fingerprints['it notifies'])->toEqual($expected);
})->with([
    'parameter the test assigns a literal' => ['$parameter = \'a\'; notify($parameter);', ['function:notify' => 1, 'value:\'a\'' => 1]],
    'variable a closure captures by reference' => [
        '$email = \'a\'; $notify = function () use (&$email): void { notify($email); };',
        ['function:notify' => 1, 'value:\'a\'' => 1],
    ],
    'variable a closure captures by value' => [
        '$email = \'a\'; $notify = function () use ($email): void { notify($email); };',
        ['function:notify' => 1, 'in:function:notify#0/value:\'a\'' => 1],
    ],
    'variable assigned a list with an object and read twice' => [
        '$contacts = [new Contact()]; notify($contacts); notify($contacts);',
        ['function:notify' => 2, 'new:Contact' => 1],
    ],
    'variable assigned a literal once' => ['$email = \'a\'; notify($email);', ['function:notify' => 1, 'in:function:notify#0/value:\'a\'' => 1]],
    'variable assigned a literal twice' => [
        '$email = \'a\'; $email = \'b\'; notify($email);',
        ['function:notify' => 1, 'value:\'a\'' => 1, 'value:\'b\'' => 1],
    ],
    'variable assigned a map and read once' => [
        '$options = [\'a\' => 1]; notify($options);',
        ['function:notify' => 1, 'key:value:\'a\'' => 1, 'in:function:notify#0/value:\'a\'/value:1' => 1],
    ],
    'variable assigned an array with a key and read twice' => [
        '$data = [\'id\' => 5]; notify($data); notify($data);',
        ['function:notify' => 2, 'key:value:\'id\'' => 2, 'in:function:notify#0/value:\'id\'/value:5' => 2],
    ],
    'variable assigned an object' => ['$contact = new Contact(); notify($contact);', ['function:notify' => 1, 'new:Contact' => 1]],
    'variable the test appends to' => [
        '$emails = []; $emails[] = \'a\'; notify($emails);',
        ['function:notify' => 1, 'value:\'a\'' => 1, 'value:[]' => 1],
    ],
    'variable the test never reads' => ['$email = \'a\'; notify();', ['function:notify' => 1, 'value:\'a\'' => 1]],
]);

it('gives a call result a test assigns once to a local variable the tokens of the call passed where the test first reads it', function (
    string $assigned,
    string $inline,
): void {
    $fingerprints = fingerprintsOf(
        '<?php it(\'assigned\', function (): void { ' . $assigned . ' }); it(\'inline\', function (): void { ' . $inline . ' });',
    );

    expect($fingerprints['it assigned'])->toEqual($fingerprints['it inline']);
})->with([
    'call result read once' => ['$email = sprintf(\'email@%s\', $domain); notify($email);', 'notify(sprintf(\'email@%s\', $domain));'],
    'call result read twice' => [
        '$user = create([\'role\' => \'admin\']); login($user); expect($user->fresh())->toBeTrue();',
        'login(create([\'role\' => \'admin\'])); expect($user->fresh())->toBeTrue();',
    ],
]);

it('keeps the tokens of a call result without a path when the test first reads its variable in a loop body', function (): void {
    $fingerprints = fingerprintsOf(
        '<?php it(\'sends\', function (): void { $client = client([\'a\' => 1]); foreach ($ids as $id) { $client->send($id); } });',
    );

    expect($fingerprints['it sends'])->toEqual([
        'function:client' => 1,
        'in:function:client#0/value:\'a\'/value:1' => 1,
        'key:value:\'a\'' => 1,
        'loop:call:send' => 1,
    ]);
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
            [
                'function:expect',
                'expect:function:add/call:toBe',
                'function:add',
                'call:group',
                'value:\'math\'',
                'in:expect:function:add/call:toBe#0/value:3',
            ],
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
        ->and(array_keys($fingerprints['testGreets › john']))->toEqualCanonicalizing(['function:greet', 'in:function:greet#0/value:\'John\''])
        ->and(array_keys($fingerprints['testAges › adult']))->toEqualCanonicalizing(['function:age', 'in:function:age#0/value:30']);
});

it('reports what a test asserts beyond the tests that cover it', function (): void {
    $root = fixturePath('pest');
    $cache = new FingerprintCache($root);

    expect($cache->difference('tests/CalculatorTest.php :: it adds two numbers › negative numbers', ['tests/CalculatorTest.php :: it adds one and two']))
        ->toBe(['in:call:add#0/value:-1', 'in:call:add#1/value:-2', 'in:expect:call:add/call:toBe#0/value:-3'])
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

    expect($differsBy)->toBe(['in:function:dispatch#1/value:2']);
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

    expect($differsBy)->toEqualCanonicalizing(['function:dispatch', 'in:function:dispatch#0/value:\'a\'']);
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
        ->and($fingerprints['`adding` → it adds › two'])->toHaveKey('in:function:expect#0/value:2');
});

function declaresSingleTestCase(string $code): bool
{
    $project = temporaryDirectory();
    file_put_contents($project . '/ProbeTest.php', $code);

    return ContractFingerprints::declaresSingleTestCase($project . '/ProbeTest.php');
}

it('recognises a file that declares exactly one test case and no dataset', function (string $code): void {
    expect(declaresSingleTestCase($code))->toBeTrue();
})->with([
    'a Pest it' => ['<?php it(\'works\', function (): void { expect(1)->toBe(1); });'],
    'a Pest test inside describe' => ['<?php describe(\'group\', function (): void { it(\'works\', fn () => expect(1)->toBe(1)); });'],
    'a Pest test that reads the test case' => ['<?php it(\'works\', function (): void { test()->assertTrue(true); });'],
    'a Pest test with modifiers' => ['<?php test(\'works\', fn () => expect(1)->toBe(1))->group(\'fast\')->skip(false);'],
    'a PHPUnit method with the Test attribute' => ['<?php class ProbeTest { #[Test] public function works(): void {} }'],
    'a PHPUnit test method' => ['<?php class ProbeTest { public function testWorks(): void {} public function helper(): void {} }'],
    'a PHPUnit test that expects a mock call with arguments' => [
        '<?php class ProbeTest { public function testWorks(): void { $mock->shouldReceive("run")->with(1)->once(); mock(Foo::class)->with(2); } }',
    ],
]);

it('keeps a file in the coverage run when it may run more than one test case', function (string $code): void {
    expect(declaresSingleTestCase($code))->toBeFalse();
})->with([
    'a file that does not parse' => ['<?php it('],
    'a Pest describe block with a dataset' => ['<?php describe(\'g\', function (): void { it(\'a\', fn () => null); })->with([1, 2]);'],
    'a Pest test beside a loop that generates tests' => [
        '<?php it(\'a\', fn () => null); '
        . 'foreach ([1, 2] as $value) { it(\'b\' . $value, fn () => null); }',
    ],
    'a Pest test generated in a loop' => ['<?php foreach ([1, 2] as $value) { it(\'a\' . $value, fn () => null); }'],
    'a Pest test with a computed description' => ['<?php it($description, fn () => null);'],
    'a Pest test with a dataset' => ['<?php it(\'a\', fn (int $value) => null)->with([1, 2]);'],
    'a Pest test with a dataset it cannot resolve' => ['<?php it(\'a\', fn (int $value) => null)->with(fn () => [1, 2]);'],
    'a Pest test with a shared dataset' => ['<?php it(\'a\', fn (int $value) => null)->with(\'numbers\');'],
    'a Pest test with one dataset row' => ['<?php it(\'a\', fn (int $value) => null)->with([\'one\' => [1]]);'],
    'a PHPUnit test with an external data provider' => [
        '<?php class ProbeTest { #[DataProviderExternal(Rows::class, \'rows\')] public function testA(int $value): void {} }',
    ],
    'a PHPUnit test with the DataProvider attribute' => [
        '<?php class ProbeTest { #[DataProvider(\'rows\')] public function testA(int $value): void {} '
        . 'public static function rows(): array { return [[1], [2]]; } }',
    ],
    'a PHPUnit test with the dataProvider tag' => [
        '<?php class ProbeTest { /** @dataProvider rows */ public function testA(int $value): void {} '
        . 'public static function rows(): array { return [[1], [2]]; } }',
    ],
    'a PHPUnit test with the TestWith attribute' => [
        '<?php class ProbeTest { #[TestWith([1])] #[TestWith([2])] public function testA(int $value): void {} }',
    ],
    'a PHPUnit test with the testWith tag' => ['<?php class ProbeTest { /** @testWith [1] */ public function testA(int $value): void {} }'],
    'a repeated Pest test' => ['<?php it(\'a\', fn () => null)->repeat(3);'],
    'no test case' => ['<?php $value = 1;'],
    'two Pest tests' => ['<?php it(\'a\', fn () => null); it(\'b\', fn () => null);'],
    'two Pest tests with one description' => ['<?php it(\'a\', fn () => null); it(\'a\', fn () => null);'],
    'two PHPUnit test methods' => ['<?php class ProbeTest { public function testA(): void {} public function testB(): void {} }'],
]);

it('keeps a file that does not exist in the coverage run', function (): void {
    expect(ContractFingerprints::declaresSingleTestCase(temporaryDirectory() . '/MissingTest.php'))->toBeFalse();
});
