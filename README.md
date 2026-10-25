<h1>
<img align="left" alt="pekral/test-redundancy — a coverage circle with a check mark in a frame" src="assets/logo.svg" width="120">
Fewer tests, the same coverage
<br>
<a href="LICENSE"><img alt="MIT Licensed" src="https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square"></a>
<img alt="Checks" src="https://github.com/pekral/test-redundancy/workflows/Checks/badge.svg">
<br clear="left">
</h1>

`test-redundancy` finds the test cases in a **PHPUnit** or **Pest** suite whose covered lines other test cases
already cover, and tells you — or an AI agent — what each of them asserts beyond those tests. Point it at a test folder
or file. It runs the tests once with per-test coverage and returns a JSON list of candidates to delete, merge into a
dataset, or review, so the suite keeps 100% coverage with fewer and simpler tests.

---

## Requirements

| | Version |
| --- | --- |
| PHP | `^8.4` |
| `phpunit/php-code-coverage` | `^10.1`, `^11`, `^12`, `^13` or `^14` — the one your PHPUnit or Pest installs |
| Coverage driver | `pcov` (the tool limits `pcov.directory` to the `source` directories) or `xdebug` |
| Test runner | `vendor/bin/pest`, or `vendor/bin/phpunit` with `vendor/bin/paratest` — the tool runs the tests only in parallel, so PHPUnit 13 needs 13.4+, the oldest version `paratest` supports |

Contributing to this repository needs PHP 8.4+ with `pcov`.

---

## Installation

```bash
composer require --dev pekral/test-redundancy
```

---

## Configuration

Optional. Without a file the tool reads `src/` and `app/`, runs 2 parallel processes, and picks `vendor/bin/pest`
when it exists, otherwise `vendor/bin/phpunit`. To change that, add `test-redundancy.json` to the project root:

```json
{
    "source": ["app"],
    "ignore": "#^app/Providers/#",
    "env": { "TELESCOPE_ENABLED": "false" },
    "processes": 6,
    "map": "storage/test-redundancy/coverage-map.json.gz",
    "runner": "vendor/bin/pest"
}
```

| Key | Default | Meaning |
| --- | --- | --- |
| `source` | `["src", "app"]` | Directories whose lines count. |
| `ignore` | none | A regex of source paths to leave out, e.g. code every test boots. |
| `env` | `{}` | Environment variables for every test run. Concurrent groups also get `TEST_TOKEN` 1…N, as `paratest` workers do. |
| `processes` | `2` | Parallel processes, at least `2`; `--processes` overrides it. The tool never runs the tests sequentially. |
| `map` | `.test-redundancy/coverage-map.json.gz` | Where the `map` command stores the coverage map. |
| `runner` | auto | Path to `pest` or `phpunit`, relative to the project root or absolute. |

---

## Usage

```bash
# A test folder or a single test file
vendor/bin/test-redundancy tests/Unit/Billing
vendor/bin/test-redundancy tests/Unit/Billing/InvoiceTest.php

# No path: the test files the branch changes against origin/HEAD, or against --base
vendor/bin/test-redundancy
vendor/bin/test-redundancy --base=master

# A report for people instead of JSON
vendor/bin/test-redundancy tests/Unit --format=text

# More or fewer parallel processes than the configuration says
vendor/bin/test-redundancy tests/Unit --processes=8

# After changing the tests: fail when a source line the last analyse covered is no longer covered
vendor/bin/test-redundancy verify
```

The JSON is compact; `--pretty` indents it.

The tests always run in parallel, on the test tokens 1…`processes`, so never start the tool next to another run of the
same suite. One folder or file runs in the runner's own parallel mode — `pest --parallel`, or `paratest` next to
`phpunit` — with the coverage settings passed to every worker through `--passthru-php` and the project's
`phpunit.xml` or `phpunit.xml.dist` passed through `--configuration`.
Several paths, such as the files a branch changes, are split into up to `processes` groups that run concurrently,
each with its own `TEST_TOKEN`. When a single folder holds test files that
[`analyse` skips](#test-files-with-a-single-test-case), the remaining files run as such groups too.
Every run stores the time of each test file from its JUnit log in
`.test-redundancy/durations.json` (next to the map). The next run puts the longest file first and every file into the
group with the smallest total, so no group collects the slowest files; a file without a stored time goes first. Without any
stored time the files are split round-robin. The split changes how long the run takes. For tests that share no state
inside one process it never changes the report or the baseline; a test whose coverage depends on what an earlier test
file in the same group already ran, such as a static cache, can cover other lines in another group.
Every run passes `--cache-directory=.test-redundancy/cache`, so the coverage analysis cache survives
between runs. `vendor/bin/test-redundancy analyse --help` lists every option.

### The JSON report

This is the report for the Pest fixture in [`tests/Fixtures/pest`](tests/Fixtures/pest), shortened to one candidate:

```json
{
  "files": [
    {
      "candidates": [
        {
          "action": "merge-into-dataset",
          "coveredBy": ["it adds two numbers › negative numbers"],
          "coveringDiffersBy": [],
          "differsBy": ["in:call:add#0/value:0", "in:call:add#1/value:5", "in:expect:new:Calculator/call:add/call:toBe#0/value:5"],
          "steps": [
            "Turn \"it adds two numbers › negative numbers\" into one test with a dataset and add a row with the values in differsBy.",
            "Delete this test.",
            "Run vendor/bin/test-redundancy verify; it must report \"ok\": true."
          ],
          "test": "it adds zero",
          "unionOnlyLines": []
        }
      ],
      "coveredLines": 4,
      "failedTests": [],
      "file": "tests/CalculatorTest.php",
      "testCases": 6,
      "testsWithoutSourceLines": []
    }
  ],
  "summary": {
    "byAction": { "delete": 1, "merge-into-dataset": 1, "review-dataset-row": 1, "review": 0 },
    "candidates": 3,
    "failedTests": 0,
    "seconds": 0,
    "skippedFiles": 1,
    "staleMap": null,
    "testCases": 7,
    "testFiles": 2,
    "testsWithoutSourceLines": 0
  },
  "tool": "pekral/test-redundancy",
  "workflow": ["Goal: 100% coverage with the fewest and simplest tests. Coverage cannot see assertions, so judge every candidate.", "…"]
}
```

| Field | Meaning |
| --- | --- |
| `files` | Only the files with a candidate, a test without source lines or a failed test. `summary.testFiles` counts every analysed file, and `summary.testCases` every test case, a skipped file included. |
| `testCases`, `coveredLines` | Test cases of the file, and the source lines they cover. |
| `coveredBy` | A small set of kept tests, picked greedily, that covers every line of the candidate. A test from another file carries its path. A single test that runs every line of the candidate and has an empty `differsBy` is preferred, so a test that passes the values of a dataset row is covered by that row, and the action is `delete`. That preferred single test can be a kept test, or a candidate that this report removes after this one. The action of that candidate then decides whether the shared input stays. |
| `differsBy` | The tokens the candidate has, or has more times, and the closest covering test lacks — see [How `differsBy` names a difference](#how-differsby-names-a-difference). `null` when the test or its dataset row cannot be parsed; the first step then says so. |
| `coveringDiffersBy` | The tokens of the covering test that made the action `review` although `differsBy` holds only data or is empty — see [When a candidate is `review`, not `delete`](#when-a-candidate-is-review-not-delete). The `review` steps name them. Empty for every other candidate. |
| `unionOnlyLines` | The source lines of the candidate that the covering test with `differsBy: []` does not run, because only the union of the `coveredBy` tests reaches them. The action is then `review`, and the steps say so. Empty for every other candidate. |
| `steps` | What to do with the candidate, naming every covering test, or the sibling dataset row. The last step always runs `verify`. |
| `action` | `delete` — nothing new; `merge-into-dataset` — differs only in data from one test of the same file; `review-dataset-row` — a row of the same dataset runs the same lines; `review` — asserts something the covering tests do not, differs from one test of the same file in more than data, is a row of a named dataset that another test uses, or could not be parsed. [When a candidate is `review`, not `delete`](#when-a-candidate-is-review-not-delete) lists the rules for `delete` and `merge-into-dataset`. |
| `testsWithoutSourceLines` | Tests that run no line under `source`. They are never candidates; check what they test, or the `source` setting. |
| `failedTests` | The test cases that failed in the coverage run, each as `{"test": …, "message": …}` with the failure message. The analysis leaves them out: a failed test case is never a candidate and never a covering test, and it is not counted in `testCases`. See [Failed tests](#failed-tests). |
| `summary.failedTests` | The number of failed test cases in the report. |
| `summary.staleMap` | With `--across`: `null`, or the map commit and the source files changed since it was built. |
| `summary.seconds` | How long the analysis took. |
| `summary.skippedFiles` | The number of test files `analyse` did not run, because each declares one test case and no dataset — see [Test files with a single test case](#test-files-with-a-single-test-case). They count in `summary.testFiles` and `summary.testCases` as one test case each and have no candidate. |
| `workflow` | The rules an agent follows for the whole report. |

### Failed tests

A failing test case does not discard the run. `analyse` leaves every failed or errored test case out of the analysis, so
it is never a candidate and never a covering test. Leaving out a covering test can only remove candidates, so the
result stays safe. The JSON report lists each of them with its failure message in `failedTests`, and the text format
prints a `Failed tests` list for the file. The coverage of every other test case is complete, and the measured durations
of every worker group that finished are stored for the next run.

`analyse` exits with `1` while a test case fails, so CI still notices it. Fix the failed tests and run `analyse` again
to include them. A process that ends without a coverage file or a JUnit log, such as a fatal error or a timeout, still
aborts the run. `map` and `verify` still abort when a test case fails.

### When a candidate is `review`, not `delete`

`delete` needs an empty `differsBy` and every one of these conditions. The tool checks them in this order: while
`unionOnlyLines` is not empty, `coveringDiffersBy` stays empty and the steps name only the lines and the covering tests.

- **One test runs every line.** One covering test runs every line of the candidate. When only the union of the
  `coveredBy` tests reaches some lines, they are listed in `unionOnlyLines` and the action is `review`.
- **No missing array key.** The covering test sets no array key, or sets no key more times, that the candidate lacks.
  A missing key is another input shape, so the `key:` tokens are listed in `coveringDiffersBy` and the action is
  `review`. A key passed only to an assertion, such as a key of the array `toEqual` expects, does not count, whether
  it is a top-level key of that array or a key nested in it.
- **No missing argument value.** The covering test passes no value, or passes no value more times, to a call that is
  not an assertion, such as an argument of the code under test (`in:call:getByListIdAndLastId#4/value:'email'`). A
  missing argument value is another input, so the value is listed in `coveringDiffersBy` and the action is `review`. A
  value passed only to an assertion, such as a Pest expectation (`toBe`) or a PHPUnit `assert*` or `expect*` call, does
  not count.
- **No missing arrangement for a test without an assertion.** A candidate without an assertion asserts only that the
  code under test throws no exception for its input. When the covering test has a call, function, `new` expression or
  value that the candidate lacks, such as `function:createDomain` for a record it creates before the call under test,
  the candidate can be the only test of an empty or missing input. The tokens are listed in `coveringDiffersBy`, the
  action is `review`, and the steps ask whether the empty or missing input is a case of its own. An assertion and a
  value or key passed only to an assertion do not count, so a covering test that only asserts more about the same input
  still leaves the candidate `delete`.
- **No other test uses the dataset.** A row of a named Pest dataset, `dataset('name', …)`, that another test of the
  same file also uses with `->with('name')` cannot be deleted for one test: deleting the row removes it from every
  test that uses the dataset. Such a row is `review`, not `delete`, and the first step names the dataset and the other
  tests. The same step comes first for every other action of such a row. A named dataset defined in another file is
  not parsed, so its rows are not checked.

`merge-into-dataset` needs a candidate that is not a dataset row, whose `differsBy` holds only data, and that differs
only in data from one test of the same file. When that test has a call, function or `new` expression that the
candidate lacks, or has it more times than the candidate does, the two tests cannot be rows of one test: the tokens are
listed in `coveringDiffersBy` and the action is `review`. When that test sets an array key that the candidate lacks,
such as `in:call:post#1/key:value:'default_min_spend'`, a row with the values in `differsBy` would still set the key,
so the case without the key would be lost: the `key:` tokens are listed in `coveringDiffersBy`, the action is `review`,
and the steps ask whether the code under test reads one of these keys. A key nested in a value that `differsBy`
already lists does not count, because a row replaces the whole value. For example, the key `'color'` in
`store(['custom_fields' => ['color' => 'red']])` is nested in the value that `differsBy` lists as
`in:function:store#0/value:'custom_fields'/value:[]` for `store(['custom_fields' => []])`, so that candidate stays
`merge-into-dataset`. A key that test passes only to an assertion does not count either, because it is not input to
the code under test. When that test passes an argument at a position where `differsBy` lists no value, such as
`in:function:store#1/value:2` for `store(1, 2)` against `store(3)`, a row with the values in `differsBy` would still
pass the argument, so the case without it would be lost: the argument tokens are listed in `coveringDiffersBy`, the
action is `review`, and the steps ask whether the code under test behaves differently without one of these values.
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

### How `differsBy` names a difference

`differsBy` lists the literals, constants, functions, calls and `new` expressions the candidate has and the covering
test lacks.

- **One covering test, not a union.** With several covering tests, `differsBy` is the smallest difference against one
  of them.
- **Counts.** The fingerprint counts each token, so a token the candidate uses more times than the covering test is
  listed too. The count is the number of places the token appears in the test source, not the number of runs.
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
  `expect($template->order)->toBeGreaterThan(0)` has `expect:property:order/call:toBeGreaterThan` and
  `in:expect:property:order/call:toBeGreaterThan#0/value:0`.
  - The subject of a Pest expectation is the value passed to the nearest `->and()` before the call, or else to
    `expect()`. Every call of the expectation chain carries it, `->and()` and `->each` included.
  - The subject of a PHPUnit `assert*` call is its last argument that is not a literal or a constant:
    `self::assertSame(3, add(1, 2))` has the token `expect:function:add/call:self::assertSame`.
  - A property carries the token `property:<name>`. A call, a function call and a `new` expression carry their own
    token.
  - A method call also carries its receiver before its own token: a variable by its name as `variable:<name>`, also
    when the test assigns it once, a property as `property:<name>`, and a call, a function call or a `new` expression
    as its own token.
    `expect($moment->format('Y-m-d'))` has the subject `variable:moment/call:format`, and
    `expect($interval->applyTo($moment)->format('Y-m-d'))` has `call:applyTo/call:format`. Only the nearest receiver
    is kept. A literal or a constant as the receiver adds nothing, so `expect(Status::Active->label())` has the subject
    `call:label`.
  - A variable that the test assigns once in its own statements stands for the value assigned to it:
    `$sum = add(1, 2); expect($sum)->toBe(3);` has the same assertion tokens as `expect(add(1, 2))->toBe(3)`.
  - A literal, a constant, any other variable, a closure and any other expression give no subject. The assertion then
    keeps its token without the prefix, e.g. `call:toBe`.
  - A test can assert one subject with two or more different literal argument lists, e.g. `$mapping->get(1)` and
    `$mapping->get(2)`. Then each of these assertions also has a `bound:` token. The token keeps the literal arguments
    of the subject and of the assertion as `#<position or name>:<literal>`, so each expected value stays bound to its
    argument. `expect($mapping->get(1))->toBe(Column::VALUE)` has the token
    `bound:expect:variable:mapping/call:get#0:1/call:toBe#0:Column::VALUE`, and
    `expect($manager->consumePipelineSkip($event, pipelineId: 99))->toBeFalse()` has
    `bound:expect:variable:manager/call:consumePipelineSkip#pipelineId:99/call:toBeFalse`. The failure message of the
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
  test reads the array in order, for example in a `foreach` that inserts records. A key that no token names, such as a
  variable, and an item without a key add nothing. A test whose keys are a part of the keys of its covering test, in the
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
  positions is a different token.
- **Enclosing calls.** A value that an argument passes on through an array or a nested call carries every enclosing
  call and array key, outermost first. `json_encode(json_encode(['test' => true]))` has the token
  `in:function:json_encode#0/function:json_encode#0/value:'test'/value:true`, and `json_encode(['test' => true])` has
  `in:function:json_encode#0/value:'test'/value:true`, so a value passed through one more call is a different token.
  An assertion, such as a Pest expectation (`expect`, `toBe`) or a PHPUnit `assert*` or `expect*` call, is left out
  of the path of a value that reaches it through another call: `expect(add(1, 2))` has the token
  `in:function:add#0/value:1`, the same as `$sum = add(1, 2)`. An operand of an operator, a cast or a closure body
  keeps no call path: `store(1 + 2)` has the tokens `value:1` and `value:2`. A list item keeps the call path only as a
  direct item of an argument: `store([1])` has the token `in:function:store#0/value:1`, but `store([[1]])` has
  `value:1` and `store(['tags' => ['a']])` has `in:value:'tags'/value:'a'`. So `store([[1]])` does not get the token
  of `store([1])` or `store(1)`, and `store(['tags' => ['a']])` does not get the token of `store(['tags' => 'a'])`. A
  list passed straight to a call keeps the token of its element: `store([1])` has the token of `store(1)` and the
  token of the list (see *Lists*).
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

### Test files with a single test case

Without `--across`, `analyse` compares a test case only with the other test cases of the same file, so a file with one
test case can never hold a candidate. `analyse` therefore does not run such a file. It counts the test cases of each
file before the coverage run with the same `nikic/php-parser` fingerprints as the rest of the report, and skips a file
only when all of these hold:

- The file declares exactly one Pest `it()` / `test()` or one PHPUnit test method.
- No dataset reaches it: no `->with()`, no `->repeat()`, no `#[DataProvider]`, `#[DataProviderExternal]`, `#[TestWith]`,
  `@dataProvider` or `@testWith`. A named or shared dataset, a dataset the parser cannot resolve, and a `describe()`
  block with a dataset all keep the file in the run, because the number of runs is known only at run time.
- The parser resolves every `it()` / `test()` call. A test that a loop generates, a test with a description that is not a
  string literal, two tests with the same description, and a file that does not parse keep the file in the run.

A skipped file stays in the report: it counts in `summary.testFiles` and `summary.testCases` as one test case, it is
counted in `summary.skippedFiles`, and it has no candidate, no `coveredLines` and no `testsWithoutSourceLines`, because
it did not run. The candidates of every other file are the same as when the file runs. `--across` and `map` run every
file, because there a single test case is compared with the test cases of other files.

### Verifying that coverage stayed

`analyse` stores the source lines the analysed tests cover in `.test-redundancy/baseline.json` (next to the map). Add
`.test-redundancy/` to `.gitignore`; the map, the baseline, the file durations and the runner cache are local state. After
the tests change, `verify` runs the same test files again and compares:

```json
{"baselineLines":4,"instruction":"Every source line the analysed tests covered before is still covered.","lostLines":[],"missingTestFiles":[],"ok":true,"tool":"pekral/test-redundancy","unstableLines":[]}
```

A test file that `analyse` skipped stays in the baseline without lines, so `verify` runs it and reports its deletion in
`missingTestFiles`. `verify` cannot know the lines that only a deleted skipped file covered, because `analyse` never ran it.

It exits with `1` and lists every line in `lostLines` when a change dropped coverage. After `--across`, a line another
mapped test file still covers does not count as lost.

`analyse` also stores a hash of every analysed test file, of every covered source file and of the test support files.
The support files are the other files, not named `*Test.php`, in the top-level directory of a test file, such as
`tests/`: Pest datasets, `tests/Pest.php`, base test cases, traits and fixtures. When a line is no longer covered, none
of the test files that covered it changed, its source file did not change and no support file changed, `verify` lists it
in `unstableLines` instead of `lostLines`, exits with `0` and tells the agent not to restore a test. The coverage of such
a test depends on state outside the test run, such as a cache or a database. A line that a changed or deleted test file
also covered, a line of a source file that changed since `analyse`, and every lost line after a support file was
changed, added or deleted stay in `lostLines`, and `verify` exits with `1`. When a changed support file is the reason and no
baseline test file is missing, the `instruction` tells the agent to check that file or to run `analyse` again, not to
restore a test. When a baseline test file is missing too, the `instruction` still tells the agent to restore the test and
adds that a changed support file may also be the reason. A baseline written before source file hashes
or support file hashes existed reports every lost line in `lostLines`; run `analyse` again to restore the distinction.

### How to read a candidate

A candidate runs no line the other tests do not run. Coverage cannot see what a test asserts, so a candidate is not
proof that a test is useless:

1. **Delete** it only when a test under `coveredBy` asserts the same input and the same result.
2. **Merge it into a dataset** when the two differ only in data.
3. **Keep** a test that asserts a different result, a different input class (empty, boundary, another tenant,
   invalid), an ordering, or a rejection. When in doubt, keep it.

The candidates of one file can be removed together without losing a covered line. Re-run the tool after each change.

### Comparing across files

```bash
# Per-test coverage of the given tests (default: tests)
vendor/bin/test-redundancy map tests

# Compare a folder or a file with every mapped test
vendor/bin/test-redundancy tests/Unit/Billing --across
```

`--across` reads unchanged test files from the map and re-runs only the changed ones. It warns when source files changed
since the map was built. Of two tests that cover each other, it proposes at most one.

---

## How it works

1. One test run with `--coverage-php` and `--log-junit` records which test case — every dataset row separately —
   covers which source line. Test descriptions come from the JUnit log.
2. A greedy pass removes test cases whose lines the remaining test cases still cover. On a tie it removes standalone
   tests before dataset rows.
3. For each candidate it picks a small covering set greedily and compares contract fingerprints built with
   `nikic/php-parser`: Pest `it()` / `test()` with `->with()` datasets and chained modifiers such as `->throws()`, and
   PHPUnit `test*` / `#[Test]` / `@test` methods with `#[DataProvider]` / `@dataProvider` rows. Pest tests inside a
   namespace or `describe()` blocks, and shared `dataset('name', …)` rows of the same file, are parsed too.

---

## Testing

```bash
composer install
composer test
```

| Command | What it does |
| --- | --- |
| `composer check` | Composer normalize, PHPCS, Pint, Rector, PHPStan, security audit, and the tests with 100% coverage |
| `composer fix` | Composer normalize, Rector, Pint and PHPCS fixes |
| `composer test` | The tests without coverage |
| `composer test:coverage` | The tests with a 100% coverage minimum |
| `composer ai-olympus:install` | Reinstall the [pekral/ai-olympus](https://github.com/pekral/ai-olympus) agent rules and skills |

PHPCS, PHPStan and Rector use [pekral/phpcs-rules](https://github.com/pekral/phpcs-rules),
[pekral/phpstan-rules](https://github.com/pekral/phpstan-rules) and [pekral/rector-rules](https://github.com/pekral/rector-rules).

### CI

Pull requests and pushes to `master` run `composer check` on PHP 8.4 and 8.5, then the tool on its own tests. A matrix
runs the tests on PHP 8.4 and 8.5 with the lowest and the highest dependencies, and a second matrix runs the tool on
the PHPUnit fixture with PHPUnit 10.5, 11.5, 12.5 and 13.4 with `paratest` and no Pest, on PHP 8.4. A security audit runs every
Monday, and Dependabot proposes Composer and GitHub Actions updates weekly.

---

## Changelog

See [CHANGELOG](CHANGELOG.md).

## Contributing

See [CONTRIBUTING](CONTRIBUTING.md).

## Security

See [the security policy](SECURITY.md).

## License

MIT — see [LICENSE](LICENSE).
