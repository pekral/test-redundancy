<?php

declare(strict_types = 1);

require_once __DIR__ . '/../src/Phone.php';

use Fixture\InvalidUtf8\Phone;

it("normalizes 733\xA0382412", function (): void {
    expect(Phone::normalize("733\xA0382412"))->toBe('733382412');
});

it('keeps an empty phone number', function (): void {
    expect(Phone::normalize(''))->toBe('');
});
