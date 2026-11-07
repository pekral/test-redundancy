<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;

final class PhpUnitRowNames
{

    /**
     * @param list<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_|null> $rows
     * @return list<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_|null>
     */
    public static function named(array $rows): array
    {
        $position = 0;
        $named = [];

        foreach ($rows as $row) {
            $key = $row?->key;

            if ($key instanceof String_ && !self::isIntegerString($key->value)) {
                $named[] = $row;

                continue;
            }

            if ($row === null || ($key instanceof Expr && !$key instanceof Int_ && !$key instanceof String_)) {
                return [...$named, ...array_fill(0, count($rows) - count($named), value: null)];
            }

            $positional = clone $row;
            $positional->key = new String_((string) $position);
            $named[] = $positional;
            $position += 1;
        }

        return $named;
    }

    private static function isIntegerString(string $value): bool
    {
        return preg_match('/^(0|-?[1-9]\d*)$/', $value) === 1;
    }

}
