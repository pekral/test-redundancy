<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use Pekral\TestRedundancy\Support\TestKey;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;

final class DatasetRows
{

    /**
     * @param array<\PhpParser\Node> $body
     * @param array<\PhpParser\Node\Param> $parameters
     * @param array<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_|null> $rows
     * @return array<string, array<string, int>>
     */
    public static function fingerprints(string $testCase, array $body, array $parameters, array $rows): array
    {
        if ($rows === []) {
            return [$testCase => TokenCollector::collect($body)];
        }

        $fingerprints = [];

        foreach ($rows as $row) {
            if ($row === null || !$row->key instanceof String_ || $row->value === null) {
                continue;
            }

            $fingerprints[$testCase . TestKey::DATASET_SEPARATOR . $row->key->value] = self::rowTokens($body, $parameters, $row->value);
        }

        return $fingerprints;
    }

    /**
     * @param array<\PhpParser\Node> $body
     * @param array<\PhpParser\Node\Param> $parameters
     * @return array<string, int>
     */
    private static function rowTokens(array $body, array $parameters, Expr $row): array
    {
        $items = $row instanceof Array_ ? array_values($row->items) : [new ArrayItem($row)];
        $arguments = [];

        foreach ($items as $position => $item) {
            $name = self::parameterName($item, $parameters[$position] ?? null);

            if ($name !== null) {
                $arguments[$name] = $item;
            }
        }

        $binder = new ParameterBinder(array_map(static fn (ArrayItem $item): Expr => $item->value, $arguments));
        $boundBody = $binder->bind($body);
        $boundItems = array_intersect_key($arguments, $binder->boundNames());

        return TokenCollector::sum(
            TokenCollector::collect($boundBody),
            TokenCollector::collect(array_filter($items, static fn (ArrayItem $item): bool => !in_array($item, $boundItems, strict: true))),
        );
    }

    private static function parameterName(ArrayItem $item, ?Param $parameter): ?string
    {
        $variable = $item->key !== null || $item->unpack || $parameter === null || $parameter->variadic ? null : $parameter->var;

        return $variable instanceof Variable && is_string($variable->name) ? $variable->name : null;
    }

}
