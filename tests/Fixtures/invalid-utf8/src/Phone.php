<?php

declare(strict_types = 1);

namespace Fixture\InvalidUtf8;

final class Phone
{

    public static function normalize(string $input): string
    {
        return str_replace(["\xA0", ' '], '', $input);
    }

}
