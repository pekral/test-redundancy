# Reference

The first two sections define exactly when the tool proposes `delete` or `merge-into-dataset`, and how it names a
difference. Read them when a verdict surprises you.

## When a candidate is `review`, not `delete`

`delete` needs an empty `differsBy` and every one of these conditions. The tool checks them in this order: while
`unionOnlyLines` is not empty, `coveringDiffersBy` stays empty and the steps name only the lines and the covering tests.

- **One test runs every line.** One covering test runs every line of the candidate. When only the union of the
  `coveredBy` tests reaches some lines, they are listed in `unionOnlyLines` and the action is `review`.
- **No missing array key.** The covering test sets no array key, or sets no key more times, that the candidate lacks.
  A missing key is another input shape, so the `key:` tokens are listed in `coveringDiffersBy` and the action is
  `keep` or `merge-into-dataset` (see [Other combinations of input values](report.md#other-combinations-of-input-values)), or
  `review` when the covering test also has a call or an assertion that the candidate lacks. A key passed only to an assertion, such as a key of the array `toEqual` expects, does not count, whether
  it is a top-level key of that array or a key nested in it.
- **No missing argument value.** The covering test passes no value, or passes no value more times, to a call that is
  not an assertion, such as an argument of the code under test (`in:call:getByListIdAndLastId#4/value:'email'`). A
  missing argument value is another input, so the value is listed in `coveringDiffersBy` and the action is `keep` for a
  literal value (see [Other combinations of input values](report.md#other-combinations-of-input-values)), or `review`. A
  value passed only to an assertion, such as a Pest expectation (`toBe`) or a PHPUnit `assert*` or `expect*` call, does
  not count. A value that is not a literal counts too: a variable, a property or a call result in an argument position
  that the candidate does not fill carries the token `in:<call>#<position>/non-literal`, such as
  `in:function:logEvent#3/non-literal` for the fourth argument `$url` of `logEvent($account, $campaign, $contact, $url)`.
- **No missing call or object.** The covering test has a call, function or `new` expression that the candidate lacks,
  or has it more times than the candidate does, for example `function:createVerifiedDomain` for an extra record it
  creates, `call:andThrow` and `new:InvalidDomain` for a mock that throws, `function:strtoupper` for a wrapping call, or
  `function:ob_start` called twice. Such a call arranges another state of the code under test, so the tokens are listed
  in `coveringDiffersBy` and the action is `review`. An assertion, and a literal that is not an argument, do not count.
  A call that the covering test only reads in an assertion, such as `activeState()` in `expect(activeState())->toBe(0)`
  or `$order->total()` in `assertSame(5, $order->total())`, does not count either. A call inside a call that an
  assertion receives still counts, as `strtoupper()` does in `expect(keepSubscribed(collect([strtoupper($email)])))`.
- **No missing arrangement for a test without an assertion.** A candidate without an assertion asserts only that the
  code under test throws no exception for its input. When the covering test has a call, function, `new` expression or
  value that the candidate lacks, such as `function:createDomain` for a record it creates before the call under test,
  the candidate can be the only test of an empty or missing input. The tokens are listed in `coveringDiffersBy`, the
  action is `review`, and the steps ask whether the empty or missing input is a case of its own. This holds for a value
  that is not an argument too, such as a literal the covering test echoes. An assertion and a value or key passed only
  to an assertion do not count, so a covering test that only asserts more about the same input still leaves the
  candidate `delete`. A Pest `->throws()`, `->throwsIf()` or `->throwsUnless()` with an argument chained on the test
  counts as an assertion, so a test whose only expectation is such a call is not a candidate without an assertion.
- **No other test uses the dataset.** A row of a named Pest dataset, `dataset('name', …)`, that another test of the
  same file also uses with `->with('name')` cannot be deleted for one test: deleting the row removes it from every
  test that uses the dataset. Such a row is `review`, not `delete`, and the first step names the dataset and the other
  tests. The same step comes first for every other action of such a row. A named dataset defined in another file is
  not parsed, so its rows are not checked.

A test whose values equal a row of the dataset that covers it is `delete`, covered by that row, and the row is never a
candidate covered by that test. The row is kept, so the report never proposes to delete the row and to add it back.

`merge-into-dataset` needs a candidate that is not a dataset row, whose `differsBy` holds only data, and that differs
only in data from one test of the same file. When that test has a call, function or `new` expression that the
candidate lacks, or has it more times than the candidate does, the two tests cannot be rows of one test: the tokens are
listed in `coveringDiffersBy` and the action is `review`. When that test sets an array key that the candidate lacks,
such as `in:call:post#1/key:value:'default_min_spend'`, a row with the values in `differsBy` would still set the key,
so the case without the key would be lost: the action is `merge-into-dataset` with whole arrays as row values, or
`keep`, see [Other combinations of input values](report.md#other-combinations-of-input-values). A key nested in a value that `differsBy`
already lists does not count, because a row replaces the whole value. For example, the key `'color'` in
`store(['custom_fields' => ['color' => 'red']])` is nested in the value that `differsBy` lists as
`in:function:store#0/value:'custom_fields'/value:[]` for `store(['custom_fields' => []])`, so that candidate stays
`merge-into-dataset`. When that test passes a variable or a property that the candidate does not pass, such as
`in:function:dispatchBulkStep#0/variable:account/property:name` for `dispatchBulkStep($account->name)` against
`dispatchBulkStep('happypet')`, a row with the values in `differsBy` would still pass that variable or property, so the
case of the candidate would be lost: the name tokens (see *Argument names*) are listed in `coveringDiffersBy` and the
action is `review`. A name token or a `non-literal` token is never data, also when its path crosses the key of an
array, such as `in:function:foo#0/value:'k'/function:bar#0/variable:x` for `foo(['k' => bar($x)])`. When that test passes an argument at a position where `differsBy` lists no value, such as
`in:function:store#1/value:2` for `store(1, 2)` against `store(3)`, a row with the values in `differsBy` would still
pass the argument, so the case without it would be lost: the argument tokens are listed in `coveringDiffersBy` and the
action is `keep` for a literal argument, or `review`.
The reverse holds too. When the candidate passes a named or a positional argument that the covering test omits, such
as `in:new:PredictedData#newCustomersForecastCount/value:24` for `new PredictedData(date: $date, newCustomersForecastCount: 24)`
against `new PredictedData(date: $date)`, the covering test relies on the default of the argument, and a row passes
every argument, so the case "the argument is omitted, the default applies" would be lost: the action is `review`, and
the steps list the arguments of the candidate that the covering test omits and say to merge only when the row passes them
as a spread array that is empty for the covering row. The same holds for a call of a variable, such as
`($action)('john@example.com', 20, $events)` against `($action)('john@example.com', 20)`, which carries
`function:$action` and the position `in:function:$action#2/`.
The same holds for the expected value of an assertion. When that test expects more than the candidate does, a row
with the values in `differsBy` would still expect it, so the case of the candidate would be lost. Such tokens are listed
in `coveringDiffersBy`, the action is `review`, and the steps say to merge the candidate only into a row that takes its
whole expected value as a value of its own. Three shapes count:

- an expected list with more elements, `in:expect:function:resolve/call:toEqualCanonicalizing#0/value:list-item`.
  A list whose whole expected value `differsBy` already lists as empty, such as `in:expect:function:build/call:toBe#0/value:[]`
  for `toBe([])` against `toBe(['x', 'y'])`, does not count;
- an expected array key that the candidate lacks, such as `in:expect:new:Context/call:toArray/call:toBe#0/key:value:'impersonator_email'`
  for `toBe(['impersonated' => false])` against `toBe(['impersonated' => true, 'impersonator_email' => 'a@b.cz'])`.
  A key nested in an expected value that `differsBy` already lists does not count;
- an assertion argument at a position where `differsBy` lists no value, such as the message
  `in:call:toThrow#1/value:'vocatives_cz.csv:2'` of `toThrow(InvalidArgumentException::class, 'vocatives_cz.csv:2')`.
  The failure message of an assertion, such as the `$message` of `toBe()` or `assertSame()`, does not count.

When that test is a dataset row whose parameter reaches the expected value, the row replaces the whole expected value, so
none of these shapes counts.
When that test is a dataset row, a new row can pass only the values that the parameters of its closure or test method
take. Each value in `differsBy` must sit at a position that a parameter reaches, and the type of that parameter must take
the value. Otherwise the action is `review`, because a row with the value would land in another parameter, would be
dropped, or would throw a `TypeError`, and the case of the candidate would be lost. This applies to a value at a position
that the body hard-codes, such as `false` against the literal `true` in `addVarsToUrlQuery($content, $vars, true)`, to an
argument that the body does not pass, and to a value that the parameter type does not take, such as `null` for
`int $code`. A slot the body hard-codes is never row data, also in a list that the body builds around a parameter: in
`f([$n, 5])`, a row changes only the slot of `$n`, so a candidate that lacks the `5` there, such as `f([1, 6])`, is
`review`. A constant is taken by every type, because its value is not known. A candidate whose `differsBy` holds only
data against several covering tests or against a test of another file is `review` too, and so is a dataset row whose
covering test is not a row of the same dataset.

An exception that a Pest test expects through `->throws()`, `->throwsIf()` or `->throwsUnless()` is never data. Such a
call is chained on the whole `it()` or `test()`, so a dataset cannot vary it per row. Each argument of such a call
carries the token `call:<method>#<position>/` before its value, without the `in:` prefix of an argument in the body.
For example, `->throws(EmailNotFound::class, 'The website is empty.')` carries `call:throws#0/const:EmailNotFound::class`
and `call:throws#1/value:'The website is empty.'`. A named argument carries its name instead of its position, such as
`call:throws#exceptionMessage/value:'The website is empty.'`. An argument that is not a literal or a constant also
carries its source code, such as `call:throwsIf#0/source:$onLinux` for `->throwsIf($onLinux, …)`,
`call:throwsIf#0/source:fn() => $this->onLinux` for a closure, and
`call:throws#1/source:"The website {$website} is empty."` for an interpolated string. When an argument of such a call
in the candidate differs from the covering test, the candidate is `review`, not `merge-into-dataset`: a row with the
values in `differsBy` would still expect the exception of the covering test, so the case of the candidate would be
lost. The argument can be the class, the message, the code or the condition, and it can be a variable, a property, a
closure, an operator or an interpolated string. A difference only in whitespace or comments is not a difference.
When one test covers the candidate and `unionOnlyLines` is empty, the arguments and the calls of this kind that only
the covering test has are listed in `coveringDiffersBy`, such as `call:throws#0/const:InvalidUrlAddress::class` and
`call:throws`. The steps then name them and say to move the expectation of both tests into the body as
`expect(fn () => …)->toThrow()`, with the class and the message as row values. An exception that a test expects in its
body, such as `expect(fn () => …)->toThrow(EmailNotFound::class)` or PHPUnit's
`$this->expectException(EmailNotFound::class)` and `$this->expectExceptionMessage('…')`, is data, because a row can pass
the class or the message to it, so such a candidate can still be `merge-into-dataset`. The analysis compares the source
code of an argument, never its value at run time. Two tests whose arguments have the same source code are not told
apart, such as `->throws($exception)` in both tests while the variable holds another class in each.
The step for an argument that the covering test omits does not read a call of this kind as a call in the body of the
covering test. A method named `throws()` that the candidate calls in its body, such as `$stub->throws(E::class)`, and
the `->throws(E::class)` that the covering test chains on the whole test therefore do not make that step name the
arguments of `->throws()` as omitted.

The arguments of the other Pest modifiers chained on the whole test are never data either, because a dataset cannot
vary them per row. The modifiers covered are `->assignee()`, `->covers()`, `->coversClass()`, `->coversFunction()`,
`->coversTrait()`, `->depends()`, `->done()`, `->fails()`, `->flaky()`, `->group()`, `->issue()`, `->note()`, `->pr()`,
`->references()`, `->repeat()`, `->see()`, `->skip()`, `->skipOnPhp()`, `->ticket()`, `->todo()` and `->wip()`. Each
argument of such a call carries the token `call:<method>#<position>/` before its value, in the same way as an argument
of `->throws()`. For example, `->group('slow')` carries `call:group#0/value:'slow'`, and `->wip(note: 'Check it.')`
carries `call:wip#note/value:'Check it.'`. When an argument of such a call in the candidate differs from the covering
test, such as `->group('a')` against `->group('b')`, the candidate is `review`, not `merge-into-dataset`: a merged
test would keep one value for every row, so the modifier of the candidate would be lost. A modifier without an argument,
such as `->only()` or `->skipOnWindows()`, carries only its call, such as `call:only`, which is never data.
The steps of such a `review` candidate include a step that names each modifier with an argument in `differsBy`, such as
`group()`, and says that a dataset cannot vary it per row. The step says to keep the test when the modifier still has a
purpose of its own, such as a group a CI job selects, and to delete the test only when it does not.

The closure of `->after()` is never data either, because a dataset cannot vary it per row. Its tokens are collected in
the same way as the tokens of the test body, and each one carries the token `call:after#<position>/` before it. For
example, `->after(fn () => cleanup('mail'))` carries `call:after#0/in:function:cleanup#0/value:'mail'`. A `read:` token
keeps its prefix in front, such as `read:call:after#0/call:Mailer::sent`. When the closure of the candidate differs
from the covering test, the candidate is `review`, not `merge-into-dataset`. Unlike the arguments of the other
modifiers, the closure carries no `source:` token, because its tokens already describe it. An `expect()` or an
`assert*()` call inside the closure counts as an assertion of the test, because Pest runs the closure after the test
and a failure in it fails the test. A test whose only expectation is in its `->after()` closure therefore does not get
the step that says the test asserts nothing.

## How `differsBy` names a difference

`differsBy` lists the literals, constants, functions, calls and `new` expressions the candidate has and the covering
test lacks.

- **One covering test, not a union.** With several covering tests, `differsBy` is the smallest difference against one
  of them.
- **Counts.** The fingerprint counts each token, so a token the candidate uses more times than the covering test is
  listed too. The count is the number of places the token appears in the test source, not the number of runs.
  A call of a callable that a variable, a property, a class constant or an array element holds counts too:
  `($action)($account)` carries `function:$action`, `($this->action)($account)` carries `function:$this->action`,
  `(self::$action)($account)` carries `function:self::$action`, `(self::HANDLER)($account)` carries
  `function:self%3A%3AHANDLER`, and `($this->actions['cleanUp'])($account)` carries
  `function:$this->actions['cleanUp']`. A class constant and the key of an array element are written like a `const:` or
  `value:` token without its prefix, e.g. `$actions[0]`, `$actions[-1]`, `$actions[self%3A%3AK]` or
  `Actions%3A%3AALL['cleanUp']`. In them, `%`, `/`, `#` and `:` are written as `%25`, `%2F`, `%23` and `%3A`, because
  `/`, `#` and `:` separate the parts of a token, so `($this->commands['import:products'])($account)` carries
  `function:$this->commands['import%3Aproducts']`, and a constant named like an assertion, such as `self::assertReady`,
  is not read as an assertion. A nullsafe
  method call `$action?->handle($account)` carries `call:handle`, like the method call `$action->handle($account)`. A
  test that makes such a call once is therefore `review` against a covering test that makes it twice, because the
  covering test makes the call more times (see *No missing call or object*). A
  callable that the test names only at run time, such as `($this->{$name})()`, `($this->actions[$name])()` or
  `($class::HANDLER)()`, carries no token.
- **Casts.** A cast carries a token for its type at its place, e.g. `in:function:store#0/cast:object` for
  `store((object) ['code' => null])` or `in:function:connect#0/cast:int` for `connect((int) $id)`. Each token inside the
  cast carries the cast in its path, and in an argument also the place of the argument, e.g.
  `in:function:store#0/cast:object/key:value:'code'`, so two tests with swapped literals in cast arguments, such as
  `store((int) '5', (int) '6')` and `store((int) '6', (int) '5')`, are `merge-into-dataset`. A token inside a cast in a
  list carries no place, e.g. `in:cast:object/key:value:'code'`. An alias carries the token of its type, so
  `(integer)` carries `cast:int` and `(double)` carries `cast:float`. A test that has a cast its covering test lacks,
  or a cast in another place, is therefore `review`, not `delete` or `merge-into-dataset`, because a row built from
  `differsBy` would drop the cast and pass another type. A cast that only the covering test has is not in `differsBy`.
  Around a variable or a property that an assertion expects, it carries no other token, so
  `expect(render())->toBe($x)` covered by `expect(render())->toBe((string) $x)` is still `delete`. In an argument of a
  call that is not an assertion, it is listed in `coveringDiffersBy`, so `store($x)` covered by `store((string) $x)` is
  `review`. A cast that is the subject of an assertion or an array key
  carries what it casts, e.g. `expect:cast:string/variable:response/call:body/call:toBe` or
  `in:function:store#0/cast:string/const:Foo::A/value:1`. A cast in a list is ordered by its source, like an array or
  a call with arguments.
- **Loops.** A call, function call or `new` expression in the body of a `foreach`, `for`, `while` or `do` loop carries a
  `loop:` prefix, e.g. `loop:call:Batch::import`. A call made in a loop and the same call made outside a loop are
  different tokens, so the action is `review`. Two loops differ only in the tokens they carry, never in the number of
  runs, and a callback passed to a function such as `array_map()` is not a loop body.
- **Negation.** A call made through Pest's `->not` or `->not()` carries a `not:` prefix, e.g. `not:call:toBeEmpty`.
- **Property-form assertions.** A Pest assertion written as a property, e.g. `->toBeEmpty` or `->not->toBeEmpty`,
  carries the token of its call form.
- **Assertion subjects.** An assertion carries the value it checks, its subject, as an `expect:` prefix. The same
  assertion on two different subjects therefore gives two different tokens, and the candidate is not `delete`.
  `expect($templates->create())->toBeGreaterThan(0)` has the tokens
  `expect:variable:templates/call:create/call:toBeGreaterThan` and
  `in:expect:variable:templates/call:create/call:toBeGreaterThan#0/value:0`, and
  `expect($template->order)->toBeGreaterThan(0)` has `expect:variable:template/property:order/call:toBeGreaterThan` and
  `in:expect:variable:template/property:order/call:toBeGreaterThan#0/value:0`.
  - The subject of a Pest expectation is the value passed to the nearest `->and()` before the call, or else to
    `expect()`. Every call of the expectation chain carries it, `->and()` and `->each` included.
  - The subject of a PHPUnit `assert*` call is its last argument that is not a literal or a constant:
    `self::assertSame(3, add(1, 2))` has the token `expect:function:add/call:self::assertSame`. The failure message is
    never the subject. When every other argument is a literal or a constant, the last of them is the subject, such as
    `'5'` in `$this->assertSame(5, '5')`.
  - A property carries its name token (see *Argument names*), e.g. `variable:template/property:order`. A call, a
    function call and a `new` expression carry their own token.
  - A method call also carries its receiver before its own token: a variable by its name as `variable:<name>`, also
    when the test assigns it once, a property by its name token, such as `variable:this/property:repository` for
    `$this->repository` and `property:self::repository` for `self::$repository`, and a call, a function call or a
    `new` expression as its own token.
    `expect($moment->format('Y-m-d'))` has the subject `variable:moment/call:format`, and
    `expect($interval->applyTo($moment)->format('Y-m-d'))` has `call:applyTo/call:format`. Only the nearest receiver
    is kept. A literal or a constant as the receiver adds nothing, so `expect(Status::Active->label())` has the subject
    `call:label`.
  - A variable that the test assigns once in its own statements stands for the value assigned to it:
    `$sum = add(1, 2); expect($sum)->toBe(3);` has the same assertion tokens as `expect(add(1, 2))->toBe(3)`.
  - Any other variable carries its name, so `expect($a)->toBe(1)` has the tokens `expect:variable:a/call:toBe` and
    `in:expect:variable:a/call:toBe#0/value:1`.
  - A closure or an arrow function stands for the value it returns, so `expect(fn () => import(1))->toThrow(A::class)`
    has the subject `function:import`. The value a closure returns is the expression of each of its own `return`
    statements, and not of a `return` statement in a closure or a function nested in it.
  - A literal, a constant and any other expression that carries no token, such as the element read `$r['a']`, a list,
    a map or the enum case read `Status::A->value`, give no prefix to the token of the assertion, which keeps e.g.
    `call:toBe`. When a test has two or more assertions with different such subjects, each argument of these
    assertions carries its subject in its path: a literal, a constant or an empty array by its token, any other
    expression by its source code. `expect('5')->toBe(5)` next to `expect('6')->toBe(6)` has
    `in:expect:value:'5'/call:toBe#0/value:5`, `expect($r['a'])->toBe(1)` next to `expect($r['b'])->toBe(2)` has
    `in:expect:$r['a']/call:toBe#0/value:1`, and `expect([1])->toBe(3)` next to `expect([2])->toBe(4)` has
    `in:expect:[1]/call:toBe#0/value:3`. So a test that pairs the same subjects
    with swapped values is `merge-into-dataset`, not `delete`. A test with one such subject keeps
    `in:call:toBe#0/value:5`. A test with two or more such subjects is `review`, not `merge-into-dataset`, against a
    covering test that asserts other such subjects, because each subject is a place of its own.
  - A test can assert one subject with two or more different argument lists, e.g. `$mapping->get(1)` and
    `$mapping->get(2)`. Then each of these assertions also has a `bound:` token. The token keeps the arguments of the
    subject and of the assertion as `#<position or name>:<value>`, so each expected value stays bound to its argument.
    The value is the literal. An argument that holds no literal has the names it holds (see *Argument names*), such as
    `variable:a` or `value:list-item/element:variable:a`, and an argument without names has its source code, such as
    `bar($a)`. An argument that holds a literal, such as `$r['a']` or `[$a, 1]`, has its source code as well, so the
    literal stays in the token. `expect($mapping->get(1))->toBe(Column::VALUE)` has the token
    `bound:expect:variable:mapping/call:get#0:1/call:toBe#0:Column::VALUE`, and
    `expect($manager->consumePipelineSkip($event, pipelineId: 99))->toBeFalse()` has
    `bound:expect:variable:manager/call:consumePipelineSkip#0:variable:event#pipelineId:99/call:toBeFalse`. A literal
    or a constant as the receiver of the subject is kept as well, e.g.
    `bound:expect:const:Status::A/call:label/call:toBe#0:'a'`. The value that a cast subject casts is kept by its
    token when it is a literal or a constant, and by its source code when it carries no token of its own, e.g.
    `bound:expect:cast:int/value:'5'/call:toBe#0:5`, `bound:expect:cast:int/$r['a']/call:toBe#0:1` and
    `bound:expect:cast:object/[1]/call:toEqual#0:1`.
    So `expect(json_encode($a))->toBe('x'); expect(json_encode($b))->toBe('y');` against the same assertions with the
    values swapped is `review`, not `delete`. The failure message of the
    assertion is left out, such as `'first'` in `toBe(Column::VALUE, 'first')` or in
    `assertSame(Column::VALUE, $mapping->get(1), 'first')`, because it does not change the pairing. A `bound:` token is
    not data, so a candidate that expects the same values for swapped arguments, or another value for the same
    argument, is `review`. A test that asserts each subject with one argument list has no `bound:` token, because its arguments and
    its expected values already pair up.
- **Array keys and values.** An array key carries a `key:` prefix and the path of its array, the same path a literal in
  the array has: `key:value:'x'` for `$options = ['x' => 1]` that the test never passes on,
  `in:function:store#0/key:value:'x'` for `store(['x' => 1])`, and `in:function:store#0/value:'user'/key:value:'x'` for
  `store(['user' => ['x' => 1]])`. An enum case read through `->value` or `->name` as a key names the property it
  reads, like an argument value: `in:function:store#0/key:const:CouponType::FIXED->value` for
  `store([CouponType::FIXED->value => true])`. A literal under an array key carries the key as a path prefix, e.g.
  `in:value:'enabled'/value:false` for `$options = ['enabled' => false]` that the test never passes on, so the same
  literal under another key, or in a list without a key, is a different token. A map passed to a call also carries the
  call and the argument position (see *Call arguments*).
- **Order of array keys.** An array with two or more keys also carries one token for each pair of its keys, with the
  earlier key first: `keys:value:'a'>value:'b'` for `$options = ['a' => 1, 'b' => 2]`, and
  `in:function:store#0/keys:value:'a'>value:'b'` for `store(['a' => 1, 'b' => 2])`. An array with three keys has three
  such tokens. The same keys in another order give other tokens, so two dataset rows whose arrays differ only in the
  order of their keys differ in `differsBy`, and the candidate is `review`, not `delete`. The order is input when the
  test reads the array in order, for example in a `foreach` that inserts records. A property as a key takes part by
  its name (see *Argument names*), e.g. `in:function:transfer#0/keys:variable:a/property:id>variable:b/property:id`
  for `transfer([$a->id => 1, $b->id => 1])`, and the name is also the path of the value under it, e.g.
  `in:function:transfer#0/variable:a/property:id/value:1`. A variable as a key takes part by its name the same way,
  e.g. `in:function:store#0/keys:variable:a>variable:b` and `in:function:store#0/variable:a/value:1` for
  `store([$a => 1, $b => 2])`. A key that no token or name names, such as an element read `$keys[0]`, and an item
  without a key add nothing. A test whose keys are a part of the keys of its covering test, in the
  same order, has no order token the covering test lacks. An order token of a key that the covering test lacks is not
  listed either, because that key is listed already. The order tokens of the covering test are never listed in
  `coveringDiffersBy`, because the keys that cause them are listed already.
- **Empty arrays.** An empty array literal `[]` carries the token `value:[]`. Under an array key it carries the key path
  too, e.g. `in:value:'tags'/value:[]` for `['tags' => []]`.
- **Lists.** A non-empty array literal whose elements have no key, and none of which unpacks an array with `...`,
  carries the token `value:list-item` once per element. The token has the path an element in the place of the list
  would have, e.g. `in:function:notify#0/value:list-item` counted three times for `notify([$click, $click, $click])`.
  The length of a list therefore counts even when its elements carry no token, such as a variable whose later reads
  add no token (see *Local variables*): a test that passes the same item three times in one list lists the token in
  `differsBy` against a test that passes it once, and is not `delete`. A shorter list adds nothing to `differsBy`,
  because a token the candidate has fewer times is never listed.
- **Order of list elements.** A list with two or more elements also carries one token for each pair of its elements,
  with the earlier element first, like the order of array keys: `in:function:store#0/items:value:3>value:5` for
  `store([3, 5])`, and `items:value:1>value:2` for `$ids = [1, 2]` that the test never passes on. A list with three
  elements has three such tokens. The same elements in another order give other tokens, so two dataset rows or tests
  whose lists differ only in the order of their elements, such as `[3, 5]` and `[5, 3]`, differ in `differsBy`, and the
  candidate is `review`, not `delete`. An element takes part when it carries a token of its own: a literal, a constant,
  an enum case, a call or a `new` expression without arguments, or an empty array. A variable or a property takes part
  by its name (see *Argument names*), e.g. `in:function:transfer#0/items:variable:a>variable:b` for
  `transfer([$a, $b])`, so `[$a, $b]` and `[$b, $a]`, or `[$a, 1]` and `[1, $a]`, differ and neither is `delete`
  against the other. A property takes part by its name, which names its object too, so `[$a->id, $b->id]` has
  `in:function:transfer#0/items:variable:a/property:id>variable:b/property:id` and is `review`, not `delete`, against
  `[$b->id, $a->id]`. A non-empty array, an operator (see *Argument names*), and a call or a
  `new` expression with arguments, take part by their source code, e.g.
  `in:function:total#0/items:new Money(1)>new Money(2)` for `total([new Money(1), new Money(2)])`,
  `in:function:fullName#0/items:$first . $last>variable:title` for `fullName([$first . $last, $title])`, and
  `items:['id' => 1]>['id' => 2]` for two rows of a dataset. That source code is normalized: the quotes of a string,
  the base of an integer, the case of a constant and a comment do not change it, so `["id" => 0x10]` and
  `['id' => 16]` are the same element. A closure adds nothing. `differsBy` lists an order token only
  when the covering test has the same two elements in the other order. The order token of an element that the
  covering test lacks is not listed, because that element is listed already. So a test whose list has another or an
  extra element of any kind stays `merge-into-dataset`, and a test that swaps elements the covering test also has is
  `review`, also when another value differs. The order tokens of the covering test are never listed in
  `coveringDiffersBy`.
- **Order of arranging calls.** A test whose body makes two or more calls with literal input also carries one token
  for each pair of these calls, with the earlier call first, like the order of list elements:
  `calls:store(1)>store(2)` for `store(1); store(2);`. A call takes part when it is a statement of the test body, or
  the value assigned in one, when it is not an assertion, and when its arguments hold a literal or a constant. A call
  is named by its normalized source code, like a composite list element, without the variable it is assigned to:
  `$matching = Model::factory()->create(['c_fields' => null])` takes part as
  `Model::factory()->create(['c_fields' => null])`. A test with three such calls has three such tokens. Two tests or
  dataset rows that make the same calls in another order, such as two tests that create the same records in another
  order, differ in `differsBy`, and the candidate is `review`, not `delete`. Two tests that make the same calls in the
  same order under other variable names have the same tokens. A call without literal input, an assertion, and a
  statement that is not a call add nothing. `differsBy` lists an order token only when the covering test makes the
  same two calls in the other order, so a test that makes another or an extra call keeps its action. The order tokens
  of the covering test are never listed in `coveringDiffersBy`.
- **Call arguments.** A literal, constant or empty array passed straight to a call or a `new` expression carries the
  call and the argument position, e.g. `in:function:store#0/value:true` and `in:function:store#1/value:false` for
  `store(true, false)`, so the same literals at swapped positions are different tokens. A named argument carries its
  name instead of the position, e.g. `in:function:store#enabled/value:true`. An enum case read through `->value` or
  `->name` names the property it reads, e.g. `in:function:store#0/const:CouponType::FIXED->value` for
  `store(CouponType::FIXED->value)`, so the value, the name and the case itself are different tokens. An element of a
  list passed straight to a call carries the same path, e.g. `in:function:store#0/value:'a'` for `store(['a'])` and
  `in:function:store#0/const:CouponType::FIXED->value` for `store([CouponType::FIXED->value])`. A value under a key of
  an array passed straight to a call carries the call and the position before the key, e.g.
  `in:function:store#0/value:'enabled'/value:false` for `store(['enabled' => false])`, so the same array at swapped
  positions is a different token. A closure or an arrow function passed to a call passes on the value it returns:
  that value carries the call and the position of the closure, e.g. `in:function:store#0/value:1` for
  `store(fn () => 1)`. So `store(fn () => 1, fn () => 2)` against `store(fn () => 2, fn () => 1)` is
  `merge-into-dataset`. The value a closure returns is the expression of each of its own `return` statements (see
  *Assertion subjects*). A closure that the test assigns to a variable and then passes on carries no such place, so
  `$f = fn () => 1; $g = fn () => 2; store($f, $g);` against the same test with the values swapped is `delete`.
  Any other literal or constant in the body of such a closure carries the place of the closure, then `closure`, then
  its path inside the closure: each step is the operand of an operator, written `operator:<kind>#<place>` as in
  *Argument names*, or else the part of the PHP syntax tree it sits in, such as `stmts#0`, `cond` or `expr`.
  `$mock->withArgs(fn ($a, $b) => $a === true && $b === true)` has
  `in:call:withArgs#0/closure/expr/operator:booleanand#0/operator:identical#1/value:true` and
  `in:call:withArgs#0/closure/expr/operator:booleanand#1/operator:identical#1/value:true`, so each place of a literal
  that differs inside a closure is its own entry in `differsBy`. A key or a cast inside the closure adds no place of
  its own: `$mock->withArgs(fn ($a) => $a === ['approval' => true])` has
  `in:call:withArgs#0/closure/expr/operator:identical#1/items#0/value/value:true`. A literal in an argument of a call
  inside the closure keeps the place of that call.
- **Non-literal arguments.** An argument of a call that is not an assertion, whose value is not a literal, a constant,
  an enum case, a list or a map, carries the call and the position followed by `non-literal`, such as
  `in:function:recalculate#2/non-literal` for `recalculate($job, 5, $campaign->id)`. It names the position only, so a
  test that lacks the position differs from the one that fills it. The variable or the property at the position has a
  token of its own (see *Argument names*). Both tokens are left out of a difference that names the call itself
  already, as `function:recalculate` does for a call the other test does not make. A variable that the
  test assigns once and passes on is read as the value it holds, so `$url = fake()->url(); store($url)` has the call
  tokens of `fake()->url()` and the token of the position.
- **Argument names.** A variable or a property passed straight to a call, a function call, a `new` expression or an
  assertion carries the call and the position followed by its name: `variable:<name>` for a variable and
  `property:<name>` for a property, after the name of its object. `connect($this->enShop)` has the token
  `in:function:connect#0/variable:this/property:enShop`, `recalculate($job, 5, $campaign->id)` has
  `in:function:recalculate#0/variable:job` and `in:function:recalculate#2/variable:campaign/property:id`, and
  `expect(linksOf($account))->toContain($canvaUserId)` has
  `in:expect:function:linksOf/call:toContain#0/variable:canvaUserId`. A nullsafe property is a property too:
  `connect($shop?->url)` has `in:function:connect#0/variable:shop/property:url`. A static property is named with its
  class as written, so `connect(self::$enShop)` has `in:function:connect#0/property:self::enShop`. A variable or
  property inside a cast that is the argument carries the position of that argument, so `toBe((string) $campaign->title)`
  has `in:expect:function:resolveUtm/call:toBe#0/variable:campaign/property:title` for
  `expect(resolveUtm($campaign))`. The cast has a token of its own (see *Casts*). Each variable or property inside an operator, a ternary or an interpolated string that is
  the argument carries the position of that argument, the place of its operand and the prefix `operand:`, because the
  argument holds the name and is not the name. The place of an operand is `operator:<kind>#<number>`: the kind of the
  operator and the number of the operand, counted from 0 from the left. The kind is the name of the operator in lower
  case, e.g. `plus`, `minus`, `concat`, `coalesce`, `unaryminus`, `booleannot`, `ternary` or `interpolatedstring`. A
  ternary numbers its condition 0, the value if true 1 and the value if false 2. An interpolated string numbers each of
  its parts, text and names alike. The operator itself carries `operator:<kind>` at its place. An operator inside an
  operator adds its own place, outermost first. A literal or a constant operand carries its place too, before its own
  token and with no prefix. So `toBe('no_segment_tag_' . $campaign->title)` has
  `in:expect:function:resolveUtm/call:toBe#0/operator:concat`,
  `in:expect:function:resolveUtm/call:toBe#0/operator:concat#0/value:'no_segment_tag_'` and
  `in:expect:function:resolveUtm/call:toBe#0/operator:concat#1/operand:variable:campaign/property:title`,
  `toBe("no_segment_tag_{$campaign->title}")` has
  `in:expect:function:resolveUtm/call:toBe#0/operator:interpolatedstring#1/operand:variable:campaign/property:title`,
  and `connect($primary ? $enShop : -$debit)` has `in:function:connect#0/operator:ternary#0/operand:variable:primary`,
  `in:function:connect#0/operator:ternary#1/operand:variable:enShop` and
  `in:function:connect#0/operator:ternary#2/operator:unaryminus#0/operand:variable:debit`. A test that passes `$x` and
  a test that passes `$x . $y`, `$c ? $x : 1`, `-$x` or `"{$x}"` differ, and neither is `delete` against the other.
  Two tests whose arguments hold another operator, such as `foo($x + 1)` and `foo($x - 1)`, `foo(!$x)` and
  `foo(-$x)`, or `foo(1 + 2)` and `foo(1 - 2)`, differ too. So do two tests whose arguments hold the same names at
  other places, such as `foo($x . $y)` and `foo($y . $x)`, or `toBe($c ? $x : $y)` and `toBe($c ? $y : $x)`, and a
  test whose operator has fewer operands than the one of its covering test, such as `foo($x . $y)` covered by
  `foo($x . $y . $z)`, `foo(1 + 2)` covered by `foo(1 + 2 + 3)`, or `foo(f() . 'a')` covered by
  `foo(f() . 'a' . 'b')`. The candidate is `review` in each of these cases. Two tests whose literal operands swap
  places, such as `foo(1 - 2)` and `foo(2 - 1)`, differ by the place of each literal, and the candidate is
  `merge-into-dataset`, as for literal arguments that swap places. These operators are the binary operators, the
  ternary, the interpolated string, the unary `-`, `+`, `!` and `~`, and the spread `...` of an argument. A negative
  number, such as `-5`, is a literal and not an operator. A spread argument has the kind `spread` and its value is
  operand 0, so `foo(...$x)` has `in:function:foo#0/operator:spread` and
  `in:function:foo#0/operator:spread#0/operand:variable:x`, while `foo($x)` has `in:function:foo#0/variable:x`. A test
  that spreads a variable or a property and a test that passes it plain, such as `foo(...$x)` and `foo($x)`, differ,
  and neither is `delete` against the other. The same holds for an argument that carries no name, such as a call, a
  list or a constant, of a call that is not an assertion: `foo(f())` and `foo(...f())`, `foo([1, 2])` and
  `foo(...[1, 2])`, or `foo(Foo::ITEMS)` and `foo(...Foo::ITEMS)` differ, and neither is `delete` against the other.
  When only the covering test spreads such an argument, the candidate is `review` and its `coveringDiffersBy` names the
  spread, such as `in:function:foo#0/operator:spread`. The following shapes are still `delete`:
  - An operator in no argument of a call or an assertion, such as one that a closure returns or one assigned to a
    variable that the test passes on. It carries no token, so `foo(fn () => $x + 1)` against `foo(fn () => $x - 1)`
    and `$y = $x + 1; foo($y)` against `$y = $x - 1; foo($y)` are `delete`. An operator in an argument of a call
    inside a closure carries its token, so `foo(fn () => bar($x + 1))` against `foo(fn () => bar($x - 1))` is
    `review`.
  - An operator other than a spread that only the covering test has, around an argument that carries no name, such
    as `foo(f())` covered by `foo(f() . 'a')`. Like a name that only the covering test has, it does not turn the
    candidate into `review`.
  - A spread that only the covering test has, around an argument of an assertion that carries no name, such as
    `expect(f())->toBe(1)` covered by `expect(...f())->toBe(1)`, or `$this->assertSame(1, f())` covered by
    `$this->assertSame(1, ...f())`. The code under test gets the same input in both tests, so the candidate is
    `delete`.
  - An operator that is not in the list above, such as `instanceof`, because it carries no token. A spread inside a
    list is not the spread of an argument and carries no token either, so `foo([...$x, ...$y])` against
    `foo([...$y, ...$x])` is `delete`.

  Each variable or property that is a key or a value of an array that a
  call or an assertion receives, at any depth, carries the position of that argument too, and so does the variable
  that an array element read holds, such as `$row` in `$row['email']`. Such a name has the prefix `element:`, because
  the argument holds the variable and is not the variable. Before the prefix, the name carries its place inside the
  argument: the key of each array it sits under, written as in the path of a value (see *Array keys and values*), or
  `value:list-item` for an element of a list. A key, and the variable that an element read holds, take the place of
  their array or element read. `toBe($rows[0])`, `toBe([$x])`, `toBe([[$x]])` and `toBe(['a' => $x])` have
  `in:expect:function:f/call:toBe#0/element:variable:rows`, `.../value:list-item/element:variable:x`,
  `.../value:list-item/value:list-item/element:variable:x` and `.../value:'a'/element:variable:x`, while `toBe($x)` has
  `.../variable:x`. A key that is itself a variable or a property names the value under it, so `[$k => $x]` has
  `.../element:variable:k` and `.../variable:k/element:variable:x`. The prefixes `element:` and `operand:` combine in
  the order the name is reached, so `toBe([$x . $y])` has
  `.../value:list-item/operator:concat#0/element:operand:variable:x` and `toBe($rows[0] . 'a')` has
  `.../operator:concat#0/operand:element:variable:rows`. So a test that passes `$x` and a test that passes
  `[$x]` differ, and two tests that hold the same names at other places of one argument, such as
  `['from' => $a, 'to' => $b]` and `['from' => $b, 'to' => $a]`, differ too: neither is `delete` against the other.
  Names that swap places inside a list, such as `[$a, $b]` and `[$b, $a]`, differ by their order tokens (see *Order of
  list elements*).
  `expect($response->updatedEmails())->toBe([$subscriber->email => ['field1' => 'value1']])` has
  `in:expect:variable:response/call:updatedEmails/call:toBe#0/element:variable:subscriber/property:email`. A candidate
  with `toBe([])` is then `review`, not `merge-into-dataset`, against that test, because a dataset row cannot hold the
  key. So two tests that pass or expect different variables or properties differ in `differsBy`, and the candidate is
  `review`. The name of a property names its object too when the object is a variable, a property or a class, so
  `$account->name` and `$user->name` differ, `$this->shop->url` has `variable:this/property:shop/property:url`, and
  `$class::$shop` has `variable:class/property:shop`. A property of an object that has no name, such as the result of
  a call or an element read in `$a->get()->id` or `$a['x']->id`, has `property:<name>` alone. So
  `transfer([$a->get()->id, $b->get()->id])` against `transfer([$b->get()->id, $a->get()->id])` is still `delete`, as
  `transfer([$a->get(), $b->get()])` against `transfer([$b->get(), $a->get()])` is. Properties that swap places inside
  an operator, a ternary or an interpolated string, such as `transfer($a->id . $b->id)` against
  `transfer($b->id . $a->id)`, differ by the place of each operand, and the candidate is `review`. A
  property whose name is not written out, such as `$a->$field` or `$a->{'id'}`, has no name token, so
  `transfer($a->$field)` against `transfer($b->$field)` is `delete` too. A
  parameter of the test closure or test method carries no name, because each dataset row fills it with another value. A
  property of a parameter is still named with the parameter, e.g. `variable:row/property:id` for `$row->id`. A variable
  that the test assigns once and passes on where it first reads it carries no name there, because that read is counted
  as the value the variable holds. A later read of the variable carries the name. A name that only the covering test has
  does not turn a `delete` candidate into `review`: the candidate passes another value at the same position, or the
  position token already lists the argument.
- **Closure arguments.** A closure or an arrow function that is an argument of a call, a `new` expression or an
  assertion carries the call and the position followed by `closure`, next to the value it returns (see *Call
  arguments*): `store(fn () => 1)` has `in:function:store#0/closure`. A closure inside an array carries the place of
  its key or `value:list-item` and the prefix `element:`, as a variable does, so `store(['k' => fn () => 1])` has
  `in:function:store#0/value:'k'/element:closure` and `store([fn () => 1])` has
  `in:function:store#0/value:list-item/element:closure`. A closure that another closure returns carries no token of
  its own. A closure is not data, because a dataset row cannot fill its slot with a literal. A candidate is
  therefore `review`, and not `merge-into-dataset`, when its covering test passes a closure that returns another value
  where the candidate passes a literal, at a place the token covers: an argument of a call, a `new` expression or an
  assertion, an array key or a list item, or an operand. Such are `foo('a')` against `foo(fn () => 1)` or
  `foo(function () { return 1; })`, `foo(['k' => 'a'])` against `foo(['k' => fn () => 1])`, `foo(['a'])` against
  `foo([fn () => 1])`, or `toBe('a')` against `toBe(fn () => 1)`. The token of the closure is listed in
  `coveringDiffersBy`. Two tests that pass a closure at the same place carry the same token, so closures that return
  other values, such as `foo(fn () => 'a')` against `foo(fn () => 1)`, stay `merge-into-dataset`. A closure that a
  dataset row passes to a parameter of the test is no closure argument of the body: the type of the parameter decides
  whether a row takes the literal (see *Dataset rows*).
  Three shapes keep their verdict. A literal that equals the value the closure returns, under an array key or in an
  assertion argument, is still `delete`: `foo(['k' => 1])` against `foo(['k' => fn () => 1])`, or `toBe(1)` against
  `toBe(fn () => 1)`. A closure in a `match` arm carries no token, so `foo(match ($x) { 1 => 'a' })` against
  `foo(match ($x) { 1 => fn () => 1 })` is still `merge-into-dataset`. The same holds for a candidate whose `match` arm
  holds the closure: `toBe(match ($x) { 1 => fn () => 1 })` against `toBe(match ($x) { 1 => 1 })` is still `delete`,
  and with `fn () => 2` it is still `merge-into-dataset`.
  Two shapes are `review`, and no longer `delete` or `merge-into-dataset`. A candidate that passes a closure where the
  covering test passes a literal under an array key or in an assertion argument is `review`, whether the literal equals
  the value the closure returns or not: `foo(['k' => fn () => 1])` against `foo(['k' => 1])`, or `toBe(fn () => 2)`
  against `toBe(1)`. The same holds as an operand: `foo($a ?? fn () => 2)` against `foo($a ?? 1)`. An argument of an
  assertion that holds a closure and no literal keeps its source code in a `bound:` token, wherever the closure sits in
  the argument. So
  `expect(g($a))->toBe($c ?? fn () => foo()); expect(g($b))->toBe($c ?? fn () => bar());` against the same pair with
  `foo()` and `bar()` swapped is `review`.
- **Assertion reads.** A call that sits directly in an argument of an assertion, or in the receiver chain of that argument, such as
  `expect(activeState())` or `assertSame(5, $order->total())`, adds the token `read:` followed by its own token, such
  as `read:function:activeState`, beside the token itself. The token counts how many of the calls are such reads, so
  a difference can tell a call the covering test arranges from a call it only reads in an assertion. A call inside
  another call that the assertion receives, or inside a closure, is not a read. A `read:` token is never listed in
  `differsBy` or `coveringDiffersBy`.
- **Enclosing calls.** A value that an argument passes on through an array or a nested call carries every enclosing
  call and array key, outermost first. `json_encode(json_encode(['test' => true]))` has the token
  `in:function:json_encode#0/function:json_encode#0/value:'test'/value:true`, and `json_encode(['test' => true])` has
  `in:function:json_encode#0/value:'test'/value:true`, so a value passed through one more call is a different token.
  An assertion, such as a Pest expectation (`expect`, `toBe`) or a PHPUnit `assert*` or `expect*` call, is left out
  of the path of a value that reaches it through another call: `expect(add(1, 2))` has the token
  `in:function:add#0/value:1`, the same as `$sum = add(1, 2)`. An operand of an operator, a cast or a closure body
  keeps no call path in its own token, except for the value that a closure passed to a call returns (see *Call
  arguments*): `store(1 + 2)` has the tokens `value:1` and `value:2`. An operator in an
  argument adds the place of the argument instead: the operator carries `in:function:store#0/operator:plus`, and each
  literal operand carries its place in the operator, `in:function:store#0/operator:plus#0/value:1` and
  `in:function:store#0/operator:plus#1/value:2` (see *Argument names*). The literal part of an interpolated string
  keeps no call path in its own token either: `store("tag_{$name}")` has the token `value:'tag_'` and, at its place,
  `in:function:store#0/operator:interpolatedstring#0/value:'tag_'`. A list item keeps the call
  path as a direct item of an argument and as an item of a list under a key: `store([1])` has the token
  `in:function:store#0/value:1`, and `store(['tags' => ['a']])` has `in:function:store#0/value:'tags'/list/value:'a'`,
  where the `list` segment marks the list under the key. A list inside a list keeps no call path: `store([[1]])` has
  `value:1`. So `store([[1]])` does not get the token of `store([1])` or `store(1)`, and `store(['tags' => ['a']])` does
  not get the token of `store(['tags' => 'a'])`. An item of a list under a key of an array no call receives keeps the
  key path with the `list` segment: `$options = ['tags' => ['a']]` has `in:value:'tags'/list/value:'a'`. A list passed straight to a call keeps the token of its element: `store([1])` has the token
  of `store(1)` and the token of the list (see *Lists*).
- **Dataset rows.** A dataset row value is the value of the parameter at the same position of the test closure or
  method. A literal, constant, empty array, list or map in a row is counted where the test reads that
  parameter: the row `[1, 2, 3]` of `expect(add($first, $second))->toBe($sum)` has the same tokens as
  `expect(add(1, 2))->toBe(3)`, the row `[['id', 'tags']]` of `load($columns)` has the same tokens as
  `load(['id', 'tags'])`, and the row `[['id' => 5]]` of `load($options)` has the same tokens as `load(['id' => 5])`.
  A list is a non-empty array whose elements have no key and are each a literal, constant, empty array, list or map. A
  map is a non-empty array whose elements all have a key that is a literal or constant, and each value is a literal,
  constant, empty array, list or map, so lists and maps can nest. Any other row value, such as `new Contact()` or
  `['id' => $id]`, is counted once, as the row has it, however many times the test reads the parameter. A row value whose parameter the test never reads by its name keeps its
  token without a path. A closure or arrow function that is the row value, or one argument of the row, also carries
  the token `value:closure:<hash>`, a hash of its source as `nikic/php-parser` prints it. Two rows whose closures have
  different bodies, such as `fn (string $email): string => $email` and
  `fn (string $email): string => rawurlencode($email)`, therefore differ in `differsBy`, and the row is not `delete`.
  Two closures that differ only in whitespace or line breaks have the same token. A closure inside an array argument,
  such as `['map' => fn ($x) => $x]`, carries only the tokens of its body.
  A test that combines several datasets, through several arguments of one `->with()` or through chained `->with()`
  calls, gets one row for each combination, as Pest runs it: the values of the rows follow one another in the order of
  the datasets, and the row name is the one Pest reports, such as `plain" / dataset "slash` for the rows `'plain'` and
  `'slash'`, or `('ab') / dataset "slash` for a row without a key. When a row of one dataset cannot be named, its
  combinations stay unresolved.
  A row without a key is named from its values, as Pest prints them, such as `('ab')`. When that name repeats in one
  dataset, every row with it gets the number Pest adds in the order of the rows: `['a', 'a', 'b', 'a']` gives
  `('a') #1`, `('a') #2`, `('b')` and `('a') #3`. A repeated name of a row with a key, and a repeated name in a
  dataset that a test combines with another one, stay unresolved.
  A row of a PHPUnit data provider with a string key is named by the key. Every other row is named by the counter
  PHPUnit uses, which starts at `0` and counts only those rows, whatever integer key a row has: `[[1], 'named' => [2],
  [3], 7 => [4]]` gives `0`, `named`, `1` and `2`. This holds for a provider that returns an array, and for a generator
  whose statements are only `yield` expressions. A key the analysis cannot read, such as a class constant, stops the
  counter, and the rows after it stay unresolved.
  The analysis reads the rows of a dataset that the test file declares: a literal array, a named dataset of the file or
  of a `describe()` block (a name that two blocks declare stays unresolved), a variable that the file writes once (a write in a loop counts too),
  a function of the file called without arguments, and a closure or a function whose statements are `yield`,
  `yield from` or one `return`, after local variables that it assigns once. Such a local variable is replaced by its
  value in the rows, also inside another local variable, but not inside a `function () use (…)` closure. A spread
  `...rows()` or `...$rows` inside a dataset array adds the rows of that function or variable. A dataset built by a
  loop, a condition, a call with arguments or a call of another class stays unresolved.
  A row that holds a class name, such as `ShouldNotHappen::class`, is named by the full class name as a string, as
  Pest prints it; `self::class` and `static::class` stay unresolved. `->with(Level::cases())` for an enum that the
  project declares gives one row for each case, in the order of the declaration.
  A dataset key or a row value that is an enum case or a class constant of a class declared in another file of the
  project is resolved through that declaration, as [What the analysis reads](#what-the-analysis-reads) describes. A
  row that holds the case is named the way Pest prints it, with the full class name:
  `(App\Enums\Level Enum (High, 2))` for a backed case and `(App\Enums\Mode Enum (Strict))` for a pure one. A row
  that holds an object of a class other than `stdClass` stays unresolved, because Pest names it by the properties
  the class declares (`(Report)` or `Report Object ()`).
- **Local variables.** A literal, constant, empty array, list or map (as defined for dataset rows) that the test
  assigns once to a local variable in its own statements is counted where the test reads the variable:
  `$email = 'a@example.com'; notify($email);` has the same tokens as `notify('a@example.com')`,
  `$columns = ['id', 'tags']; load($columns);` has the same tokens as `load(['id', 'tags'])`, and
  `$options = ['enabled' => false]; store($options);` has the same tokens as `store(['enabled' => false])`. A variable the test writes more than once keeps its tokens without a path.
  A call, function call or `new` expression that the test assigns once to a local variable is counted once, where the
  test first reads the variable, when that read passes the variable on as a call argument or an array value outside a
  loop body: `$email = sprintf('email@%s', $domain); notify($email);` has the same tokens as
  `notify(sprintf('email@%s', $domain))`, and a later read of the variable adds no token. A variable whose first read
  does not pass it on, such as `$user->email`, or whose first read is in a loop body keeps the tokens of the call
  without a path.

## How it works

1. One test run with `--coverage-php` and `--log-junit` records which test case — every dataset row separately —
   covers which source line. Test descriptions come from the JUnit log.
2. A greedy pass removes test cases whose lines the remaining test cases still cover. On a tie it removes standalone
   tests before dataset rows.
3. For each candidate it picks a small covering set greedily and compares contract fingerprints built with
   `nikic/php-parser`: Pest `it()` / `test()` with `->with()` datasets and chained modifiers such as `->throws()`, and
   PHPUnit `test*` / `#[Test]` / `@test` methods with `#[DataProvider]` / `@dataProvider` rows. Pest tests inside a
   namespace or `describe()` blocks, and shared `dataset('name', …)` rows of the same file, are parsed too.

### What the analysis reads

The fingerprint phase only parses PHP with `nikic/php-parser`. It never includes, autoloads or runs the analysed code,
and it never calls `constant()`, `class_exists()` or reflection on a name from it. Besides the test file, it reads one
kind of file: the declaration of a class whose enum case or constant a dataset key or a dataset row uses, such as
`IntegrationType::Upgates->value => [...]` or `->with([Level::High])`.

- The class name is resolved from the test file's `namespace` and `use` imports. `self`, `static`, `parent` and a
  name built at runtime are not resolved.
- The file comes only from the `psr-4` maps of `autoload` and `autoload-dev` in the project's own `composer.json`,
  read as JSON. Every segment of the class name must be a plain identifier.
- The file must resolve inside the project root, outside `vendor/`, and be at most 512 KiB. A symlink that leads out
  of the project root is not read.
- Only an enum case with a literal `string` or `int` value, a pure enum case, and a class constant with a literal
  `string` or `int` value are read. A value that is an expression or refers to another constant is not followed.
- Each file is parsed at most once per run. Any step that fails leaves the row unresolved, as before.
