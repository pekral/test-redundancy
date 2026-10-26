# Changelog

All notable changes to `pekral/test-redundancy` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed

- A test that passes a literal where its covering test passes a closure is no longer `merge-into-dataset`. Before, a
  closure argument carried only the `non-literal` token of its position and the value it returns, so `foo('a')`
  against `foo(fn () => 1)`, `foo(['k' => 'a'])` against `foo(['k' => fn () => 1])` and `foo(['a'])` against
  `foo([fn () => 1])` had a `differsBy` with a literal only and nothing in `coveringDiffersBy`. A row built from
  `differsBy` would pass the literal to a slot that receives a closure in the covering test (#134). Now a closure or an
  arrow function that is an argument of a call, a `new` expression or an assertion carries the call and the position
  followed by `closure`, e.g. `in:function:foo#0/closure`, and a closure inside an array carries its place and the
  prefix `element:`, e.g. `in:function:foo#0/value:'k'/element:closure`, as a variable does (#116). Such a token is not
  data, so the candidate is `review` and `coveringDiffersBy` lists it. This holds for `function () { return 1; }`,
  `static fn () => 1`, a named argument, a method call, a `new` expression, a nested key, a list item and an argument
  of an assertion. Two tests that pass a closure at the same place carry the same token, so `foo(fn () => 'a')`
  against `foo(fn () => 1)` stays `merge-into-dataset`. A closure that a dataset row passes to a parameter of the test
  is no closure argument of the body, so the type of the parameter decides as before (#95). A closure that is an
  argument of an assertion keeps its source code in a `bound:` token, as before.
- A test that pairs the same subjects with swapped values, where a subject carries no token of its own, is no longer
  `delete`. Before, the pairing of a subject with its value was lost in these shapes, so
  `expect('5')->toBe(5); expect('6')->toBe(6);` and `expect('5')->toBe(6); expect('6')->toBe(5);` had the same tokens,
  and one of them was `delete`. The same held for variables as subjects (`expect($a)->toBe(1)`), calls on other
  variables as subjects (`expect(json_encode($a))->toBe('x')`), variables as array keys
  (`store([$a => 1, $b => 2])`) and closures that return literals (`store(fn () => 1, fn () => 2)`). Now:
  - A variable that the test does not assign once carries its name wherever a property does: as an assertion subject,
    e.g. `expect:variable:a/call:toBe` and `in:expect:variable:a/call:toBe#0/value:1`, and as an array key, e.g.
    `in:function:store#0/variable:a/value:1` and `in:function:store#0/keys:variable:a>variable:b`. These swapped pairs
    are `merge-into-dataset`, and variable keys that swap places are `review`.
  - When a test has two or more assertions with different subjects that carry no token, such as literals, constants,
    element reads, lists, maps or enum case reads such as `Status::A->value`, each argument of these assertions
    carries its subject in its path: a literal, a constant or an empty array by its token, any other expression by
    its source code, e.g. `in:expect:value:'5'/call:toBe#0/value:5`, `in:expect:$r['a']/call:toBe#0/value:1`,
    `in:expect:[1]/call:toBe#0/value:3` and `in:expect:Status::A->value/call:toBe#0/value:1`. The swapped pair is
    `merge-into-dataset`. A test with one such subject
    keeps `in:call:toBe#0/value:5`. A test with two or more such subjects is now `review`, not
    `merge-into-dataset`, against a covering test that asserts other such subjects. The last argument of a PHPUnit
    `assert*` call is the subject when every argument other than the failure message is a literal or a constant, so
    `$this->assertSame(5, '5')` has the subject `'5'`. A variable as the failure message is never the subject.
  - A `bound:` token (#96) keeps every argument of the subject and of the assertion, not only a literal. An argument
    that holds no literal carries the names it holds, or else its source code, e.g.
    `bound:expect:function:json_encode#0:variable:a/call:toBe#0:'x'` and
    `bound:expect:function:json_encode#0:bar($a)/call:toBe#0:'x'`. An argument that holds a literal carries its source
    code, e.g. `bound:expect:function:strlen#0:$r['a']/call:toBe#0:1` and
    `bound:expect:function:json_encode#0:[$a, 1]/call:toBe#0:'x'`. It also keeps a literal or a constant as the
    receiver of the subject, e.g. `bound:expect:const:Status::A/call:label/call:toBe#0:'a'`. The value that a cast
    subject casts is kept by its token, or by its source code when it carries no token of its own, e.g.
    `bound:expect:cast:int/$r['a']/call:toBe#0:1`, `bound:expect:cast:string/Status::A->value/call:toBe#0:'1'` and
    `bound:expect:cast:object/[1]/call:toEqual#0:1`. These swapped pairs are `review`.
  - A closure or an arrow function passed to a call passes on the value it returns at the place of its argument, e.g.
    `in:function:store#0/value:1` for `store(fn () => 1)`. A closure as the subject of an assertion stands for the
    value it returns, so `expect(fn () => import(1))->toThrow(A::class)` has the subject `function:import`. The value
    a closure returns is the expression of each of its own `return` statements.

  Some shapes stay `delete`, among them these. A closure that the test assigns to a variable and then passes on, such as
  `$f = fn () => 1; $g = fn () => 2; store($f, $g);` against the same test with the values swapped, carries no place.
  An operator that a closure returns carries no token, as #131 states, so `store(fn () => $x + 1, fn () => $x + 2)`
  against the closures swapped is `delete`. A call that is not an assertion and pairs any argument with a literal in its
  arguments, such as `store($a, 1); store($b, 2);`, `store('a', 1); store('b', 2);` or
  `store(foo($a), 1); store(foo($b), 2);` against the same calls with the literals swapped, has no pairing token (#133).
  This holds for a method call, a `new` expression and named arguments as well. This release does not change other
  shapes that stay `delete` against the same test with the values swapped either, among them these four. A property or
  a method call on an element read, and a property of a call, such as `$u[0]->name`, `$r['a']->get()` next to
  `$r['b']->get()` or `foo()->a` next to `bar()->a`, give both subjects the same token, e.g. `property:name`. Array keys
  other than a literal, a constant, a variable or a property, such as `store([$r['a'] => 1, $r['b'] => 2])` or
  `store([foo($a) => 1, foo($b) => 2])`, give the values under them no place of their own. The same call asserted
  twice, such as `expect($s->next())->toBe(1); expect($s->next())->toBe(2);` or `$a = foo(); $b = foo();
  expect($a)->toBe(1); expect($b)->toBe(2);`, gives both subjects the same token. The closures of a Pest `sequence()`,
  such as `expect([1, 2])->sequence(fn ($e) => $e->toBe(1), fn ($e) => $e->toBe(2))`, carry no place.
- A test that spreads an argument of a call that is not an assertion where its covering test passes it plain, or the
  other way round, is no longer `delete`. Before, a spread argument carried the same tokens as the plain argument, so
  `foo(...$x)` and `foo($x)` had the same tokens, and one of them was `delete`. Now the spread of an argument of a call
  or an assertion is the operator `spread` with the spread value as operand 0, e.g. `in:function:foo#0/operator:spread`
  and `in:function:foo#0/operator:spread#0/operand:variable:x` for `foo(...$x)`. This holds for a variable or a
  property, and for an argument that carries no name, such as `foo(f())` and `foo(...f())`, `foo([1, 2])` and
  `foo(...[1, 2])`, or `foo(Foo::ITEMS)` and `foo(...Foo::ITEMS)`. When only the covering test spreads such an argument,
  the candidate is `review` and its `coveringDiffersBy` names the spread. Three shapes stay `delete`. A spread inside a
  list, such as `foo([...$x, ...$y])` against `foo([...$y, ...$x])`, carries no token. A spread that only the covering
  test has, around an argument of an assertion that carries no name, such as `expect(f())->toBe(1)` covered by
  `expect(...f())->toBe(1)`, gives the code under test the same input. An operator other than a spread that only the
  covering test has, around an argument that carries no name, such as `foo(f())` covered by `foo(f() . 'a')`, stays
  `delete` as in #131 (#132).
- A test whose argument holds another operator, the same names at other places, or fewer operands than its covering
  test is no longer `delete`. Before, an operator carried no token and a name inside it carried no place. So
  `foo($x + 1)` and `foo($x - 1)`, `foo(!$x)` and `foo(-$x)`, `foo(1 + 2)` and `foo(1 - 2)`, `foo($x . $y)` and
  `foo($y . $x)`, and `toBe($c ? $x : $y)` and `toBe($c ? $y : $x)` had the same tokens, and one of each pair was
  `delete`. `foo($x . $y)` covered by `foo($x . $y . $z)` was `delete` too. Now an operator in an argument of a call
  or an assertion carries `operator:<kind>` at its place, e.g. `in:function:foo#0/operator:plus`. A name inside it
  carries the place of its operand before the prefix `operand:`, e.g.
  `in:function:foo#0/operator:concat#1/operand:variable:y` for `$y` in `foo($x . $y)`. A literal or a constant operand
  carries its place before its own token, e.g. `in:function:foo#0/operator:minus#0/value:1` for `1` in `foo(1 - 2)`. A
  ternary numbers its condition 0, the value if true 1 and the value if false 2, and an operator inside an operator
  adds its own place, outermost first. These candidates are `review`. So are `foo(1 + 2)` covered by
  `foo(1 + 2 + 3)`, `foo(f() . 'a')` covered by `foo(f() . 'a' . 'b')`, and properties that swap places inside an
  operator, such as `transfer($a->id . $b->id)` against `transfer($b->id . $a->id)`. Literal operands that swap
  places, such as `foo(1 - 2)` against `foo(2 - 1)`, are `merge-into-dataset`. An operator in a list now takes part in
  the order of the list by its source code, e.g. `in:function:fullName#0/items:$first . $last>variable:title`, so
  `fullName([$first . $last, $title])` against `fullName([$title, $first . $last])` is `review` too. Three shapes stay
  `delete`. An operator in no argument of a call or an assertion, such as one that a closure returns or one assigned
  to a variable that the test passes on, carries no token. An operator that only the covering test has, around an argument that carries no name,
  such as `foo(f())` covered by `foo(f() . 'a')`, does not turn the candidate into `review`, like a name. An operator
  other than a binary operator, a ternary, an interpolated string or a unary `-`, `+`, `!` or `~`, such as
  `instanceof`, carries no token (#131).
- A test that passes or expects the same properties of other or swapped objects as its covering test is no longer
  `delete` when each property with a written-out name is a whole argument, a list element, an array key or value, an
  assertion subject or an expected value. Before, the name token of a property carried no token for its object. So `transfer([$a->id, $b->id])`
  and `transfer([$b->id, $a->id])` had the same tokens, and one of them was `delete`. The same held for
  `transfer($a->id)` against `transfer($b->id)` and for `expect($a->id)->toBe(1)` against `expect($b->id)->toBe(1)`. Now
  the name of a property also names its object when the object is a variable, a property or a class, e.g.
  `variable:a/property:id`, `variable:this/property:shop/property:url` and `property:self::enShop`. The order tokens of
  a list and of the keys of a map use this name, e.g.
  `in:function:transfer#0/items:variable:a/property:id>variable:b/property:id`, so these candidates are `review`. A
  property as an array key now also names the values under it with this name instead of `call:<name>`, e.g.
  `in:function:transfer#0/variable:a/property:id/value:1`. So `transfer([$a->id => 1, $b->id => 2])` against
  `transfer([$a->id => 2, $b->id => 1])` is `merge-into-dataset`, not `delete`. A nullsafe or a static property as the
  subject of an assertion is named the same way, so `$x = $a?->id; expect($x)->toBe(1)` against
  `$x = $b?->id; expect($x)->toBe(1)` is `review`. Two shapes stay `delete`. A property of an object that has no name,
  such as the result of a call in `$a->get()->id` or an element read in `$a['x']->id`, keeps `property:<name>` alone, so
  `transfer([$a->get()->id, $b->get()->id])` against `transfer([$b->get()->id, $a->get()->id])` is `delete`.
  A property whose name is not written out, such as `$a->$field` or `$a->{'id'}`, has no name token, so
  `transfer($a->$field)` against `transfer($b->$field)` is `delete` too (#130).

## [0.1.6] - 2026-10-26

### Fixed

- A test is no longer `delete` or `merge-into-dataset` when one of the two tests holds a variable or a property
  inside an operator, a ternary or an interpolated string and the other passes the name as the whole argument. Before, a
  name inside such an argument carried the same token as the name passed alone, e.g.
  `in:function:foo#0/variable:x` for both `foo($x . $y)` and `foo($x)`, so `foo($x)` was `delete` against
  `foo($x . $y)`, `expect(f())->toBe($x)` was `delete` against `expect(f())->toBe($c ? $x : 1)`, and
  `expect(f())->toBe($x . 'a')` was `merge-into-dataset` against `expect(f())->toBe($x)`. Now such a name has the
  prefix `operand:`, e.g. `in:function:foo#0/operand:variable:x`, also inside a unary operator such as `-$x` or `!$x`,
  and these candidates are `review`. The prefix combines with `element:` in the order the name is reached, so
  `foo([$x . $y])` has `in:function:foo#0/value:list-item/element:operand:variable:x` and is no longer `delete`
  against `foo([$x])`. This entry does not change a name inside a cast: it keeps its plain token, e.g.
  `in:function:foo#0/variable:x` for `foo((string) $x)` (#123).
- A test whose covering test holds a variable or a property at another place inside the same argument is no longer
  `merge-into-dataset` or `delete`. Before, a name inside an array carried only the place of the argument, so
  `toBe(['a' => $x, 'b' => 1])` and `toBe(['a' => 1, 'b' => $x])` had the same name token, the candidate was
  `merge-into-dataset`, and `transfer(['from' => $a, 'to' => $b])` against `transfer(['from' => $b, 'to' => $a])`
  was `delete`. Now the name also carries its place inside the argument: the key of each array it sits under, or
  `value:list-item` for an element of a list, e.g. `in:expect:function:f/call:toBe#0/value:'a'/element:variable:x`. A
  key that is a variable or a property names the value under it, e.g. `.../variable:k/element:variable:x` for
  `[$k => $x]`. These candidates are `review`. A name token or a `non-literal` token whose path crosses the key of an
  array, such as `in:function:foo#0/value:'k'/function:bar#0/variable:x` for `foo(['k' => bar($x)])`, is no longer
  read as data, so it is listed in `coveringDiffersBy` and the candidate against `foo(['k' => bar('a')])` is
  `review`, not `merge-into-dataset`. This also holds when the key contains `#` or a newline, such as
  `foo(['a#b' => $x])`. A variable or a property in a list now takes part in the order of the list elements by its
  name, e.g. `in:function:transfer#0/items:variable:a>variable:b`, so `transfer([$a, $b])` against
  `transfer([$b, $a])`, and `transfer([$a, 1])` against `transfer([1, $a])`, are `review`, not `delete` (#122).
- A test that calls the code under test once is no longer `delete` against a test that calls it twice through a
  callable held in a class constant. Before, a call such as `(self::HANDLER)('acme')` or
  `(Actions::ALL['cleanUp'])('acme')` carried no token, so two identical calls gave the same tokens as one call, and the
  test with one call was `delete`. Now such a call carries `function:` and the constant as written, with `:` escaped
  like the key of an array element: `function:self%3A%3AHANDLER` or `function:Actions%3A%3AALL['cleanUp']`. The
  fingerprint counts the call, so the candidate is `review`, and its arguments carry a position. Because `:` is
  escaped, a constant named like an assertion, such as `self::assertReady`, is not read as an assertion. A constant
  named only at run time, such as `($class::HANDLER)()`, still carries no token (#120).
- A `merge-into-dataset` candidate whose covering test is a row of a dataset test no longer asks to turn that test into
  a dataset test again. The first step is now `Add a row with the values in differsBy to the dataset of "<test>".`, with
  the name of the dataset test without the row. A covering test that is not a dataset row keeps the step `Turn "<test>"
  into one test with a dataset and add a row with the values in differsBy.`. A test whose values equal a row of the
  covering dataset is now `delete`, covered by that row, and the row is no longer a candidate covered by that test.
  Before, a row that runs fewer lines than such a test was `delete` covered by the test, and the test was
  `merge-into-dataset` into another row, so following both proposals deleted the row and added it back (#118).
- A test that has a cast its covering test lacks is no longer `delete` or `merge-into-dataset`. Before, a
  cast carried no token, so `store([(object) ['code' => null]])` and `store([['code' => null]])` had the same tokens,
  and a `merge-into-dataset` row built from `differsBy` dropped the cast and passed an array instead of a `stdClass`.
  Now a cast carries a token for its type at its place, e.g. `in:function:store#0/cast:object`, so a cast that moves to
  another argument, property or list item also differs. Each token inside the cast carries the cast in its path, and in
  an argument also the place of the argument, e.g. `in:function:store#0/cast:object/key:value:'code'` for
  `store((object) ['code' => null])`, so `store((int) '5', (int) '6')` and `store((int) '6', (int) '5')` are
  `merge-into-dataset`, never `delete`. An alias carries the token of its type, so `(integer)` carries `cast:int`, and
  `(double)` carries `cast:float` and is ordered as `(float)`. The token is listed in `differsBy`, and the candidate is
  `review`. A cast that only the covering test has in an argument of a call that is not an assertion, such as
  `store((string) $x)` against `store($x)`, is listed in `coveringDiffersBy`, and the candidate is `review`. The variable or property inside a cast keeps its name token, so
  `connect((int) $this->enShop)` still carries `in:function:connect#0/property:enShop`. A cast that an assertion
  receives as its subject, or that is an array key, carries the cast together with what it casts, e.g.
  `expect:cast:string/variable:response/call:body/call:toBe` for `expect((string) $response->body())->toBe('x')` and
  `in:function:store#0/cast:string/const:Foo::A/value:1` for `store([(string) Foo::A => 1])`, so two such tests with
  swapped values are `merge-into-dataset`, never `delete`. A cast in a list is ordered by its source, like an array or
  a call with arguments (#117).
- A test is no longer `merge-into-dataset` when the covering test fills the differing slot of an assertion with an array
  that holds a variable or a property. Before, the key `$subscriber->email` in `expect($response->updatedEmails())->toBe([$subscriber->email => ['field1' => 'value1']])`
  carried no token, so a candidate with `toBe([])` was `merge-into-dataset`, although a dataset row cannot hold the key.
  Now each variable or property that is a key or a value of an array that an assertion receives, at any depth, carries
  `element:variable:` or `element:property:` at the position of that argument, such as
  `in:expect:variable:response/call:updatedEmails/call:toBe#0/element:property:email`. The token is listed in
  `coveringDiffersBy`, and the candidate is `review`. The same holds for an array that a call other than an assertion
  receives, such as `store(['key' => $id])`, and for the variable or property that an array element read holds, such as
  `$row` in `toBe($row['email'])`. The `element:` prefix keeps such a name apart from the same name passed as the
  whole argument, so `toBe([$x])` and `toBe($x)` differ, and neither test is `delete` against the other. An enum case read, such as `Status::Active->value`, stays a value and carries no
  name, also inside an operator or a ternary, so a covering test with `connect(Status::Active->value . 'x')` is
  `merge-into-dataset` against `connect('a' . 'x')`, because a dataset row holds the enum value (#116).
- A test that calls the code under test once is no longer `delete` against a test that calls it twice through a
  callable held in an array element. Before, a call such as `($this->actions['cleanUp'])('acme')` carried no token, so
  two identical calls gave the same tokens as one call, and the test with one call was `delete`. Now such a call
  carries `function:` and the callable as written, e.g. `function:$this->actions['cleanUp']` or `function:$actions[0]`,
  like the call of a callable held in a property of #103. The fingerprint counts the call, so the candidate is
  `review`, and its arguments carry a position. The key is written like a `value:` or `const:` token without its
  prefix, so a literal integer, a literal string, a negative number and a constant key all carry a token, e.g.
  `function:$actions[-1]`. In the key, `%`, `/`, `#` and `:` are written as `%25`, `%2F`, `%23` and `%3A`, because
  `/`, `#` and `:` separate the parts of a token: `($this->commands['import:products'])('acme')` carries
  `function:$this->commands['import%3Aproducts']`, and a key such as `'x:assertTrue'` is not read as an assertion. A
  call through a key or a receiver named only at run time, such as `($this->actions[$name])()`, still carries no
  token (#114).
- A test whose interpolated string has another literal text than its covering test is no longer `delete`. Before,
  the literal parts of an interpolated string carried no token, so `toBe("Hello {$this->user->name}")` and
  `toBe("Hi {$this->user->name}")` had the same tokens, and the candidate was `delete` with an empty `differsBy`. Now
  each literal part carries a `value:` token, like a string joined with `.`: `"Hello {$this->user->name}"` has
  `value:'Hello '`. The two tests differ in `differsBy`, and the candidate is `review` (#110).
- A test that calls the code under test once is no longer `delete` or `merge-into-dataset` against a test that calls
  it twice through a callable held in a property. Before, a call such as `($this->cleanUp)($this->account)` or
  `(self::$resolve)('campaign_{name}', $campaign)` carried no token, so two identical calls gave the same tokens as
  one call. A test that runs the cleanup once and asserts the state was `delete`, covered by a test that runs it twice,
  and no test asserted the state after a single call. Now such a call carries `function:` and the callable as written,
  e.g. `function:$this->cleanUp`, `function:$this?->actions->cleanUp` or `function:self::$resolve`, like the call of a
  variable `function:$action` of #102. The fingerprint counts the call, so the covering test that makes it twice lists
  it in `coveringDiffersBy`, and the candidate is `review`. Its arguments carry a position too. A nullsafe method call
  such as `$this->action?->handle('acme')` carried no token either. It now carries `call:handle`, like the method call
  `$this->action->handle('acme')`, and an assertion on its result names the same receiver. A callable that the test
  names only at run time, such as `($this->{$name})()`, still carries no token (#103).
- A `merge-into-dataset` candidate is now `review` when it passes an optional argument that its covering test omits.
  Before, a candidate such as `new PredictedData(date: $date, newCustomersForecastCount: 24)` was merged into the
  covering test `new PredictedData(date: $date)`, which relies on the default `= null`. A dataset row passes every
  argument, so the case "the argument is omitted, the default applies" disappeared, and `coveringDiffersBy` did not
  name the omission. The same held for a positional argument, such as the third argument of
  `($action)('john@example.com', 20, $events)` against `($action)('john@example.com', 20)`. Now the action is
  `review`, and a new step lists the arguments of the candidate that the covering test omits and says to merge only when
  the row passes them as a spread array that is empty for the covering row. A call of a variable, such as
  `$action(...)` or `($action)(...)`, has the token `function:$action` now, so its arguments carry a position like the
  arguments of a named function (#102).
- The order of the calls that arrange a test is part of its fingerprint. Before, the tokens of a test did not depend on
  the order of its statements, so two tests that create the same records in another order, such as
  `JsonFloatScopeTest` with the matching row first and the row with `null` last, had the same tokens. One of them was
  `delete` with an empty `differsBy`, and the order the test protects was lost. Now a test body with two or more calls
  that carry literal input and are not assertions has one token for each pair of these calls, the earlier call first,
  like the list-order tokens of #98, e.g. `calls:store(1)>store(2)` for `store(1); store(2);`. A call is named by its
  normalized source code without the variable it is assigned to, so two tests that make the same calls in the same
  order under other variable names still match. Tests and dataset rows that make the same calls in another order differ
  in `differsBy`, and the candidate is `review`. `differsBy` lists an order token only when the covering test makes the
  same two calls in the other order, so a test that makes another or an extra call keeps its action. An order token is
  never read as an assertion (#101).
- A variable or a property that a test passes to a call or to an assertion now carries a token that names it. Before,
  an argument that was not a literal carried only the `non-literal` token of its position, and an argument of an
  assertion carried nothing, so two tests that pass different objects, such as `connect($this->enShop)` and
  `connect($this->mainShop)`, or expect different variables, such as `toContain($canvaUserId)` and
  `toContain($otherCanvaUserId)`, looked the same when their literals matched, and the candidate was `delete` or
  `merge-into-dataset`. Now the argument carries the call, the position and `variable:<name>` or `property:<name>`,
  such as `in:function:connect#0/property:enShop` and
  `in:expect:function:linksOf/call:toContain#0/variable:canvaUserId`, so such tests differ in `differsBy` and the
  candidate is `review`. A `merge-into-dataset` candidate is also `review` when its covering test passes a variable or
  a property where the candidate passes a literal, such as `dispatchBulkStep($account->name)` against
  `dispatchBulkStep('happypet')`. A static or a nullsafe property, such as `self::$enShop` or `$shop?->url`, is named
  as a property, and each variable or property inside an operator, a cast, a ternary or an interpolated string that is
  the argument is named at the position of that argument, such as
  `in:expect:function:resolveUtm/call:toBe#0/property:title` for `toBe('no_segment_tag_' . $campaign->title)` or
  `toBe((string) $campaign->title)`. A parameter of the test closure or test method, which a dataset row
  fills, carries no name (#100).
- A `delete` candidate no longer ignores an arrangement that only its covering test has outside the literal arguments
  of the code under test. Before, `coveringDiffersBy` listed only the array keys and the literal arguments, so a
  covering test that arranges a mock that throws (`->andThrow(new InvalidDomain())`), a wrapping call
  (`strtoupper($email)`), an extra arrangement call (`createVerifiedDomain()`, `ob_start()`), or an extra argument held in
  a variable (`$url = fake()->url()`, `$campaign->id`) left the candidate `delete` with an empty `differsBy` and an empty
  `coveringDiffersBy`, and the input class of the candidate was lost. Now `coveringDiffersBy` lists every call,
  function and `new` expression that the covering test makes and the candidate does not, or makes more times, and the
  candidate is `review`. An argument of a call that is not an assertion, whose value is not a literal, carries a new
  `non-literal` token with the call and the position, such as `in:function:logEvent#3/non-literal`, so an argument
  position that only the covering test fills is listed too. A call that the covering test only reads in an assertion
  it adds, such as `expect(activeState())` or `assertSame(5, $order->total())`, is not an arrangement, so a candidate
  with the same arrangement stays `delete`. A `merge-into-dataset` candidate keeps its action when the covering test
  takes the position as a parameter (#99).
- A failing test case no longer discards the coverage of the whole `analyse` run. Before, `CoverageRun` threw
  `ProcessFailedException` on the first worker group with a non-zero exit code, so the coverage of every other group
  was lost and the measured durations were not recorded. Now `analyse` leaves every failed or errored test case out of
  the analysis, so it is never a candidate and never a covering test, and it records the durations of every group that
  finished. The report lists the left-out test cases with the failure message in the new `failedTests` field of each
  file, counts them in `summary.failedTests`, and the text format prints a `Failed tests` list. `analyse` exits with `1`
  while a test case fails. A process that ends without a coverage file or a JUnit log still aborts the run, and `map`
  and `verify` still abort when a test case fails (#93).
- A test is no longer `merge-into-dataset` into a dataset row when a value in its `differsBy` cannot become row data.
  Before, the steps asked to add a row with the values in `differsBy` even when the covering closure hard-codes the
  value in its body, does not pass the argument at all, or declares a parameter type that does not take the value, such
  as `null` for `int $reminderCode`. Such a row lands in another parameter, is dropped, or throws a `TypeError`, so the
  case of the candidate was lost. Now each value in `differsBy` must sit at a position that a parameter of the covering
  closure or test method reaches, and the type of that parameter must take the value; otherwise the action is `review`.
  A slot the body hard-codes is never row data, also in a list that the body builds around a parameter, such as the `5`
  in `f([$n, 5])` (#95).
- An assertion keeps the literal arguments of its subject when the test asserts that subject with two or more
  different argument lists. Before, the subject kept the calls of its chain but not their arguments, so two tests that
  expect the same values for swapped arguments, such as `$mapping->get(1)` → `Column::VALUE` and `$mapping->get(2)` →
  `Column::MIN_SPEND` against the reverse, had the same assertion tokens. `differsBy` listed only the inputs, and a
  `merge-into-dataset` row built from it kept the expectations of the covering test, which contradict the candidate.
  Now each such assertion also has a `bound:` token with the literal arguments of the subject and of the assertion,
  e.g. `bound:expect:variable:mapping/call:get#0:1/call:toBe#0:Column::VALUE`, also for a named argument such as
  `pipelineId: 99`. The token leaves out the failure message of the assertion, so two tests that pair the same
  arguments with the same values under another message stay `merge-into-dataset`. `differsBy` names the assertion
  that differs, and the candidate is `review` (#96).
- A `merge-into-dataset` candidate whose expected value is smaller than the expected value of its covering test is
  `review`. Before, an expected list without an element, an expected array without a key, or an assertion without an
  argument that only the covering test passes produced no token: `differsBy` named only the inputs and the values both
  tests expect, and a row built from it kept the larger expectation of the covering test, so the point of the candidate
  was lost. Now `coveringDiffersBy` lists the longer expected list
  (`in:expect:function:resolve/call:toEqualCanonicalizing#0/value:list-item`), the expected key the candidate lacks
  (`in:expect:new:Context/call:toArray/call:toBe#0/key:value:'impersonator_email'`), and the assertion argument at a
  position where `differsBy` lists no value (`in:call:toThrow#1/value:'vocatives_cz.csv:2'`). A new step says to merge
  the candidate only into a row that takes its whole expected value as a value of its own. A key nested in an expected
  value that `differsBy` already lists, a list whose whole expected value `differsBy` already lists as empty (`value:[]`),
  the failure message of an assertion, and an expected value that a parameter of the covering dataset row reaches do not
  count. This replaces the behaviour of #79 for a `merge-into-dataset`
  candidate: a key that the covering test passes only to an assertion now makes it `review`. A `delete` candidate is
  unchanged, so a covering test that only asserts more about the same input still leaves it `delete` (#97).
- The order of the elements of a list literal is part of its fingerprint. Before, two dataset rows or tests that pass
  the same list in another order, such as `['email', 'a', 'b']` and `['a', 'b', 'email']`, or `[3, 5]` and `[5, 3]`,
  had the same tokens, so one of them was `delete` with an empty `differsBy`, and the boundary it protects, such as the
  first or the last index, was lost. Now a list with two or more elements has one token for each pair of its elements,
  the earlier element first, like the key-order tokens of #87, e.g. `in:function:store#0/items:value:3>value:5` for
  `store([3, 5])`. A non-empty array, and a call or a `new` expression with arguments, is named by its normalized
  source code, e.g. `items:new Money(1)>new Money(2)`, so a list of rows or of objects counts its order too, and the
  same data written with other quotes, another integer base, another constant case or a comment stays the same
  element. Rows and tests whose lists differ only in order differ in `differsBy`, and the candidate is `review`.
  `differsBy` lists an order token only when the covering test has the same two elements in the other order, so a
  test whose list has another or an extra element of any kind stays `merge-into-dataset`, and a test that swaps
  elements the covering test also has is `review`, also when another value differs (#98).

## [0.1.5] - 2026-10-24

### Fixed

- A test that passes exactly the values of a row of a dataset is `delete`, covered by that row, not `merge-into-dataset`
  against another row of the dataset. Before, the covering test was the first test that runs the same lines, so the row
  `December 25th` covered a test that passes the values of the row `June 15th`, and the steps asked to add a row that
  already exists. Now a single test that runs every line of the candidate and has an empty `differsBy` is preferred as
  `coveredBy`. It is a kept test, or a test that the analysis removes after the candidate, so the covering test is never
  a test that is removed before the candidate. The fixture reports `it adds one and two` and `testGreetsJane` as `delete`
  (#88).
- The order of the keys of an array literal is part of its fingerprint. An array with two or more keys has one token
  for each pair of its keys, the earlier key first, e.g. `in:function:store#0/keys:value:'a'>value:'b'` for
  `store(['a' => 1, 'b' => 2])`. Two dataset rows that pass the same array with the keys in another order differ in
  `differsBy`, so such a row is no longer reported with the action `delete`. The order tokens of the covering test are
  not listed in `coveringDiffersBy`, and an order token of a key that the covering test lacks is not listed in
  `differsBy`, because that key is listed already. Every array with two or more keys gets new tokens (#87).
- A row of a named dataset (`dataset('name', …)`) that another test of the same file also uses with `->with('name')` is
  `review`, not `delete`, because deleting the row removes it from every test that uses the dataset. The first step
  names the dataset and the other tests, and it is the first step of every other action of such a row too (#87).
- The subject of an assertion on a method call carries the receiver of that call. `expect($moment->format('Y-m-d'))`
  has the subject `variable:moment/call:format`, and `expect($interval->applyTo($moment)->format('Y-m-d'))` has
  `call:applyTo/call:format`, so a test that asserts its input is no longer `merge-into-dataset` into a test that
  asserts the result of a call on that input. Its `differsBy` lists the assertion on the other receiver, e.g.
  `expect:variable:moment/call:format/call:toBe`, and the candidate is `review`. Before, both subjects were
  `call:format`. Only the nearest receiver is kept, and a literal or a constant receiver adds nothing, e.g.
  `Status::Active->label()` keeps `call:label`. Every assertion on a method call gets new tokens:
  `expect(app(Templates::class)->create())` has `expect:function:app/call:create/…` instead of `expect:call:create/…`
  (#86).

## [0.1.4] - 2026-10-24

### Fixed

- An enum case read through `->value` or `->name` as an array key inside a call argument keeps the call path and the
  `key:` prefix, like a literal key. `store([CouponType::FIXED->value => true])` has the tokens
  `in:function:store#0/key:const:CouponType::FIXED->value` and
  `in:function:store#0/const:CouponType::FIXED->value/value:true`. Before, the key had neither the path nor the prefix,
  `const:CouponType::FIXED->value`, and the value under it carried the key as `call:value`, e.g.
  `in:function:store#0/call:value/value:true`. An enum key of an array no call receives also gets the `key:` prefix,
  `key:const:CouponType::FIXED->value`, and the value under it becomes `in:const:CouponType::FIXED->value/value:true`;
  before, they were `const:CouponType::FIXED->value` and `in:call:value/value:true` (#82).
- A key that the covering test passes only to an assertion, such as a key of the array `toEqual` expects, is not
  listed in `coveringDiffersBy` and no longer makes the candidate `review`. A top-level key and a nested key under an
  assertion are treated the same way: `in:expect:function:build/call:toEqual#0/key:value:'type'` and
  `in:expect:function:build/call:toEqual#0/value:'properties'/key:value:'senderMail'` both count as passed to the
  assertion. Before, every such key was listed for a test with an assertion and for a `merge-into-dataset` candidate,
  and a top-level key was listed for a test without an assertion. In the variants case of #71, `coveringDiffersBy`
  then named `in:expect:…/call:toEqual#0/value:'properties'/key:value:'variants'` next to the input key (#79).
- An enum case read through `->value` inside a call argument carries the path of the argument, like any other argument
  value, and its token names the property it reads:
  `$this->post('/import', ['default_type' => CouponType::FIXED->value])` has the token
  `in:call:post#1/value:'default_type'/const:CouponType::FIXED->value` instead of
  `in:value:'default_type'/const:CouponType::FIXED`, and `store(CouponType::FIXED->value)` and
  `store([CouponType::FIXED->value])` have `in:function:store#0/const:CouponType::FIXED->value`. A test that passes the
  enum value is then `merge-into-dataset` against a test that passes a literal, the enum case itself, or its `->name` to
  the same argument. Before, the token had no argument position, so the candidate was `review` against a literal, and
  `->value`, `->name` and the case itself had the same token, so a test that passes `->name` was `delete` against a test
  that passes `->value` (#76).
- A `merge-into-dataset` candidate whose covering test passes an argument at a position where `differsBy` lists no
  value, such as `store(1, 2)` against `store(3)`, is `review`. `coveringDiffersBy` lists the argument tokens, e.g.
  `in:function:store#1/value:2`, and the steps ask whether the code under test behaves differently without one of
  these values. Before, the extra argument could not be told apart from a differing value, so `coveringDiffersBy`
  stayed empty and the steps merged the candidate into a row that still passed the argument (#75).
- An array key carries the path of its array, the same path a literal in the array has, e.g.
  `in:function:store#0/value:'custom_fields'/key:value:'color'` instead of `key:value:'color'`. A key nested in a value
  that `differsBy` already lists then does not count as a key the candidate lacks, because a dataset row replaces the
  whole value: `store(['custom_fields' => []])` against `store(['custom_fields' => ['color' => 'red']])` is
  `merge-into-dataset`. Before, a `key:` token carried no path, so the key sent the candidate to `review`. The same key
  under another call or another argument is now a different token, and a key nested in a call argument is named only
  in the step about array keys (#74).
- A list carries its length: every element of a non-empty array literal without keys counts once in the token
  `value:list-item`, with the path of the list, e.g. `in:function:collect#0/value:list-item`. A test that passes the
  same item several times in one list, such as `collect([$click, $click, $click])`, is then `review` or
  `merge-into-dataset` against a test that passes it once. Before, `differsBy` was empty and the test was `delete`,
  because a variable read again in the same list adds no token. A list that unpacks an array with `...` carries no
  length. The token also appears in `coveringDiffersBy` when the covering test passes a longer list (#72).
- A `merge-into-dataset` candidate whose covering test sets an array key the candidate lacks, such as a key the covering
  dataset row sets through a parameter, is `review`. `coveringDiffersBy` lists the `key:` tokens, and the steps ask
  whether the code under test reads one of these keys. Before, `coveringDiffersBy` stayed empty and the steps merged the
  candidate into a row that still set the key, so the case without the key was lost (#71).
- A call, function call or `new` expression that a test assigns once to a local variable is counted where the test
  first passes the variable on as a call argument or an array value, so it has the same tokens as the same call passed
  inline. Before, `$email = sprintf('email@%s', $domain); notify($email);` listed the literal in `differsBy` against
  `notify(sprintf('email@%s', $domain))`. A first read that does not pass the variable on, or that is in a loop body,
  keeps the tokens without a path (#71).

## [0.1.3] - 2026-10-24

### Changed

- The concurrent groups of a coverage run are balanced by the time of each test file instead of round-robin. Every run
  stores the time of each test file from its JUnit log in `.test-redundancy/durations.json`. The next run puts the
  longest file first and every file into the group with the smallest total; a file without a stored time goes first,
  and without any stored time the files are split round-robin as before. For tests that share no state inside one
  process the report and the baseline stay the same (#54).
- `analyse` without `--across` does not run a test file that declares exactly one test case and no dataset, because
  such a file can never hold a candidate. A file with a dataset (`->with()`, `->repeat()`, `#[DataProvider]`,
  `#[TestWith]`, `@dataProvider`, `@testWith`), a named or shared dataset, or a test case the parser cannot resolve
  still runs. A skipped file counts in `summary.testFiles` and `summary.testCases`, `summary.skippedFiles` counts it,
  and it stays in the baseline without lines, so `verify` still runs it and lists a deleted skipped file in
  `missingTestFiles`. The candidates of every other file stay the same. `--across` and `map` run every file (#56).
- With `pcov`, the coverage run instruments only the `source` directories. `pcov.directory` is the one `source`
  directory, or the deepest directory that holds all of them, and `pcov.exclude` keeps out `vendor/` and what lies
  under that directory outside the `source`. Before, `pcov` instrumented every PHP file under the project root, such
  as the tests, `database/` and `config/`. A `source` directory that does not exist is skipped, and the project root
  stays the `pcov.directory` when none exists. The recorded lines of the `source` stay the same (#55).

### Fixed

- A candidate without an assertion is reported with the action `review` instead of `delete` when its covering test
  arranges more input, such as a call, function, `new` expression or value that the candidate lacks. The tokens are
  listed in `coveringDiffersBy`, and a step asks whether the empty or missing input is a case of its own. An assertion
  and a value passed only to an assertion do not count (#60).
- A dataset row whose value is a closure or an arrow function carries the token `value:closure:<hash>`, a hash of the
  closure source as `nikic/php-parser` prints it. Two rows whose closures have different bodies differ in `differsBy`,
  so such a row is no longer reported with the action `delete` against its sibling row. Two closures that differ only
  in formatting still have the same token (#57).
- A dataset row whose value is a map of literals, such as `['listId' => 1, 'store' => 'shop']`, now carries the
  position of the parameter it binds to and, through it, the argument position of the call it reaches, such as
  `in:function:changedKeys#1/value:'store'/value:'shop'`. The same holds for a map written inline in a call and for a
  map the test assigns once to a local variable and reads, so a local map and the same map inline still have the same
  tokens. A list or map nested in a row list or map binds too. Two rows that swap the same maps between two arguments
  differ in `differsBy`, so such a row is no longer reported with the action `delete` against its sibling row (#58).
- A value that an argument passes on through an array or a nested call now carries every enclosing call and array key
  in the contract fingerprint, such as `in:function:json_encode#0/function:json_encode#0/value:'test'/value:true` for
  `json_encode(json_encode(['test' => true]))`. A dataset row whose value its sibling row passes through one more call
  differs in `differsBy`, so such a row is no longer reported with the action `delete`. An assertion that receives the
  value through another call stays out of the path, so `expect(add(1, 2))` and `$sum = add(1, 2)` keep the same
  tokens. An operand of an operator, a cast or a closure body keeps no call path, so `foo('a' . $suffix)` does not get
  the token of `foo('a')`. A list item keeps the call path only as a direct item of an argument, so `foo([[1]])` does
  not get the token of `foo(1)`, and `foo(['a' => [1]])` (`in:value:'a'/value:1`) does not get the token of
  `foo(['a' => 1])` (#59).
- An assertion now carries its subject in the contract fingerprint: the value passed to `expect()` or `->and()`, or the
  last argument of a PHPUnit `assert*` call that is not a literal or a constant, such as
  `expect:call:create/call:toBeGreaterThan` and `in:expect:call:create/call:toBeGreaterThan#0/value:0`. Two tests that
  make the same assertion on different subjects differ in `differsBy`, so such a test is no longer reported with the
  action `delete`. A variable the test assigns once stands for its value, so `$sum = add(1, 2); expect($sum)` and
  `expect(add(1, 2))` keep the same tokens (#61).

## [0.1.2] - 2026-10-23

### Added

- `unionOnlyLines` in the JSON report and `union only lines` in the text report list the source lines of a candidate
  that the covering test with `differsBy: []` does not run, because only the union of the `coveredBy` tests reaches
  them (#45).

### Fixed

- A candidate is reported with the action `delete` only when one covering test matches its contract (`differsBy: []`)
  and runs every source line the candidate runs. When the matching test runs only some of the lines and a second
  covering test runs the rest, the action is now `review`, and `unionOnlyLines` names the lines the matching test
  misses. Before, such a candidate got `differsBy: []` and the action `delete`, and deleting it left the lines of the
  second test without an assertion (#45).
- A candidate whose covering test sets an array key the candidate does not set, or sets it more times, is reported
  with the action `review` instead of `delete`, and `coveringDiffersBy` names the key (`key:value:'variants'`). A
  missing array key is another input shape, not less of the same data. The steps ask whether the code under test
  reads the key, and say to keep the test when it does. Before, a dataset row without the key got `differsBy: []` and
  the action `delete`. A test such as `add(1, 2)` covered by the dataset row `[1, 2, 3]` is still reported with the
  action `delete`, because positional values carry no `key:` token (#46).
- A candidate whose covering test passes a value to a call that is not an assertion, such as an argument of the code
  under test, and the candidate does not pass it, or passes it fewer times, is reported with the action `review`
  instead of `delete`. `coveringDiffersBy` names the value (`in:call:getByListIdAndLastId#4/value:'email'`), and the
  steps ask whether the code under test behaves differently without it. An element of a list passed straight to a call
  now carries the call and the argument position in the contract fingerprint (`in:function:store#0/value:'a'`), so a
  list without an element is told apart from the full list. A value the covering test passes only to an assertion,
  such as `toBe`, `assert*` or `expect*`, keeps the action `delete`, so a test such as `add(1, 2)` covered by the
  dataset row `[1, 2, 3]` is still reported with the action `delete` (#47).
- A list of literals that reaches the code under test through a local variable the test assigns once, or through a
  dataset row, now carries the call path its elements carry when the list is written inline in the call, such as
  `in:call:getByListIdAndLastId#4/value:'email'`. A candidate whose list lacks an element the covering test passes in
  the same way is reported with the action `review`, and `coveringDiffersBy` names the element. Before, both forms got
  `differsBy: []` and the action `delete` (#51).

## [0.1.1] - 2026-10-23

### Added

- `coveringDiffersBy` in the JSON report and `covering differs by` in the text report list the calls, functions and
  `new` expressions of the covering test that made the action `review` although `differsBy` holds only data, for
  example `call:forget`. The `review` steps name them (#27).

### Fixed

- A literal, constant or empty array passed straight to a call or a `new` expression now carries the call and the
  argument position in the contract fingerprint (`in:function:store#0/value:true`), or the argument name for a named
  argument. A literal, constant or empty array of a dataset row is counted where the test reads the parameter at its
  position, and so is a literal that the test assigns once to a local variable. Two dataset rows, or two calls, with the
  same literals at swapped positions, such as `[false, true]` and `[true, false]`, are reported with a non-empty
  `differsBy` instead of `differsBy: []` and the action `delete`. A test such as `add(1, 2)` covered by the dataset row
  `[1, 2, 3]` of `add($first, $second)`, or a test that passes the literal through a local variable, is still reported
  with the action `delete`. A dataset row value such as `new Contact()` is counted once, however many times the test
  reads it (#37).
- A call, function call or `new` expression in the body of a `foreach`, `for`, `while` or `do` loop now carries a
  `loop:` prefix in the contract fingerprint (`loop:call:Batch::import`), because the fingerprint cannot count how many
  times the loop runs. A test that makes the call in a loop against one that makes it once is reported with the action
  `review` instead of `merge-into-dataset` (#33). A test that makes the call once, covered by a test that makes it in a
  loop, is reported with the action `review` instead of `delete`.
- `verify` no longer lists a line in `lostLines` and says to restore a test when no covering test file changed. `analyse`
  stores a hash of each analysed test file in the baseline. A lost line whose covering test files are all unchanged is
  reported in the new `unstableLines`, with an instruction not to restore a test, and `verify` exits with `0`. A baseline
  written by an earlier version reports every lost line as before.
- A call made through Pest's negation `->not` or `->not()` now carries a `not:` prefix in the contract fingerprint
  (`not:call:toBeEmpty`), so a test that asserts the opposite result is reported with a non-empty `differsBy`
  and the action `review` instead of `delete` (#10).
- `verify` reports a lost line in a source file that changed since `analyse` in `lostLines`, not in `unstableLines`.
  `analyse` stores a hash of each covered source file in the baseline. A baseline written before this change has no
  source hashes, so `verify` reports every lost line of it in `lostLines`.
- An array key now carries a `key:` prefix in the contract fingerprint (`key:value:'x'`),
  so a dataset row whose input equals only an array key of the covering row is reported with `differsBy: ["value:'x'"]`
  instead of `differsBy: []` and the action `delete` (#11).
- A candidate whose covering test makes a call, function call or `new` expression the candidate lacks is reported
  with the action `review` instead of `merge-into-dataset`. `merge-into-dataset` now requires that the two tests differ
  only in data in both directions, so a dataset can express them as rows of one test (#12).
- `verify` reports a lost line in `lostLines`, not in `unstableLines`, when a test support file changed since `analyse`:
  a Pest dataset, `tests/Pest.php`, a base test case, a trait or a fixture. `analyse` stores a digest of these files in
  the baseline. A baseline written before this change has no digest, so `verify` reports every lost line of it in
  `lostLines` (#20).
- `differsBy` is the smallest difference against one covering test, not against the union of all covering tests.
  A candidate whose values each appear in some covering test, but never together in one, is reported with the action
  `review` instead of `delete` (#15).
- An empty array literal `[]` now carries the token `value:[]` in the contract fingerprint, so a test or dataset row
  that differs from its covering test only by passing `[]` is reported with `differsBy: ["value:[]"]` instead of
  `differsBy: []` and the action `delete` (#16).
- The contract fingerprint counts each token instead of storing it once. A candidate whose covering test makes a call,
  function call or `new` expression more or fewer times than the candidate, for example creates two records where the
  candidate creates one, is reported with the action `review` instead of `merge-into-dataset`. A token the candidate
  uses more times than the covering test appears in `differsBy`, so a test that differs only by repeating a value is
  no longer reported as `delete`. The count is the number of places the token appears in the test source, so a call
  inside a loop body counts once (#17).
- A Pest assertion written as a property, without parentheses (`expect($x)->toBeEmpty`, `expect($x)->not->toBeEmpty`),
  now carries the same token as its call form (`call:toBeEmpty`, `not:call:toBeEmpty`) in the contract fingerprint.
  Only a property fetch on an `expect()` chain whose name is a method of Pest's `Expectation` is collected; a plain
  object property and a higher-order property such as `expect($user)->name` carry no token (#21).
- A boolean, string, number or constant used as the value of an array key now carries the path of its keys in the
  contract fingerprint (`in:value:'enabled'/value:false`). A dataset row that passes the same literal without that key,
  or under another key, is reported with a non-empty `differsBy` and the action `review-dataset-row` or `review`
  instead of `differsBy: []` and the action `delete` (#25).
- An empty array literal `[]` used as the value of an array key now carries the path of its keys in the contract
  fingerprint (`in:value:'tags'/value:[]`), like the other literals since #25. A dataset row whose empty array the
  covering row has only under an unrelated key is reported with a non-empty `differsBy` and the action
  `review-dataset-row` or `review` instead of `differsBy: []` and the action `delete` (#36).

## [0.1] - 2026-10-22

### Added

- `vendor/bin/test-redundancy [paths]` — finds PHPUnit and Pest test cases whose covered lines other test cases
  already cover, from one run with per-test coverage. A folder runs in parallel.
- A logo: one coverage circle with a check mark in a frame, also used in the social preview.
- JSON output for AI agents with an `action` per candidate (`delete`, `merge-into-dataset`, `review-dataset-row`,
  `review`), the smallest covering set, and what the candidate asserts beyond it. `--format=text` for people.
- `map` and `--across` — a per-test coverage map of the whole suite, to compare tests across files.
- `test-redundancy.json` for source paths, ignored sources, environment, processes, map path and runner.
- Support for PHP 8.4–8.5, Pest 3–5 and PHPUnit 10–13.
- `testsWithoutSourceLines` and `summary.staleMap` in the JSON report.
- `verify` — fails and lists every source line the last `analyse` covered that no test covers any more.
- `steps` per candidate and a `workflow` for AI agents; compact JSON by default, `--pretty` to indent it.
- `--processes` for `analyse` and `map`; several paths run as concurrent groups, each with its own `TEST_TOKEN`.
- Pest tests in a namespace, inside `describe()` blocks and with shared `dataset()` rows get a contract fingerprint.
- `pekral/ai-olympus` as a development dependency, with its Claude Code and Codex rules, skills and agents.

### Changed

- The Composer keywords name Pest, paratest, code and test coverage, duplicate tests and AI agents, so Packagist search
  finds the package.
- The package requires PHP 8.4 or newer; the quality checks run on PHP 8.4 and 8.5.
- The tests run only in parallel: `processes` must be at least 2, PHPUnit needs `paratest`, and the one `env` map
  replaces `env` and `parallelEnv`.

### Fixed

- Parallel workers get the coverage settings through `--passthru-php` and the project configuration through
  `--configuration`; before, a worker that guessed another `pcov.directory` recorded no coverage.

[Unreleased]: https://github.com/pekral/test-redundancy/compare/0.1.6...HEAD
[0.1.6]: https://github.com/pekral/test-redundancy/compare/0.1.5...0.1.6
[0.1.5]: https://github.com/pekral/test-redundancy/compare/0.1.4...0.1.5
[0.1.4]: https://github.com/pekral/test-redundancy/compare/0.1.3...0.1.4
[0.1.3]: https://github.com/pekral/test-redundancy/compare/0.1.2...0.1.3
[0.1.2]: https://github.com/pekral/test-redundancy/compare/0.1.1...0.1.2
[0.1.1]: https://github.com/pekral/test-redundancy/compare/0.1...0.1.1
[0.1]: https://github.com/pekral/test-redundancy/releases/tag/0.1
