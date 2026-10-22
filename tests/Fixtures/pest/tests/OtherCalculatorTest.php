<?php

declare(strict_types = 1);

require_once __DIR__ . '/../src/Calculator.php';

use Fixture\Pest\Calculator;

test('divide returns a float', function (): void {
    expect((new Calculator())->divide(6, 3))->toBe(2.0);
});
