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

final class FunctionToken
{

    private const array KEY_ESCAPES = ['%' => '%25', '/' => '%2F', '#' => '%23', ':' => '%3A'];

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
        $key = $element->dim !== null && TokenCollector::isData($element->dim) ? TokenCollector::token($element->dim) : null;

        return $receiver === null || $key === null
            ? null
            : $receiver . '[' . strtr((string) preg_replace(TokenCollector::DATA_TOKEN_PATTERN, '', $key), self::KEY_ESCAPES) . ']';
    }

    private static function propertyName(PropertyFetch|NullsafePropertyFetch $property): ?string
    {
        $receiver = self::callableName($property->var);

        return $receiver === null || !$property->name instanceof Identifier
            ? null
            : $receiver . ($property instanceof NullsafePropertyFetch ? '?->' : '->') . $property->name->toString();
    }

}
