<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

enum Action: string
{

    case Delete = 'delete';
    case MergeIntoDataset = 'merge-into-dataset';
    case ReviewDatasetRow = 'review-dataset-row';
    case Review = 'review';

    public const VERIFY_STEP = 'Run vendor/bin/test-redundancy verify; it must report "ok": true.';

    /**
     * @return list<string>
     */
    public function steps(string $coveringTests): array
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
                self::Review => [
                    'Check whether differsBy holds an assertion the covering tests lack: '
                        . 'another result, input class, ordering or rejection.',
                    'Delete this test only when it does not; otherwise keep it.',
                ],
            },
            self::VERIFY_STEP,
        ];
    }

}
