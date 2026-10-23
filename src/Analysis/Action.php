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
                    sprintf('Confirm that %s together assert the same input and result.', $coveringTests),
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
