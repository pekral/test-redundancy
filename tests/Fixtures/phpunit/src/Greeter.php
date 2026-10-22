<?php

declare(strict_types = 1);

namespace Fixture\PhpUnit;

final class Greeter
{

    public function greet(string $name): string
    {
        if ($name === '') {
            return 'Hello, stranger!';
        }

        return sprintf('Hello, %s!', $name);
    }

}
