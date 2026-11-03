<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Cast\Object_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\UnaryMinus;
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

    public static function of(Expr $row): ?string
    {
        $values = self::values($row instanceof Array_ ? $row->items : [new ArrayItem($row)]);

        return $values === null ? null : '(' . $values . ')';
    }

    /**
     * @param array<\PhpParser\Node\ArrayItem> $items
     */
    private static function values(array $items): ?string
    {
        $names = [];

        foreach (array_values($items) as $position => $item) {
            if ($position === self::MAX_ITEMS) {
                $names[] = '…';

                break;
            }

            $name = $item->unpack ? null : self::value($item->value);

            if ($name === null) {
                return null;
            }

            $names[] = $name;
        }

        return implode(', ', $names);
    }

    private static function value(Expr $value): ?string
    {
        return match (true) {
            $value instanceof String_ => self::string($value->value),
            $value instanceof Int_, $value instanceof Float_ => var_export($value->value, return: true),
            $value instanceof UnaryMinus => self::negative($value->expr),
            $value instanceof ConstFetch => self::constant($value),
            $value instanceof Array_ => self::array($value),
            $value instanceof Object_ => self::object($value->expr),
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

    private static function array(Array_ $value): ?string
    {
        $items = self::values($value->items);

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
