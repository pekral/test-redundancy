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
