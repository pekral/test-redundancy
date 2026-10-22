<?php

declare(strict_types = 1);

require_once __DIR__ . '/../src/Calculator.php';

use Fixture\Pest\Calculator;

it('adds two numbers', function (int $first, int $second, int $sum): void {
    expect((new Calculator())->add($first, $second))->toBe($sum);
})->with([
    'small numbers' => [1, 2, 3],
    'negative numbers' => [-1, -2, -3],
]);

it('adds one and two', function (): void {
    expect((new Calculator())->add(1, 2))->toBe(3);
});

it('divides two numbers', function (): void {
    expect((new Calculator())->divide(6, 3))->toBe(2.0);
});

it('rejects a division by zero', function (): void {
    (new Calculator())->divide(1, 0);
})->throws(InvalidArgumentException::class, 'Division by zero.');

it('adds zero', function (): void {
    expect((new Calculator())->add(0, 5))->toBe(5);
});
