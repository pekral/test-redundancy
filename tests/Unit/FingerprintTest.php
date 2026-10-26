<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Fingerprint\ContractFingerprints;
use Pekral\TestRedundancy\Fingerprint\FingerprintCache;
use Pekral\TestRedundancy\Fingerprint\NonLiteralArguments;
use Pekral\TestRedundancy\Fingerprint\SharedDataset;
use Pekral\TestRedundancy\Fingerprint\TokenCollector;
use PhpParser\ParserFactory;

/**
 * @return array<string, array<string, int>>
 */
function fingerprintsOf(string $code): array
{
    $file = (string) tempnam(sys_get_temp_dir(), 'fingerprint');
    file_put_contents($file, $code);
    $fingerprints = ContractFingerprints::analyse($file)->fingerprints;
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
        'function:$dynamic',
        'in:function:abs#0/value:-5',
        'read:function:abs',
        'in:expect:function:abs/call:toBe#0/value:true',
        'call:Foo::create',
        'new:Bar',
        'in:call:Foo::create#0/non-literal',
        'in:call:Foo::create#1/const:Baz::VALUE',
        'in:call:Foo::create#2/value:null',
        'in:call:Foo::create#3/value:1.5',
        'in:call:Foo::create#4/value:\'text\'',
        'in:call:Foo::create#5/non-literal',
        'in:call:Foo::create#6/non-literal',
        'in:call:Foo::create#7/non-literal',
        'in:call:Foo::create#8/non-literal',
        'in:call:Foo::create#9/non-literal',
        'in:call:Foo::create#10/non-literal',
        'in:call:Foo::create#10/variable:value',
    ])->and(TokenCollector::collect(nodes: null))->toBe([]);
});

it('collects the Pest negation as part of the call it negates', function (): void {
    $parser = new ParserFactory()->createForHostVersion();

    expect(array_keys(TokenCollector::collect($parser->parse('<?php expect($value)->not->toBeEmpty()->toBeArray();'))))->toEqualCanonicalizing([
        'function:expect',
        'in:function:expect#0/variable:value',
        'not:call:toBeEmpty',
        'call:toBeArray',
    ])->and(array_keys(TokenCollector::collect($parser->parse('<?php expect($value)->not()->toBeEmpty()->toBeArray();'))))->toEqualCanonicalizing([
        'function:expect',
        'in:function:expect#0/variable:value',
        'not:call:toBeEmpty',
        'call:toBeArray',
    ])->and(array_keys(TokenCollector::collect($parser->parse('<?php expect($value)->toBeEmpty();'))))->toEqualCanonicalizing([
        'function:expect',
        'in:function:expect#0/variable:value',
        'call:toBeEmpty',
    ]);
});

it('collects a Pest assertion written as a property like its call form', function (string $code, array $expected): void {
    $statements = new ParserFactory()->createForHostVersion()->parse('<?php ' . $code);

    expect(TokenCollector::collect($statements))->toEqual($expected);
})->with([
    'assertion' => ['expect($value)->toBeEmpty;', ['in:function:expect#0/variable:value' => 1, 'call:toBeEmpty' => 1, 'function:expect' => 1]],
    'assertion after and' => ['\\expect($value)->and($other)->toBeTrue;', [
        'call:and' => 1,
        'call:toBeTrue' => 1,
        'function:expect' => 1,
        'in:call:and#0/variable:other' => 1,
        'in:function:expect#0/variable:value' => 1,
    ],
    ],
    'assertion on a higher-order property' => [
        'expect($user)->name->toBeString;',
        ['in:function:expect#0/variable:user' => 1, 'call:toBeString' => 1, 'function:expect' => 1],
    ],
    'assertion on each item' => [
        'expect($items)->each->toBeInt;',
        ['in:function:expect#0/variable:items' => 1, 'call:toBeInt' => 1, 'call:each' => 1, 'function:expect' => 1],
    ],
    'negated assertion' => [
        'expect($value)->not->toBeEmpty;',
        ['in:function:expect#0/variable:value' => 1, 'not:call:toBeEmpty' => 1, 'function:expect' => 1],
    ],
]);

it('collects no property fetch that runs no Pest assertion', function (): void {
    $statements = new ParserFactory()->createForHostVersion()->parse(
        '<?php $user->name; $this->toBeEmpty; $users->each->toBeEmpty; $factory($value)->toBeEmpty; '
        . 'expect($user)->name->email; expect($user)->{$name};',
    );

    expect(TokenCollector::collect($statements))->toBe([
        'in:function:$factory#0/non-literal' => 1,
        'in:function:$factory#0/variable:value' => 1,
        'in:function:expect#0/variable:user' => 2,
        'function:$factory' => 1,
        'function:expect' => 2,
    ]);
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
        'read:function:add' => 1,
    ],
    ],
    'actual argument without a token' => ['self::assertSame(foo(), $rows[\'id\']);', [
        'call:self::assertSame' => 1,
        'function:foo' => 1,
        'read:function:foo' => 1,
        'value:\'id\'' => 1,
    ],
    ],
    'actual argument written twice' => ['$x = 1; $x = bar(); self::assertEquals(new Money(5), $x);', [
        'call:self::assertEquals' => 1,
        'function:bar' => 1,
        'in:call:self::assertEquals#1/variable:x' => 1,
        'in:new:Money#0/value:5' => 1,
        'new:Money' => 1,
        'read:new:Money' => 1,
        'value:1' => 1,
    ],
    ],
    'call on an enum case' => ['expect(Status::Active->label())->toBe(\'Active\');', [
        'call:label' => 1,
        'const:Status::Active' => 1,
        'expect:call:label/call:toBe' => 1,
        'function:expect' => 1,
        'in:expect:call:label/call:toBe#0/value:\'Active\'' => 1,
        'read:call:label' => 1,
    ],
    ],
    'call passed to expect' => ['expect(app(Templates::class)->create())->toBeGreaterThan(0);', [
        'call:create' => 1,
        'expect:function:app/call:create/call:toBeGreaterThan' => 1,
        'function:app' => 1,
        'function:expect' => 1,
        'in:expect:function:app/call:create/call:toBeGreaterThan#0/value:0' => 1,
        'in:function:app#0/const:Templates::class' => 1,
        'read:call:create' => 1,
        'read:function:app' => 1,
    ],
    ],
    'local variable the test assigns once' => ['$sum = add(1, 2); expect($sum)->toBe(3);', [
        'expect:function:add/call:toBe' => 1,
        'function:add' => 1,
        'function:expect' => 1,
        'in:expect:function:add/call:toBe#0/value:3' => 1,
        'in:function:add#0/value:1' => 1,
        'in:function:add#1/value:2' => 1,
        'read:function:add' => 1,
    ],
    ],
    'property passed to expect' => ['expect($template->order)->toBeGreaterThan(0);', [
        'expect:property:order/call:toBeGreaterThan' => 1,
        'function:expect' => 1,
        'in:expect:property:order/call:toBeGreaterThan#0/value:0' => 1,
        'in:function:expect#0/property:order' => 1,
    ],
    ],
    'value passed to and' => ['expect($user)->toBeTrue()->and(load())->not->toBeEmpty();', [
        'call:and' => 1,
        'call:toBeTrue' => 1,
        'expect:function:load/not:call:toBeEmpty' => 1,
        'function:expect' => 1,
        'function:load' => 1,
        'in:function:expect#0/variable:user' => 1,
        'read:function:load' => 1,
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
                'in:function:normalize#0/key:value:\'z\'',
                'in:function:normalize#0/value:\'z\'/key:value:\'y\'',
                'in:function:normalize#0/value:\'z\'/key:value:\'x\'',
                'in:expect:function:normalize/call:toBe#0/key:value:\'z\'',
                'in:expect:function:normalize/call:toBe#0/value:\'z\'/key:value:\'y\'',
                'in:expect:function:normalize/call:toBe#0/value:\'z\'/key:value:\'x\'',
                'in:function:normalize#0/value:\'z\'/value:\'y\'/value:1',
                'in:function:normalize#0/value:\'z\'/value:\'x\'/value:2',
                'in:expect:function:normalize/call:toBe#0/value:\'z\'/value:\'y\'/value:1',
                'in:expect:function:normalize/call:toBe#0/value:\'z\'/value:\'x\'/value:2',
                'in:function:normalize#0/value:\'z\'/keys:value:\'y\'>value:\'x\'',
                'in:expect:function:normalize/call:toBe#0/value:\'z\'/keys:value:\'x\'>value:\'y\'',
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
        'in:function:store#0/key:value:\'enabled\'' => 1,
        'in:function:store#0/value:\'enabled\'/value:false' => 1,
    ],
    ],
    'boolean under another key' => ['store([\'visible\' => false]);', [
        'function:store' => 1,
        'in:function:store#0/key:value:\'visible\'' => 1,
        'in:function:store#0/value:\'visible\'/value:false' => 1,
    ],
    ],
    'class constant as a key' => ['store([Status::Active => true]);', [
        'function:store' => 1,
        'in:function:store#0/const:Status::Active/value:true' => 1,
        'in:function:store#0/key:const:Status::Active' => 1,
    ],
    ],
    'constant under a key' => ['store([\'status\' => Status::Active, \'flag\' => null]);', [
        'function:store' => 1,
        'in:function:store#0/key:value:\'flag\'' => 1,
        'in:function:store#0/key:value:\'status\'' => 1,
        'in:function:store#0/keys:value:\'status\'>value:\'flag\'' => 1,
        'in:function:store#0/value:\'flag\'/value:null' => 1,
        'in:function:store#0/value:\'status\'/const:Status::Active' => 1,
    ],
    ],
    'empty array under a key' => ['store([\'tags\' => []]);', [
        'function:store' => 1,
        'in:function:store#0/key:value:\'tags\'' => 1,
        'in:function:store#0/value:\'tags\'/value:[]' => 1,
    ],
    ],
    'enum value as a key' => ['store([CouponType::FIXED->value => true]);', [
        'function:store' => 1,
        'in:function:store#0/const:CouponType::FIXED->value/value:true' => 1,
        'in:function:store#0/key:const:CouponType::FIXED->value' => 1,
    ],
    ],
    'enum value as a key of an array no call receives' => ['$options = [CouponType::FIXED->value => true];', [
        'in:const:CouponType::FIXED->value/value:true' => 1,
        'key:const:CouponType::FIXED->value' => 1,
    ],
    ],
    'key under a key of an array no call receives' => ['$options = [\'user\' => [\'active\' => true]];', [
        'in:value:\'user\'/key:value:\'active\'' => 1,
        'in:value:\'user\'/value:\'active\'/value:true' => 1,
        'key:value:\'user\'' => 1,
    ],
    ],
    'literal in a call under a key' => ['store([\'limit\' => min(5, 9)]);', [
        'function:min' => 1,
        'function:store' => 1,
        'in:function:store#0/key:value:\'limit\'' => 1,
        'in:function:store#0/value:\'limit\'/function:min#0/value:5' => 1,
        'in:function:store#0/value:\'limit\'/function:min#1/value:9' => 1,
    ],
    ],
    'literal in a list inside a list' => ['store([[1]]);', ['function:store' => 1, 'in:function:store#0/value:list-item' => 2, 'value:1' => 1]],
    'literal in a list under a key' => ['store([\'tags\' => [\'a\', \'b\']]);', [
        'function:store' => 1,
        'in:function:store#0/key:value:\'tags\'' => 1,
        'in:function:store#0/value:\'tags\'/value:list-item' => 2,
        'in:value:\'tags\'/items:value:\'a\'>value:\'b\'' => 1,
        'in:value:\'tags\'/value:\'a\'' => 1,
        'in:value:\'tags\'/value:\'b\'' => 1,
    ],
    ],
    'literal in a list without a key' => ['store([false, false]);', [
        'function:store' => 1,
        'in:function:store#0/items:value:false>value:false' => 1,
        'in:function:store#0/value:false' => 2,
        'in:function:store#0/value:list-item' => 2,
    ],
    ],
    'literal under nested keys' => ['store([\'user\' => [\'active\' => true]]);', [
        'function:store' => 1,
        'in:function:store#0/key:value:\'user\'' => 1,
        'in:function:store#0/value:\'user\'/key:value:\'active\'' => 1,
        'in:function:store#0/value:\'user\'/value:\'active\'/value:true' => 1,
    ],
    ],
    'negative number under a key' => ['store([\'balance\' => -5]);', [
        'function:store' => 1,
        'in:function:store#0/key:value:\'balance\'' => 1,
        'in:function:store#0/value:\'balance\'/value:-5' => 1,
    ],
    ],
    'string under a key' => ['store([\'name\' => \'x\']);', [
        'function:store' => 1,
        'in:function:store#0/key:value:\'name\'' => 1,
        'in:function:store#0/value:\'name\'/value:\'x\'' => 1,
    ],
    ],
]);

it('gives each pair of array keys a token, so the order of the keys counts', function (string $code, array $expected): void {
    $statements = new ParserFactory()->createForHostVersion()->parse('<?php ' . $code);

    $orderTokens = array_filter(
        TokenCollector::collect($statements),
        static fn (string $token): bool => preg_match('~(?:^|/)keys:~', $token) === 1,
        ARRAY_FILTER_USE_KEY,
    );

    expect($orderTokens)->toEqual($expected);
})->with([
    'a list' => ['store([1, 2]);', []],
    'an enum case read through a property as a key' => ['store([CouponType::FIXED->value => 1, \'b\' => 2]);', [
        'in:function:store#0/keys:const:CouponType::FIXED->value>value:\'b\'' => 1,
    ],
    ],
    'keys of an array nested under a key' => ['store([\'user\' => [\'x\' => 1, \'y\' => 2], \'z\' => 3]);', [
        'in:function:store#0/keys:value:\'user\'>value:\'z\'' => 1,
        'in:function:store#0/value:\'user\'/keys:value:\'x\'>value:\'y\'' => 1,
    ],
    ],
    'keys of an array no call receives' => ['$options = [\'a\' => 1, \'b\' => 2];', ['keys:value:\'a\'>value:\'b\'' => 1]],
    'keys that no token names' => ['store([\'a\' => 1, $key => 2, 3, \'b\' => 4]);', ['in:function:store#0/keys:value:\'a\'>value:\'b\'' => 1]],
    'one key' => ['store([\'a\' => 1]);', []],
    'the same array twice' => ['store([\'a\' => 1, \'b\' => 2], [\'a\' => 1, \'b\' => 2]);', [
        'in:function:store#0/keys:value:\'a\'>value:\'b\'' => 1,
        'in:function:store#1/keys:value:\'a\'>value:\'b\'' => 1,
    ],
    ],
    'the same keys in another order' => ['store([\'b\' => 2, \'a\' => 1]);', ['in:function:store#0/keys:value:\'b\'>value:\'a\'' => 1]],
    'three keys' => ['store([\'a\' => 1, \'b\' => 2, \'c\' => 3]);', [
        'in:function:store#0/keys:value:\'a\'>value:\'b\'' => 1,
        'in:function:store#0/keys:value:\'a\'>value:\'c\'' => 1,
        'in:function:store#0/keys:value:\'b\'>value:\'c\'' => 1,
    ],
    ],
    'two keys passed to a call' => ['store([\'a\' => 1, \'b\' => 2]);', ['in:function:store#0/keys:value:\'a\'>value:\'b\'' => 1]],
]);

it('tells dataset rows that pass the same array with its keys in another order', function (): void {
    $fingerprints = fingerprintsOf(<<<'PHP'
        <?php
        it('returns a row identical to the request or to its trimmed form', function (array $storedRows): void {
            foreach ($storedRows as $storedItemId => $name) {
                Product::create(['item_id' => $storedItemId, 'name' => $name]);
            }
        })->with([
            'clean row inserted before a both-sides one' => [['AA' => 'exact', ' AA ' => 'variant']],
            'clean row inserted after a both-sides one' => [[' AA ' => 'variant', 'AA' => 'exact']],
        ]);
        PHP);

    $before = $fingerprints['it returns a row identical to the request or to its trimmed form › clean row inserted before a both-sides one'];
    $after = $fingerprints['it returns a row identical to the request or to its trimmed form › clean row inserted after a both-sides one'];
    $exactFirst = 'keys:value:\'AA\'>value:\' AA \'';
    $variantFirst = 'keys:value:\' AA \'>value:\'AA\'';

    expect([isset($before[$exactFirst]), isset($before[$variantFirst]), isset($after[$exactFirst]), isset($after[$variantFirst])])
        ->toBe([true, false, false, true]);
});

it('gives each pair of list elements a token, so the order of the elements counts', function (string $code, array $expected): void {
    $statements = new ParserFactory()->createForHostVersion()->parse('<?php ' . $code);

    $orderTokens = array_filter(
        TokenCollector::collect($statements),
        static fn (string $token): bool => preg_match('~(?:^|/)items:~', $token) === 1,
        ARRAY_FILTER_USE_KEY,
    );

    expect($orderTokens)->toEqual($expected);
})->with([
    'a list no call receives' => ['$ids = [1, 2];', ['items:value:1>value:2' => 1]],
    'a list with an unpacked array' => ['store([...$ids, 1, 2]);', []],
    'a map' => ['store([\'a\' => 1, \'b\' => 2]);', []],
    'an array element' => ['store([[\'a\' => 1], 2, []]);', [
        'in:function:store#0/items:value:2>value:[]' => 1,
        'in:function:store#0/items:[\'a\' => 1]>value:2' => 1,
        'in:function:store#0/items:[\'a\' => 1]>value:[]' => 1,
    ],
    ],
    'an enum case read through a property' => ['store([CouponType::FIXED->value, \'b\']);', [
        'in:function:store#0/items:const:CouponType::FIXED->value>value:\'b\'' => 1,
    ],
    ],
    'elements that no token names' => ['store([1, $item, 2]);', ['in:function:store#0/items:value:1>value:2' => 1]],
    'one element' => ['store([1]);', []],
    'the same elements in another order' => ['store([5, 3]);', ['in:function:store#0/items:value:5>value:3' => 1]],
    'three elements' => ['store([1, 2, 3]);', [
        'in:function:store#0/items:value:1>value:2' => 1,
        'in:function:store#0/items:value:1>value:3' => 1,
        'in:function:store#0/items:value:2>value:3' => 1,
    ],
    ],
    'two elements passed to a call' => ['store([3, 5]);', ['in:function:store#0/items:value:3>value:5' => 1]],
]);

it('gives each pair of arranging calls with literal input a token, so the order of the calls counts', function (string $body, array $expected): void {
    $fingerprints = fingerprintsOf('<?php it(\'arranges\', function (): void { ' . $body . ' });');

    $orderTokens = array_filter(
        $fingerprints['it arranges'],
        static fn (string $token): bool => str_starts_with($token, 'calls:'),
        ARRAY_FILTER_USE_KEY,
    );

    expect($orderTokens)->toEqual($expected);
})->with([
    'a call without literal input' => ['store(1); refresh($model); store(2);', ['calls:store(1)>store(2)' => 1]],
    'a call written in another form' => ['store("a"); store(0x10); /* note */ store(NULL);', [
        'calls:store(16)>store(null)' => 1,
        'calls:store(\'a\')>store(16)' => 1,
        'calls:store(\'a\')>store(null)' => 1,
    ],
    ],
    'an assertion' => ['store(1); expect(load(1))->toBe(1); $this->assertSame(2, load(2)); store(2);', ['calls:store(1)>store(2)' => 1]],
    'a statement that is not a call' => ['$limit = 5; store(1);', []],
    'calls assigned to variables' => ['$first = Model::factory()->create([\'a\' => 1]); $second = new Order(2);', [
        'calls:Model::factory()->create([\'a\' => 1])>new Order(2)' => 1,
    ],
    ],
    'one arranging call' => ['store(1);', []],
    'the same calls in another order' => ['store(2); store(1);', ['calls:store(2)>store(1)' => 1]],
    'two arranging calls' => ['store(1); store(2);', ['calls:store(1)>store(2)' => 1]],
]);

it('reads no order token of arranging calls as an assertion', function (): void {
    $project = temporaryDirectory();
    mkdir($project . '/tests');
    file_put_contents($project . '/tests/ArrangeTest.php', <<<'PHP'
        <?php
        it('arranges', function (): void {
            store('status:expected');
            store('b');
        });
        PHP);

    expect(new FingerprintCache($project)->assertsNothing('tests/ArrangeTest.php :: it arranges'))->toBeTrue();
});

it('gives a literal passed straight to a call the position of its argument', function (string $code, array $expected): void {
    $statements = new ParserFactory()->createForHostVersion()->parse('<?php ' . $code);

    expect(TokenCollector::collect($statements))->toEqual($expected);
})->with([
    'argument of a call of an array element' => [
        '($this->actions[\'store\'])(true); ($this->actions[\'store\'])(true); ($actions[0])(1); (self::$actions[\'a\'][\'b\'])(2);',
        [
            'function:$actions[0]' => 1,
            'function:$this->actions[\'store\']' => 2,
            'function:self::$actions[\'a\'][\'b\']' => 1,
            'in:function:$actions[0]#0/value:1' => 1,
            'in:function:$this->actions[\'store\']#0/value:true' => 2,
            'in:function:self::$actions[\'a\'][\'b\']#0/value:2' => 1,
            'value:0' => 1,
            'value:\'a\'' => 1,
            'value:\'b\'' => 1,
            'value:\'store\'' => 2,
        ],
    ],
    'argument of a call of an array element named only at run time' => [
        '($actions[$name])(true); ($actions[])(true); ($this->{$name}[\'a\'])(true);',
        [
            'value:true' => 3,
            'value:\'a\'' => 1,
        ],
    ],
    'argument of a call of an array element whose key carries a token separator, a sign or a constant' => [
        '($actions[\'a/b\'])(true); ($actions[\'a#b\'])(true); ($actions[\'x:assertTrue\'])(true); ($actions[\'a%2Fb\'])(true);'
            . ' ($actions[-1])(true); ($actions[self::K])(true);',
        [
            'const:self::K' => 1,
            'function:$actions[-1]' => 1,
            'function:$actions[self%3A%3AK]' => 1,
            'function:$actions[\'a%2Fb\']' => 1,
            'function:$actions[\'a%23b\']' => 1,
            'function:$actions[\'a%252Fb\']' => 1,
            'function:$actions[\'x%3AassertTrue\']' => 1,
            'in:function:$actions[-1]#0/value:true' => 1,
            'in:function:$actions[self%3A%3AK]#0/value:true' => 1,
            'in:function:$actions[\'a%2Fb\']#0/value:true' => 1,
            'in:function:$actions[\'a%23b\']#0/value:true' => 1,
            'in:function:$actions[\'a%252Fb\']#0/value:true' => 1,
            'in:function:$actions[\'x%3AassertTrue\']#0/value:true' => 1,
            'value:-1' => 1,
            'value:\'a#b\'' => 1,
            'value:\'a%2Fb\'' => 1,
            'value:\'a/b\'' => 1,
            'value:\'x:assertTrue\'' => 1,
        ],
    ],
    'argument of a call of a property' => ['($this->store)(true); ($this->store)(true); ($this?->repository->store)(1); (self::$store)(2);', [
        'function:$this->store' => 2,
        'function:$this?->repository->store' => 1,
        'function:self::$store' => 1,
        'in:function:$this->store#0/value:true' => 2,
        'in:function:$this?->repository->store#0/value:1' => 1,
        'in:function:self::$store#0/value:2' => 1,
    ],
    ],
    'argument of a call of a property the test names at run time' => ['($this->{$name})(true); ($class::$store)(false);', [
        'value:false' => 1,
        'value:true' => 1,
    ],
    ],
    'argument of a call of a variable' => ['$store(true);', ['function:$store' => 1, 'in:function:$store#0/value:true' => 1]],
    'argument of a method call, a static call and a new expression' => ['$cart->add(1); Cart::make(-2); new Item(Size::Large);', [
        'call:add' => 1,
        'call:Cart::make' => 1,
        'in:call:add#0/value:1' => 1,
        'in:call:Cart::make#0/value:-2' => 1,
        'in:new:Item#0/const:Size::Large' => 1,
        'new:Item' => 1,
    ],
    ],
    'argument of a nullsafe method call' => ['$a?->handle(1); $a?->handle(1);', ['call:handle' => 2, 'in:call:handle#0/value:1' => 2]],
    'argument under a key' => ['store([\'limit\' => min(5)]);', [
        'function:min' => 1,
        'function:store' => 1,
        'in:function:store#0/key:value:\'limit\'' => 1,
        'in:function:store#0/value:\'limit\'/function:min#0/value:5' => 1,
    ],
    ],
    'first-class callable' => ['$trim = trim(...);', ['function:trim' => 1]],
    'literal inside an argument' => ['store([true], 1 + 2);', [
        'function:store' => 1,
        'in:function:store#0/value:list-item' => 1,
        'in:function:store#0/value:true' => 1,
        'in:function:store#1/non-literal' => 1,
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
        'in:function:json_encode#0/function:json_encode#0/key:value:\'test\'' => 1,
        'in:function:json_encode#0/function:json_encode#0/value:\'test\'/value:true' => 1,
        'in:function:json_encode#0/non-literal' => 1,
    ],
    ],
    'value in a call an assertion receives' => ['expect(add(1, 2))->toBe(3);', [
        'expect:function:add/call:toBe' => 1,
        'function:add' => 1,
        'function:expect' => 1,
        'in:expect:function:add/call:toBe#0/value:3' => 1,
        'in:function:add#0/value:1' => 1,
        'in:function:add#1/value:2' => 1,
        'read:function:add' => 1,
    ],
    ],
]);

it('counts the token value:[] once per empty array literal, however deeply it is nested', function (string $argument, int $count): void {
    $statements = new ParserFactory()->createForHostVersion()->parse('<?php $tags = ' . $argument . ';');

    expect(TokenCollector::collect($statements)['value:[]'])->toBe($count);
})->with([
    'nested empty array' => ['[[[]]]', 1],
    'one empty array' => ['[]', 1],
    'two empty arrays' => ['[[], []]', 2],
]);

it('gives a list the number of its items, so a repeated item counts', function (string $code, array $expected): void {
    $statements = new ParserFactory()->createForHostVersion()->parse('<?php ' . $code);

    expect(TokenCollector::collect($statements))->toEqual($expected);
})->with([
    'list of one variable read three times' => ['notify([$click, $click, $click]);', [
        'function:notify' => 1,
        'in:function:notify#0/value:list-item' => 3,
    ],
    ],
    'list that unpacks another array' => ['notify([...$clicks, $click]);', ['function:notify' => 1]],
    'map' => ['notify([\'a\' => $click]);', ['function:notify' => 1, 'in:function:notify#0/key:value:\'a\'' => 1]],
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
        'in:function:import#0/non-literal' => 1,
        'in:function:import#1/non-literal' => 1,
        'call:create' => 2,
        'call:Lists::factory' => 2,
        'function:import' => 1,
        'new:Batch' => 2,
        'expect:function:add/call:toBe' => 1,
        'function:expect' => 1,
        'function:add' => 1,
        'read:function:add' => 1,
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
            'in:function:import#0/non-literal' => 1,
            'in:function:range#0/value:1' => 1,
            'in:function:range#1/value:3' => 1,
            'loop:function:import' => 1,
            'loop:new:Batch' => 1,
        ],
    ],
    'literals in a loop body' => [
        'foreach ($ids as $id) { store([\'id\' => 5], null); }',
        [
            'in:function:store#0/key:value:\'id\'' => 1,
            'in:function:store#0/value:\'id\'/value:5' => 1,
            'in:function:store#1/value:null' => 1,
            'loop:function:store' => 1,
        ],
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
        'in:function:changedKeys#0/key:value:\'listId\'' => 1,
        'in:function:changedKeys#1/value:\'listId\'/value:1' => 1,
        'in:function:changedKeys#1/key:value:\'listId\'' => 1,
        'in:function:changedKeys#1/value:\'store\'/value:\'shop\'' => 1,
        'in:function:changedKeys#1/key:value:\'store\'' => 1,
        'in:function:changedKeys#1/keys:value:\'listId\'>value:\'store\'' => 1,
    ]);
});

it('counts a literal a test assigns once to a local variable where the test reads the variable', function (string $body, array $expected): void {
    $fingerprints = fingerprintsOf('<?php it(\'notifies\', function (string $parameter): void { ' . $body . ' });');

    expect($fingerprints['it notifies'])->toEqual($expected);
})->with([
    'parameter the test assigns a literal' => [
        '$parameter = \'a\'; notify($parameter);',
        ['function:notify' => 1, 'in:function:notify#0/non-literal' => 1, 'value:\'a\'' => 1],
    ],
    'variable a closure captures by reference' => [
        '$email = \'a\'; $notify = function () use (&$email): void { notify($email); };',
        ['function:notify' => 1, 'in:function:notify#0/non-literal' => 1, 'in:function:notify#0/variable:email' => 1, 'value:\'a\'' => 1],
    ],
    'variable a closure captures by value' => [
        '$email = \'a\'; $notify = function () use ($email): void { notify($email); };',
        ['function:notify' => 1, 'in:function:notify#0/value:\'a\'' => 1],
    ],
    'variable assigned a list with an object and read twice' => [
        '$contacts = [new Contact()]; notify($contacts); notify($contacts);',
        [
            'function:notify' => 2,
            'in:function:notify#0/non-literal' => 2,
            'in:function:notify#0/variable:contacts' => 2,
            'new:Contact' => 1,
            'value:list-item' => 1,
        ],
    ],
    'variable assigned a literal once' => ['$email = \'a\'; notify($email);', ['function:notify' => 1, 'in:function:notify#0/value:\'a\'' => 1]],
    'variable assigned a literal twice' => [
        '$email = \'a\'; $email = \'b\'; notify($email);',
        [
            'function:notify' => 1,
            'in:function:notify#0/non-literal' => 1,
            'in:function:notify#0/variable:email' => 1,
            'value:\'a\'' => 1,
            'value:\'b\'' => 1,
        ],
    ],
    'variable assigned a map and read once' => [
        '$options = [\'a\' => 1]; notify($options);',
        ['function:notify' => 1, 'in:function:notify#0/key:value:\'a\'' => 1, 'in:function:notify#0/value:\'a\'/value:1' => 1],
    ],
    'variable assigned an array with a key and read twice' => [
        '$data = [\'id\' => 5]; notify($data); notify($data);',
        ['function:notify' => 2, 'in:function:notify#0/key:value:\'id\'' => 2, 'in:function:notify#0/value:\'id\'/value:5' => 2],
    ],
    'variable assigned an object' => [
        '$contact = new Contact(); notify($contact);',
        ['function:notify' => 1, 'in:function:notify#0/non-literal' => 1, 'new:Contact' => 1],
    ],
    'variable the test appends to' => [
        '$emails = []; $emails[] = \'a\'; notify($emails);',
        [
            'function:notify' => 1,
            'in:function:notify#0/non-literal' => 1,
            'in:function:notify#0/variable:emails' => 1,
            'value:[]' => 1,
            'value:\'a\'' => 1,
        ],
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
        'in:function:client#0/key:value:\'a\'' => 1,
        'in:call:send#0/non-literal' => 1,
        'in:call:send#0/variable:id' => 1,
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
                'read:function:add',
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
        ->toBe(['in:call:add#0/value:-1', 'in:call:add#1/value:-2', 'in:expect:new:Calculator/call:add/call:toBe#0/value:-3'])
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

it('names the other tests that use the named dataset of a dataset row', function (): void {
    $project = temporaryDirectory();
    mkdir($project . '/tests');
    file_put_contents($project . '/tests/RowsTest.php', <<<'PHP'
        <?php
        dataset('rows', ['a' => [1], 'b' => [2]]);
        dataset('single', ['c' => [3]]);

        describe('reading', function (): void {
            it('reads', fn (int $row) => expect($row)->toBeInt())->with('rows');
        });
        it('writes', fn (int $row) => expect($row)->toBeInt())->with('rows')->group('write');
        it('is alone', fn (int $row) => expect($row)->toBeInt())->with('single');
        it('has its own rows', fn (int $row) => expect($row)->toBeInt())->with(['d' => [4]]);
        it('has no rows', fn () => true);
        PHP);
    $cache = new FingerprintCache($project);

    expect($cache->sharedDataset('tests/RowsTest.php :: `reading` → it reads › a'))->toEqual(new SharedDataset('rows', ['it writes']))
        ->and($cache->sharedDataset('tests/RowsTest.php :: it writes › b'))->toEqual(new SharedDataset('rows', ['`reading` → it reads']))
        ->and($cache->sharedDataset('tests/RowsTest.php :: it is alone › c'))->toBeNull()
        ->and($cache->sharedDataset('tests/RowsTest.php :: it has its own rows › d'))->toBeNull()
        ->and($cache->sharedDataset('tests/RowsTest.php :: it has no rows'))->toBeNull()
        ->and($cache->sharedDataset('tests/MissingTest.php :: it reads › a'))->toBeNull();
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

it('gives a non-literal argument of a call that is no assertion the token of its position', function (string $code, array $expected): void {
    $statements = new ParserFactory()->createForHostVersion()->parse('<?php ' . $code);

    expect(TokenCollector::collect($statements))->toEqual($expected);
})->with([
    'call of a variable' => [
        '$store($id);',
        ['function:$store' => 1, 'in:function:$store#0/non-literal' => 1, 'in:function:$store#0/variable:id' => 1],
    ],
    'call that an assertion reads' => [
        'expect(roleOf($user))->toBeTrue();',
        [
            'expect:function:roleOf/call:toBeTrue' => 1,
            'function:expect' => 1,
            'function:roleOf' => 1,
            'in:function:roleOf#0/non-literal' => 1,
            'in:function:roleOf#0/variable:user' => 1,
            'read:function:roleOf' => 1,
            'read:in:function:roleOf#0/non-literal' => 1,
            'read:in:function:roleOf#0/variable:user' => 1,
        ],
    ],
    'call that wraps a variable' => [
        'store(strtoupper($email));',
        [
            'function:store' => 1,
            'function:strtoupper' => 1,
            'in:function:store#0/function:strtoupper#0/non-literal' => 1,
            'in:function:store#0/function:strtoupper#0/variable:email' => 1,
            'in:function:store#0/non-literal' => 1,
        ],
    ],
    'literal, constant, list, map and enum case read' => [
        'store(1, Size::Large, [$id], [\'key\' => $id], Size::Large->value);',
        [
            'function:store' => 1,
            'in:function:store#0/value:1' => 1,
            'in:function:store#1/const:Size::Large' => 1,
            'in:function:store#2/value:list-item' => 1,
            'in:function:store#3/key:value:\'key\'' => 1,
            'in:function:store#4/const:Size::Large->value' => 1,
        ],
    ],
    'named argument' => [
        'store(enabled: $flag);',
        ['function:store' => 1, 'in:function:store#enabled/non-literal' => 1, 'in:function:store#enabled/variable:flag' => 1],
    ],
    'new expression' => ['store(new Money());', ['function:store' => 1, 'new:Money' => 1, 'in:function:store#0/non-literal' => 1]],
    'Pest assertion' => [
        'expect($actual)->toBe($expected);',
        ['function:expect' => 1, 'call:toBe' => 1, 'in:function:expect#0/variable:actual' => 1, 'in:call:toBe#0/variable:expected' => 1],
    ],
    'PHPUnit assertion' => [
        '$this->assertSame($expected, $actual);',
        ['call:assertSame' => 1, 'in:call:assertSame#0/variable:expected' => 1, 'in:call:assertSame#1/variable:actual' => 1],
    ],
    'property' => ['$campaign->add($campaign->id);', ['call:add' => 1, 'in:call:add#0/non-literal' => 1, 'in:call:add#0/property:id' => 1]],
    'variable' => ['store($id);', ['function:store' => 1, 'in:function:store#0/non-literal' => 1, 'in:function:store#0/variable:id' => 1]],
]);

it('lists no non-literal or name token of an argument of a call that the token list names already', function (): void {
    $difference = [
        'function:store',
        'in:function:store#0/non-literal',
        'in:function:store#0/property:enShop',
        'loop:call:Batch::import',
        'in:call:Batch::import#1/non-literal',
        'in:call:Batch::import#1/variable:rows',
        'in:function:store#1/function:wrap#0/non-literal',
        'in:function:load#0/non-literal',
        'value:1',
    ];

    expect(NonLiteralArguments::withoutImplied($difference, $difference))->toBe([
        'function:store',
        'loop:call:Batch::import',
        'in:function:store#1/function:wrap#0/non-literal',
        'in:function:load#0/non-literal',
        'value:1',
    ]);
});

it('names the variable or the property that an argument of a call or of an assertion passes', function (string $code, array $expected): void {
    $statements = new ParserFactory()->createForHostVersion()->parse('<?php ' . $code);

    expect(array_keys(TokenCollector::collect($statements)))->toEqualCanonicalizing($expected);
})->with([
    'nullsafe property passed to the code under test' => [
        'connect($shop?->url);',
        ['function:connect', 'in:function:connect#0/non-literal', 'in:function:connect#0/property:url'],
    ],
    'property cast passed to the code under test' => [
        'connect((int) $this->enShop);',
        ['function:connect', 'in:function:connect#0/non-literal', 'in:function:connect#0/property:enShop'],
    ],
    'property interpolated in a string passed to the code under test' => [
        'store("tag_{$campaign->name}");',
        ['function:store', 'in:function:store#0/non-literal', 'in:function:store#0/property:name', 'value:\'tag_\''],
    ],
    'property joined to a string passed to a PHPUnit assertion' => [
        '$this->assertSame(\'a\' . $x->title, $y);',
        ['call:assertSame', 'in:call:assertSame#0/property:title', 'in:call:assertSame#1/variable:y', 'value:\'a\''],
    ],
    'property of a variable passed by name' => [
        'articlesFor(articleId: $article->post_id);',
        ['function:articlesFor', 'in:function:articlesFor#articleId/non-literal', 'in:function:articlesFor#articleId/property:post_id'],
    ],
    'property passed to the code under test' => [
        'connect($this->enShop);',
        ['function:connect', 'in:function:connect#0/non-literal', 'in:function:connect#0/property:enShop'],
    ],
    'static property passed to the code under test' => [
        'connect(self::$enShop);',
        ['function:connect', 'in:function:connect#0/non-literal', 'in:function:connect#0/property:enShop'],
    ],
    'variable and property joined by operators passed to the code under test' => [
        'store($prefix . $campaign->title . \'_\');',
        [
            'function:store',
            'in:function:store#0/non-literal',
            'in:function:store#0/property:title',
            'in:function:store#0/variable:prefix',
            'value:\'_\'',
        ],
    ],
    'variable and property passed to a PHPUnit assertion' => [
        '$this->assertSame($this->redisConnection, $connection);',
        ['call:assertSame', 'in:call:assertSame#0/property:redisConnection', 'in:call:assertSame#1/variable:connection'],
    ],
    'variable inside a short ternary passed to the code under test' => [
        'store($name ?: \'none\');',
        ['function:store', 'in:function:store#0/non-literal', 'in:function:store#0/variable:name', 'value:\'none\''],
    ],
    'variable passed to a Pest assertion' => [
        'expect(linksOf($account))->toContain($canvaUserId);',
        [
            'expect:function:linksOf/call:toContain',
            'function:expect',
            'function:linksOf',
            'in:expect:function:linksOf/call:toContain#0/variable:canvaUserId',
            'in:function:linksOf#0/non-literal',
            'in:function:linksOf#0/variable:account',
            'read:function:linksOf',
            'read:in:function:linksOf#0/non-literal',
            'read:in:function:linksOf#0/variable:account',
        ],
    ],
    'variables and a property inside a ternary passed to the code under test' => [
        'store($primary ? $this->enShop : $fallback);',
        [
            'function:store',
            'in:function:store#0/non-literal',
            'in:function:store#0/property:enShop',
            'in:function:store#0/variable:fallback',
            'in:function:store#0/variable:primary',
        ],
    ],
    'variables inside a unary operator passed to the code under test' => [
        'store(-$debit, +$credit, !$active, ~$mask);',
        [
            'function:store',
            'in:function:store#0/non-literal',
            'in:function:store#0/variable:debit',
            'in:function:store#1/non-literal',
            'in:function:store#1/variable:credit',
            'in:function:store#2/non-literal',
            'in:function:store#2/variable:active',
            'in:function:store#3/non-literal',
            'in:function:store#3/variable:mask',
        ],
    ],
]);

it('tells an assertion from the code under test by the call a name token belongs to', function (string $token, bool $isAssertion): void {
    expect(TokenCollector::isAssertionToken($token))->toBe($isAssertion);
})->with([
    'variable passed to an assertion' => ['in:expect:function:linksOf/call:toContain#0/variable:canvaUserId', true],
    'variable passed to the code under test whose name starts like an assertion' => ['in:function:store#0/variable:expected', false],
]);
