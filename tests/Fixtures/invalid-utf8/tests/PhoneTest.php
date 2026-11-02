<?php

declare(strict_types = 1);

require_once __DIR__ . '/../src/Phone.php';

use Fixture\InvalidUtf8\Phone;

it('normalizes a phone number', function (string $input, string $expected): void {
    expect(Phone::normalize($input))->toBe($expected);
})->with([
    ['733 382 412', '733382412'],
    ["733\xA0382412", '733382412'],
]);

it('keeps a phone number without spaces', function (): void {
    expect(Phone::normalize('733382412'))->toBe('733382412');
});
