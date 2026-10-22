<?php

declare(strict_types = 1);

namespace Fixture\Pest;

final class Calculator
{

    public function add(int $first, int $second): int
    {
        return $first + $second;
    }

    public function divide(int $dividend, int $divisor): float
    {
        if ($divisor === 0) {
            throw new \InvalidArgumentException('Division by zero.');
        }

        return $dividend / $divisor;
    }

}
