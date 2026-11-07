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
     * @param callable(\PhpParser\Node\Expr): ?string $nameByValue
     * @return list<\PhpParser\Node\ArrayItem|null>
     */
    public static function rows(array $datasets, callable $nameByValue): array
    {
        $combinations = [[[], []]];

        foreach ($datasets as $rows) {
            $next = [];
            $rowLabels = self::uniqueLabels($rows, $nameByValue);

            foreach ($combinations as [$labels, $items]) {
                foreach (array_values($rows) as $position => $row) {
                    $next[] = [[...$labels, $rowLabels[$position]], [...$items, ...self::items($row)]];
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

    /**
     * @param array<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_|null> $rows
     * @param callable(\PhpParser\Node\Expr): ?string $nameByValue
     * @return list<string|null>
     */
    private static function uniqueLabels(array $rows, callable $nameByValue): array
    {
        $labels = array_map(static fn (null|ArrayItem|Yield_ $row): ?string => self::label($row, $nameByValue), array_values($rows));
        $counts = array_count_values(array_filter($labels, is_string(...)));

        return array_map(static fn (?string $label): ?string => $label !== null && $counts[$label] === 1 ? $label : null, $labels);
    }

    /**
     * @param callable(\PhpParser\Node\Expr): ?string $nameByValue
     */
    private static function label(null|ArrayItem|Yield_ $row, callable $nameByValue): ?string
    {
        return match (true) {
            !$row?->value instanceof Expr => null,
            $row->key instanceof String_ => 'dataset "' . $row->key->value . '"',
            !$row->key instanceof Expr => $nameByValue($row->value),
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
