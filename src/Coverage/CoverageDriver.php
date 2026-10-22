<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

use RuntimeException;

final class CoverageDriver
{

    /**
     * @return list<string>
     */
    public static function iniArguments(string $projectRoot): array
    {
        return self::argumentsFor(extension_loaded('pcov'), extension_loaded('xdebug'), $projectRoot);
    }

    /**
     * @return array<string, string>
     */
    public static function environment(): array
    {
        return extension_loaded('pcov') ? [] : ['XDEBUG_MODE' => 'coverage'];
    }

    /**
     * @return list<string>
     */
    public static function argumentsFor(bool $hasPcov, bool $hasXdebug, string $projectRoot): array
    {
        return match (true) {
            $hasPcov => ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $projectRoot, '-d', 'pcov.exclude=~/vendor/~'],
            $hasXdebug => ['-d', 'xdebug.mode=coverage'],
            default => throw new RuntimeException('Per-test coverage needs the pcov or the xdebug extension.'),
        };
    }

}
