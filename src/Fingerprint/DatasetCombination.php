<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use Pekral\TestRedundancy\Support\TestKey;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Scalar\String_;

final class DatasetCombination
{

    private const string SEPARATOR = ' / ';

    /**
     * @param list<array<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_|null>> $datasets
     * @return list<\PhpParser\Node\ArrayItem|null>
     */
    public static function rows(array $datasets): array
    {
        $combinations = [[[], []]];

        foreach ($datasets as $rows) {
            $next = [];

            foreach ($combinations as [$labels, $items]) {
                foreach ($rows as $row) {
                    $next[] = [[...$labels, self::label($row)], [...$items, ...self::items($row)]];
                }
            }

            $combinations = $next;
        }

        return array_map(self::row(...), $combinations);
    }

    /**
     * @param array{0: list<string|null>, 1: list<\PhpParser\Node\ArrayItem>} $combination
     */
    private static function row(array $combination): ?ArrayItem
    {
        [$labels, $items] = $combination;

        return in_array(needle: null, haystack: $labels, strict: true)
            ? null
            : new ArrayItem(new Array_($items), new String_(TestKey::datasetRowName(implode(self::SEPARATOR, $labels))));
    }

    private static function label(null|ArrayItem|Yield_ $row): ?string
    {
        return match (true) {
            !$row?->value instanceof Expr => null,
            $row->key instanceof String_ => 'dataset "' . $row->key->value . '"',
            !$row->key instanceof Expr => PestRowName::of($row->value),
            default => null,
        };
    }

    /**
     * @return list<\PhpParser\Node\ArrayItem>
     */
    private static function items(null|ArrayItem|Yield_ $row): array
    {
        $value = $row?->value;

        return match (true) {
            $value instanceof Array_ => array_values($value->items),
            $value instanceof Expr => [new ArrayItem($value)],
            default => [],
        };
    }

}
