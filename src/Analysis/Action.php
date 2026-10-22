<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

enum Action: string
{

    case Delete = 'delete';
    case MergeIntoDataset = 'merge-into-dataset';
    case ReviewDatasetRow = 'review-dataset-row';
    case Review = 'review';

    public function instruction(): string
    {
        return match ($this) {
            self::Delete => 'Runs only lines other tests run and asserts no value or call they lack. '
                . 'Check how inputs pair with results, then delete it.',
            self::MergeIntoDataset => 'Differs from the covering test only in data. Merge both into one test with a dataset row per case.',
            self::ReviewDatasetRow => 'Another row of the same dataset runs the same lines. '
                . 'Delete the row unless its input is a different class (empty, boundary, another tenant, invalid).',
            self::Review => 'Runs only lines other tests run, but asserts values or calls they do not. '
                . 'Keep it unless those assertions add nothing.',
        };
    }

}
