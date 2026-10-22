<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Console;

use JsonSerializable;

final class Json
{

    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * @throws \JsonException
     */
    public static function encode(JsonSerializable $value, bool $pretty): string
    {
        return json_encode($value, $pretty ? self::FLAGS | JSON_PRETTY_PRINT : self::FLAGS);
    }

}
