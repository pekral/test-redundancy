<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

enum Action: string
{

    case Delete = 'delete';
    case MergeIntoDataset = 'merge-into-dataset';
    case ReviewDatasetRow = 'review-dataset-row';
    case Review = 'review';

    public const string VERIFY_STEP = 'Run vendor/bin/test-redundancy verify; it must report "ok": true.';

    public const string CONFIRM_STEP = 'Confirm that %s together assert the same input and result.';

    public const string SHARED_DATASET_STEP = 'This row belongs to the named dataset "%s", which these tests also use: %s. '
        . 'Deleting the row deletes it from each of them. '
        . 'Delete the row only when each of them stays covered without it; otherwise keep it, or give this test its own dataset.';

    public const string UNION_ONLY_LINES_STEP = 'No single covering test runs every line of this test: '
        . 'the lines in unionOnlyLines run only in the union of %s. '
        . 'Check whether a covering test that runs those lines asserts what this test asserts about them; '
        . 'delete this test only when one does, otherwise keep it.';

    public const string MISSING_KEY_STEP = 'The covering test %s sets the array keys in coveringDiffersBy (%s), and this test does not: '
        . 'without such a key, the input has another shape. '
        . 'Check whether the code under test reads one of these keys, '
        . 'for example in an isset() check, a required rule or a short-circuit condition; '
        . 'keep this test when it does, and delete it only when the code never reads them.';

    public const string MISSING_ARGUMENT_STEP = 'The covering test %s passes the values in coveringDiffersBy (%s) '
        . 'to a call that is not an assertion, and this test does not: without such a value, the code under test gets another input. '
        . 'Check whether the code under test behaves differently without one of these values, '
        . 'for example when it adds a missing value itself or falls back to a default; '
        . 'keep this test when it does, and delete it only when it does not.';

    public const string MISSING_EXPECTATION_STEP = 'The covering test %s expects the elements, keys or arguments '
        . 'in coveringDiffersBy (%s), and this test does not: its expected value is smaller. '
        . 'A row with the values in differsBy would still expect them, so the case of this test would be lost. '
        . 'To merge this test, make its whole expected value a row value of its own, so that the row expects none of them; '
        . 'otherwise keep this test.';

    public const string NO_ASSERTION_STEP = 'This test has no assertion, '
        . 'so it asserts only that the code under test throws no exception for its input. '
        . 'The covering test %s arranges the calls and values in coveringDiffersBy (%s), and this test does not: '
        . 'without them, the code under test gets an empty or missing input. '
        . 'Check whether that empty or missing input is a case of its own, for example a record that does not exist; '
        . 'keep this test when it is, and delete it only when it is not.';

    private const string REVIEW_DIFFERS_BY_STEP = 'Check whether differsBy holds an assertion the covering tests lack: '
        . 'another result, input class, ordering or rejection.';

    /**
     * @param list<string> $coveringDiffersBy
     * @return list<string>
     */
    public function steps(string $coveringTests, array $coveringDiffersBy): array
    {
        return [
            ...match ($this) {
                self::Delete => [
                    sprintf(self::CONFIRM_STEP, $coveringTests),
                    'Delete this test.',
                ],
                self::MergeIntoDataset => [
                    sprintf('Turn %s into one test with a dataset and add a row with the values in differsBy.', $coveringTests),
                    'Delete this test.',
                ],
                self::ReviewDatasetRow => [
                    sprintf('Compare this row with %s.', $coveringTests),
                    'Delete the row when its input is the same class; keep it for an empty, boundary, invalid or other-tenant input.',
                ],
                self::Review => $coveringDiffersBy === [] ? [
                    self::REVIEW_DIFFERS_BY_STEP,
                    'Delete this test only when it does not; otherwise keep it.',
                ] : [
                    self::REVIEW_DIFFERS_BY_STEP,
                    sprintf(
                        'Check whether coveringDiffersBy (%s), which the covering test has and this test lacks, '
                            . 'changes the result the covering test asserts.',
                        implode(', ', $coveringDiffersBy),
                    ),
                    'Delete this test only when neither check finds a difference; otherwise keep it.',
                ],
            },
            self::VERIFY_STEP,
        ];
    }

}
