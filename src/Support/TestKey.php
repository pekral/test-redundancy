<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Support;

final class TestKey
{

    public const string DATASET_SEPARATOR = ' › ';

    private const string FILE_SEPARATOR = ' :: ';

    public static function make(string $testFile, string $testCase): string
    {
        return $testFile . self::FILE_SEPARATOR . $testCase;
    }

    public static function file(string $testKey): string
    {
        return explode(self::FILE_SEPARATOR, $testKey, 2)[0];
    }

    public static function testCase(string $testKey): string
    {
        return explode(self::FILE_SEPARATOR, $testKey, 2)[1] ?? '';
    }

    public static function isDatasetRow(string $testKey): bool
    {
        return str_contains(self::testCase($testKey), self::DATASET_SEPARATOR);
    }

}
