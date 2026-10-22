<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Coverage\CoverageDriver;

it('enables the available coverage driver', function (bool $hasPcov, bool $hasXdebug, array $arguments): void {
    expect(CoverageDriver::argumentsFor($hasPcov, $hasXdebug))->toBe($arguments);
})->with([
    'pcov' => [true, true, ['-d', 'pcov.enabled=1']],
    'xdebug' => [false, true, ['-d', 'xdebug.mode=coverage']],
]);

it('fails without a coverage driver', function (): void {
    CoverageDriver::argumentsFor(hasPcov: false, hasXdebug: false);
})->throws(RuntimeException::class, 'pcov or the xdebug extension');

it('passes the driver of the running PHP', function (): void {
    expect(CoverageDriver::iniArguments())->toBe(['-d', 'pcov.enabled=1'])
        ->and(CoverageDriver::environment())->toBe([]);
});
