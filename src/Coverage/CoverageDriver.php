<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

use RuntimeException;
use Symfony\Component\Filesystem\Path;

final class CoverageDriver
{

    /**
     * @param list<string> $sourcePaths
     * @return list<string>
     */
    public static function iniArguments(string $projectRoot, array $sourcePaths): array
    {
        return self::argumentsFor(extension_loaded('pcov'), extension_loaded('xdebug'), $projectRoot, $sourcePaths);
    }

    /**
     * @return array<string, string>
     */
    public static function environment(): array
    {
        return extension_loaded('pcov') ? [] : ['XDEBUG_MODE' => 'coverage'];
    }

    /**
     * @param list<string> $sourcePaths
     * @return list<string>
     */
    public static function argumentsFor(bool $hasPcov, bool $hasXdebug, string $projectRoot, array $sourcePaths): array
    {
        return match (true) {
            $hasPcov => self::pcovArguments($projectRoot, $sourcePaths),
            $hasXdebug => ['-d', 'xdebug.mode=coverage'],
            default => throw new RuntimeException('Per-test coverage needs the pcov or the xdebug extension.'),
        };
    }

    /**
     * pcov instruments every file under its directory, so the directory is the narrowest one that holds all source
     * directories; the exclusion keeps out what lies under it but outside the source.
     *
     * @param list<string> $sourcePaths
     * @return list<string>
     */
    private static function pcovArguments(string $projectRoot, array $sourcePaths): array
    {
        $directories = self::sourceDirectories($projectRoot, $sourcePaths);
        $parent = $directories === [] ? null : Path::getLongestCommonBasePath(...$directories);
        $exclude = '/vendor/';

        if ($parent !== null && count($directories) > 1) {
            $subdirectories = array_map(
                static fn (string $directory): string => preg_quote(Path::makeRelative($directory, $parent), '~'),
                $directories,
            );
            $exclude .= '|^' . preg_quote(rtrim($parent, '/'), '~') . '/(?!(?:' . implode('|', $subdirectories) . ')/)';
        }

        return ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . ($parent ?? $projectRoot), '-d', 'pcov.exclude=~' . $exclude . '~'];
    }

    /**
     * @param list<string> $sourcePaths
     * @return list<string>
     */
    private static function sourceDirectories(string $projectRoot, array $sourcePaths): array
    {
        $directories = array_unique(array_filter(
            array_map(static fn (string $sourcePath): string => Path::join($projectRoot, $sourcePath), $sourcePaths),
            is_dir(...),
        ));

        return array_values(array_filter(
            $directories,
            static fn (string $directory): bool => array_filter(
                $directories,
                static fn (string $other): bool => $other !== $directory && Path::isBasePath($other, $directory),
            ) === [],
        ));
    }

}
