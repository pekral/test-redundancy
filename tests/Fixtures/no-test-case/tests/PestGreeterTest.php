<?php

declare(strict_types = 1);

require_once __DIR__ . '/../src/Greeter.php';

use Fixture\NoTestCase\Greeter;

it('greets a stranger', function (): void {
    expect(new Greeter()->greet(''))->toBe('Hello, stranger!');
});

it('greets Jane', function (): void {
    expect(new Greeter()->greet('Jane'))->toBe('Hello, Jane!');
});
