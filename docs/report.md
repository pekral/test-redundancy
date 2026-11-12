# Reading the report

## How to read a candidate

A candidate runs no line the other tests do not run. Coverage cannot see what a test asserts, so a candidate is not
proof that a test is useless. The `action` of each candidate says how to judge it:

| `action` | Meaning | What to do |
| --- | --- | --- |
| `delete` | The candidate adds nothing new. | Delete it when a test under `coveredBy` asserts the same input and the same result. |
| `merge-into-dataset` | It differs only in data from one test of the same file, or passes another combination of literal input values than that test. | Add a row with the values in `differsBy` to the dataset of that test (or turn it into a dataset test), then delete it. For another combination of input values, pass each argument or array that differs as a whole row value. |
| `review-dataset-row` | A row of the same dataset runs the same lines and differs from the sibling row in more than literal input values, such as an expected value. | Compare it with the sibling row. Delete it when its input is the same class; keep it for an empty, boundary, invalid or other-tenant input. |
| `review` | It asserts something the covering tests do not, differs in more than data, is a row of a named dataset another test uses, has a dataset row the analysis cannot resolve, or could not be parsed. | Judge it yourself. When in doubt, keep it. |
| `keep` | It asserts a rejection — an error status, a validation error or an exception — and no covering test asserts any rejection, or it passes another combination of input values than the covering test. | Keep it. It covers the error path, or an input of its own. |

Keep a test that asserts a different result, a different input class (empty, boundary, another tenant, invalid), an
ordering, or a rejection.

The candidates of one file can be removed together without losing a covered line. Re-run the tool after each change.

The report starts where a review saves the most time. The files are ordered by `candidateSeconds`, the time all their
candidates took in the coverage run, and the candidates of a file by their own `seconds`. A candidate without a measured
time, such as a test the `--across` analysis took from the coverage map, comes last in its file.

## The JSON report

This is the report for the Pest fixture in [`tests/Fixtures/pest`](../tests/Fixtures/pest), shortened to one candidate:

```json
{
  "files": [
    {
      "candidates": [
        {
          "action": "merge-into-dataset",
          "coveredBy": ["it adds two numbers › negative numbers"],
          "coveringDiffersBy": ["in:call:add#0/value:-1", "in:call:add#1/value:-2", "in:expect:new:Calculator/call:add/call:toBe#0/value:-3"],
          "coveringOnly": [],
          "differsBy": ["in:call:add#0/value:0", "in:call:add#1/value:5", "in:expect:new:Calculator/call:add/call:toBe#0/value:5"],
          "seconds": 0,
          "steps": [
            "Add a row with the values in differsBy to the dataset of \"it adds two numbers\".",
            "Delete this test.",
            "Run vendor/bin/test-redundancy verify; it must report \"ok\": true."
          ],
          "test": "it adds zero",
          "unionOnlyLines": []
        }
      ],
      "candidateSeconds": 0.008,
      "coveredLines": 4,
      "failedTests": [],
      "file": "tests/CalculatorTest.php",
      "passedOnRetry": [],
      "testCases": 6,
      "testsWithoutSourceLines": []
    }
  ],
  "summary": {
    "byAction": { "delete": 1, "merge-into-dataset": 1, "review-dataset-row": 1, "review": 0, "keep": 0 },
    "candidates": 3,
    "candidateSeconds": 0.008,
    "failedTests": 0,
    "passedOnRetry": 0,
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
| `differsBy` | The tokens the candidate has, or has more times, and the closest covering test lacks — see [How `differsBy` names a difference](reference.md#how-differsby-names-a-difference). `null` when the analysis cannot compute it: a dataset row of the candidate or of a covering test cannot be resolved by static analysis (a dataset built at runtime, from another file, or with a key the analysis cannot name), or the test cannot be parsed. The first step names which of the two it is, and the text report prints `unknown (a dataset row could not be resolved by static analysis)` or `unknown (the test could not be parsed)` in its place. |
| `coveringDiffersBy` | The tokens of the covering test that made the action `review` although `differsBy` holds only data or is empty — see [When a candidate is `review`, not `delete`](reference.md#when-a-candidate-is-review-not-delete). It also lists the arguments and the calls of a Pest `->throws()`, `->throwsIf()` or `->throwsUnless()` that only the covering test has, when one test covers the candidate and `unionOnlyLines` is empty — see the same section. The `review` steps name them. For a `merge-into-dataset` candidate, the tokens of the covering test in the places where `differsBy` lists the tokens of the candidate, so the report holds the values of both dataset rows. Empty for every other candidate. |
| `coveringOnly` | For a candidate with an empty `differsBy`: every token the covering tests have and the candidate lacks, assertions included, such as `in:expect:property:subscriber/property:tags/call:toBe#0/value:'vip'`. An assertion there shows that the covering test asserts more than the candidate on the same lines; the candidate is a weaker copy only when it passes the same input. Empty for every other candidate. |
| `seconds`, `candidateSeconds` | The time of the candidate in the coverage run, in seconds, and the sum over the candidates of the file and of the report (`summary.candidateSeconds`). `seconds` is `null` when the run did not measure the test, such as a test the `--across` analysis took from the coverage map. The time includes the coverage overhead, so compare it between candidates, not with a normal test run. |
| `unionOnlyLines` | The source lines of the candidate that the covering test with `differsBy: []` does not run, because only the union of the `coveredBy` tests reaches them. The action is then `review`, and the steps say so. Empty for every other candidate. |
| `steps` | What to do with the candidate, naming every covering test, or the sibling dataset row. The last step always runs `verify`. A `merge-into-dataset` candidate whose covering test is a row of a dataset test gets the step `Add a row with the values in differsBy to the dataset of "<test>".`, because that test already has a dataset; when that dataset is a named dataset (`dataset('name', …)`) that other tests of the same file also use with `->with('name')` at any position of their chain or as any argument of a `->with()`, the step names the dataset and those tests instead, because the new row is added to each of them (a name passed through a variable, a constant or a spread argument, a test the analysis does not parse, and a test inside a `describe()` block bound with `describe(…)->with('name')`, are not detected, so the step stays the plain one); a plain covering test gets `Turn "<test>" into one test with a dataset and add a row with the values in differsBy.` |
| `action` | `delete` — nothing new; `merge-into-dataset` — differs only in data from one test of the same file; `review-dataset-row` — a row of the same dataset runs the same lines and differs in more than literal input values; `review` — asserts something the covering tests do not, differs from one test of the same file in more than data, is a row of a named dataset that another test uses, has a dataset row the analysis cannot resolve, or could not be parsed; `keep` — asserts a rejection that no covering test asserts, see [Tests of an error path](#tests-of-an-error-path), or passes another combination of input values, see [Other combinations of input values](#other-combinations-of-input-values). [When a candidate is `review`, not `delete`](reference.md#when-a-candidate-is-review-not-delete) lists the rules for `delete` and `merge-into-dataset`. |
| `testsWithoutSourceLines` | Tests that run no line under `source`. They are never candidates; check what they test, or the `source` setting. |
| `failedTests` | The test cases that failed in the coverage run, each as `{"test": …, "message": …}` with the failure message. The analysis leaves them out: a failed test case is never a candidate and never a covering test, and it is not counted in `testCases`. See [Failed tests](#failed-tests). |
| `summary.failedTests` | The number of failed test cases in the report. |
| `passedOnRetry`, `summary.passedOnRetry` | The test cases that failed in the coverage run and passed in the retry of their files, and their number. They are analysed like every other test case. See [Failed tests](#failed-tests). |
| `summary.staleMap` | With `--across`: `null`, or the map commit and the source files changed since it was built. |
| `summary.seconds` | How long the analysis took. |
| `summary.skippedFiles` | The number of test files `analyse` did not run, because each declares one test case and no dataset — see [Test files with a single test case](#test-files-with-a-single-test-case). They count in `summary.testFiles` and `summary.testCases` as one test case each and have no candidate. |
| `workflow` | The rules an agent follows for the whole report. |

## Tests of an error path

A test that asserts a rejection is `keep` when no covering test asserts any rejection. The covering tests then run the
same lines on another path, such as the success path, and deleting the test would lose the only check of the error
path. A rejection is one of these calls in the test, outside a string or a constant:

- an HTTP status assertion of Laravel: `assertStatus()` with a literal from 400 to 599, `assertBadRequest()`,
  `assertUnauthorized()`, `assertForbidden()`, `assertNotFound()`, `assertUnprocessable()`, `assertServerError()` and
  the other error status assertions;
- a validation error: `assertInvalid()`, `assertJsonValidationErrors()`, `assertJsonValidationErrorFor()`,
  `assertSessionHasErrors()`, `assertSessionHasErrorsIn()`;
- an exception: Pest `toThrow()`, `->throws()`, `->throwsIf()`, `->throwsUnless()`, PHPUnit `expectException()` and
  its variants, and Laravel `assertThrows()`. A negated `->not->toThrow()` is not a rejection.

A candidate that differs from one test of the same file only in data stays `merge-into-dataset`, even when the data is
an error status: the new row keeps the case. When a covering test asserts a rejection too, even another one, the
candidate stays `review`, because the two tests can check two inputs of the same error path. A status passed as a
constant, such as `Response::HTTP_NOT_FOUND`, is not recognized: the analysis loads no class.

## Other combinations of input values

A test simulates an input, such as the values a user sends. Two tests that pass other literal input values to the same
code simulate two combinations of input values, and each is a case of its own. The analysis never offers to delete
such a test. It offers to unify the two tests into one test with a dataset, or to keep the test. A candidate passes
another combination of input values when one of these holds:

- the covering test passes an array key or an argument value that the candidate does not pass, and nothing else that
  the candidate lacks;
- every token in `differsBy` is a literal input value of the candidate, such as another value under the same key, and
  the covering test passes only literal input values in their place.

Such a candidate is:

- `merge-into-dataset` when it is not a dataset row, one test of the same file covers it, and that test differs from it
  only in literal input values. The covering test can be a plain test or a row of a dataset that no other test uses.
  The step says to turn the two tests into one test with a dataset and one row for each combination, with each
  argument or array that differs as a whole row value. When one test passes fewer arguments, each row passes its
  arguments as a list that the test spreads into the call. When the covering test is a dataset row, the candidate
  becomes a new row of that dataset.
- `keep` when it is a dataset row, because the combination has its own case already, and when more than one test or a
  test of another file covers it, because there is no one dataset to unify it with.

Only literal keys and values count. A variable, a property, a call, a closure, a cast or a spread that only the
covering test passes keeps the candidate `review`, and so does an assertion, an expected value, a call or a `new`
expression that only one of the tests has, and an argument the covering test omits. For example, the row
`'delete nameday' => [['nameday' => '03-22'], …]` is `keep` against the row
`'delete both' => [['nameday' => '03-22', 'birthday' => '1990-01-15'], …]` of the same dataset: deleting one field
alone is another input than deleting both. The test `update(['name' => 'Jana'])` is `merge-into-dataset` against the
test `update(['name' => 'Petr'])` of the same file.

## Failed tests

A failing test case does not discard the run. When a test case fails, `analyse` runs the files of the failed test cases
once more. A test case that passes in this retry is analysed like every other test case and is listed in
`passedOnRetry`, because its result depends on timing, the order of the tests or shared state. When the retry run itself
aborts, such as on a fatal error, the result of the first run stays. `analyse` leaves every test case that failed in the
retry too out of the analysis, so
it is never a candidate and never a covering test. Leaving out a covering test can only remove candidates, so the
result stays safe. The JSON report lists each of them with its failure message in `failedTests`, and the text format
prints a `Failed tests` list for the file. The coverage of every other test case is complete, and the measured durations
of every worker group that finished are stored for the next run.

`analyse` exits with `1` while a test case fails, so CI still notices it. Fix the failed tests and run `analyse` again
to include them. A process that ends without a coverage file or a JUnit log, such as a fatal error or a timeout, still
aborts the run. `map` and `verify` still abort when a test case fails.

## Test files with a single test case

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
