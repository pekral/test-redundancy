<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;

final class FunctionToken
{

    private const string KEY_SEPARATOR_PATTERN = '~[/#:]~';

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
            $callable instanceof ArrayDimFetch => self::elementName($callable),
            default => null,
        };
    }

    private static function elementName(ArrayDimFetch $element): ?string
    {
        $receiver = self::callableName($element->var);
        $key = $element->dim;

        return $receiver === null || !($key instanceof Int_ || $key instanceof String_)
            || preg_match(self::KEY_SEPARATOR_PATTERN, (string) $key->value) === 1
            ? null
            : $receiver . '[' . var_export($key->value, return: true) . ']';
    }

    private static function propertyName(PropertyFetch|NullsafePropertyFetch $property): ?string
    {
        $receiver = self::callableName($property->var);

        return $receiver === null || !$property->name instanceof Identifier
            ? null
            : $receiver . ($property instanceof NullsafePropertyFetch ? '?->' : '->') . $property->name->toString();
    }

}
