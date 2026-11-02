<?php

declare(strict_types = 1);

require_once __DIR__ . '/../src/Phone.php';

use Fixture\InvalidUtf8\Phone;

it('a', function (): void {
    expect(Phone::normalize('1 2'))->toBe('12');
});
it('b', function (): void {
    expect(Phone::normalize('1 2'))->toBe('12');
});
