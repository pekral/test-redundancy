<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use Pekral\TestRedundancy\Support\TestKey;
use PhpParser\Node\Scalar\String_;

final class DatasetRows
{

    /**
     * @param array<string, true> $body
     * @param array<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_|null> $rows
     * @return array<string, array<string, true>>
     */
    public static function fingerprints(string $testCase, array $body, array $rows): array
    {
        if ($rows === []) {
            return [$testCase => $body];
        }

        $fingerprints = [];

        foreach ($rows as $row) {
            if ($row === null || !$row->key instanceof String_ || $row->value === null) {
                continue;
            }

            $fingerprints[$testCase . TestKey::DATASET_SEPARATOR . $row->key->value] = $body + TokenCollector::collect($row->value);
        }

        return $fingerprints;
    }

}
