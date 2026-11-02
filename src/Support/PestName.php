<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Support;

final class PestName
{

    public const string EVALUABLE_PREFIX = '__pest_evaluable_';

    public static function evaluable(string $description): string
    {
        return self::identifier(self::EVALUABLE_PREFIX . str_replace(' ', '_', str_replace('_', '__', $description)));
    }

    public static function identifier(string $code): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_\x80-\xff]/', '_', $code);
    }

    public static function isEvaluable(string $method): bool
    {
        return str_starts_with($method, self::EVALUABLE_PREFIX);
    }

}
