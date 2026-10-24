# Changelog

All notable changes to `pekral/test-redundancy` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed

- The order of the keys of an array literal is part of its fingerprint. An array with two or more keys has one token
  for each pair of its keys, the earlier key first, e.g. `in:function:store#0/keys:value:'a'>value:'b'` for
  `store(['a' => 1, 'b' => 2])`. Two dataset rows that pass the same array with the keys in another order differ in
  `differsBy`, so such a row is no longer reported with the action `delete`. The order tokens of the covering test are
  not listed in `coveringDiffersBy`. Every array with two or more keys gets new tokens (#87).
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

[Unreleased]: https://github.com/pekral/test-redundancy/compare/0.1.4...HEAD
[0.1.4]: https://github.com/pekral/test-redundancy/compare/0.1.3...0.1.4
[0.1.3]: https://github.com/pekral/test-redundancy/compare/0.1.2...0.1.3
[0.1.2]: https://github.com/pekral/test-redundancy/compare/0.1.1...0.1.2
[0.1.1]: https://github.com/pekral/test-redundancy/compare/0.1...0.1.1
[0.1]: https://github.com/pekral/test-redundancy/releases/tag/0.1
