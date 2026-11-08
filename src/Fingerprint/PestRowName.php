<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Cast\Object_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;

final class PestRowName
{

    public const string CONTROL_CHARACTERS = '/[\x00-\x1f]/';

    private const int MAX_ITEMS = 3;

    private const int MAX_STRING_LENGTH = 40;

    private const array PEST_PATTERNS = ['#\.{3}#', '#\\\n\s*#', '# Object \(…\)#'];

    private const array PEST_REPLACEMENTS = ['…', '', ''];

    public static function of(Expr $row, ?ClassDeclarations $declarations = null): ?string
    {
        $values = self::values($row instanceof Array_ ? $row->items : [new ArrayItem($row)], $declarations);

        return $values === null ? null : '(' . $values . ')';
    }

    /**
     * @param array<\PhpParser\Node\ArrayItem> $items
     */
    private static function values(array $items, ?ClassDeclarations $declarations): ?string
    {
        $names = [];

        foreach (array_values($items) as $position => $item) {
            if ($position === self::MAX_ITEMS) {
                $names[] = '…';

                break;
            }

            $name = $item->unpack ? null : self::value($item->value, $declarations);

            if ($name === null) {
                return null;
            }

            $names[] = $name;
        }

        return implode(', ', $names);
    }

    private static function value(Expr $value, ?ClassDeclarations $declarations): ?string
    {
        return match (true) {
            $value instanceof ClassConstFetch => self::classConstant($value, $declarations),
            $value instanceof PropertyFetch && $value->var instanceof ClassConstFetch && $value->name instanceof Identifier
                && $value->name->toString() === 'value' => self::scalar($declarations?->enumCase($value->var)['value'] ?? null),
            $value instanceof String_ => self::string($value->value),
            $value instanceof Int_, $value instanceof Float_ => var_export($value->value, return: true),
            $value instanceof UnaryMinus => self::negative($value->expr),
            $value instanceof ConstFetch => self::constant($value),
            $value instanceof Array_ => self::array($value, $declarations),
            $value instanceof Object_ => self::object($value->expr),
            default => null,
        };
    }

    private static function classConstant(ClassConstFetch $fetch, ?ClassDeclarations $declarations): ?string
    {
        if ($fetch->name instanceof Identifier && $fetch->name->toLowerString() === 'class') {
            return self::className($fetch, $declarations);
        }

        $case = $declarations?->enumCase($fetch);

        if ($case === null) {
            return self::scalar($declarations?->constant($fetch));
        }

        $value = $case['value'] === null ? '' : self::scalar($case['value']);
        $class = ClassDeclarations::className($fetch);

        return $value === null || $class === null || !$fetch->name instanceof Identifier
            ? null
            : $class . ' Enum (' . $fetch->name->toString() . ($value === '' ? '' : ', ' . $value) . ')';
    }

    private static function className(ClassConstFetch $fetch, ?ClassDeclarations $declarations): ?string
    {
        $class = $declarations instanceof ClassDeclarations ? ClassDeclarations::className($fetch) : null;

        return $class === null ? null : self::string($class);
    }

    private static function scalar(null|int|string $value): ?string
    {
        return match (true) {
            is_int($value) => (string) $value,
            is_string($value) => self::string($value),
            default => null,
        };
    }

    private static function negative(Expr $number): ?string
    {
        return $number instanceof Int_ || $number instanceof Float_ ? '-' . var_export($number->value, return: true) : null;
    }

    private static function constant(ConstFetch $constant): ?string
    {
        $name = $constant->name->toLowerString();

        return in_array($name, ['true', 'false', 'null'], strict: true) ? $name : null;
    }

    private static function string(string $value): ?string
    {
        if (preg_match(self::CONTROL_CHARACTERS, $value) === 1) {
            return null;
        }

        $exported = '\'' . $value . '\'';

        if (mb_strlen($exported) > self::MAX_STRING_LENGTH) {
            $exported = mb_substr($exported, 0, self::MAX_STRING_LENGTH - 10) . '...' . mb_substr($exported, -7);
        }

        return (string) preg_replace(self::PEST_PATTERNS, self::PEST_REPLACEMENTS, $exported);
    }

    private static function array(Array_ $value, ?ClassDeclarations $declarations): ?string
    {
        $items = self::values($value->items, $declarations);

        return $items === null ? null : '[' . $items . ']';
    }

    private static function object(Expr $properties): ?string
    {
        return $properties instanceof Array_
            && $properties->items !== []
            && !array_any($properties->items, static fn (ArrayItem $item): bool => $item->unpack)
            ? 'stdClass'
            : null;
    }

}
