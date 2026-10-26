<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

final class FunctionToken
{

    public static function of(FuncCall $call): ?string
    {
        $name = $call->name instanceof Name ? $call->name->toString() : self::callableName($call->name);

        return $name === null ? null : 'function:' . $name;
    }

    private static function callableName(Expr $callable): ?string
    {
        return match (true) {
            $callable instanceof Variable => is_string($callable->name) ? '$' . $callable->name : null,
            $callable instanceof PropertyFetch, $callable instanceof NullsafePropertyFetch => self::propertyName($callable),
            $callable instanceof StaticPropertyFetch => $callable->class instanceof Name && $callable->name instanceof Identifier
                ? $callable->class->toString() . '::$' . $callable->name->toString()
                : null,
            default => null,
        };
    }

    private static function propertyName(PropertyFetch|NullsafePropertyFetch $property): ?string
    {
        $receiver = self::callableName($property->var);

        return $receiver === null || !$property->name instanceof Identifier
            ? null
            : $receiver . ($property instanceof NullsafePropertyFetch ? '?->' : '->') . $property->name->toString();
    }

}
