<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Support;

use Symfony\Component\Finder\Finder;

final class TestFiles
{

    private const string PATTERN = '*Test.php';

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    public static function expand(array $paths, string $projectRoot): array
    {
        $testFiles = [];

        foreach ($paths as $path) {
            $relativePath = ProjectPath::relative($path, $projectRoot);
            $absolutePath = $projectRoot . DIRECTORY_SEPARATOR . $relativePath;

            if (!is_dir($absolutePath)) {
                $testFiles[] = $relativePath;

                continue;
            }

            foreach (Finder::create()->files()->in($absolutePath)->name(self::PATTERN)->sortByName() as $file) {
                $testFiles[] = ProjectPath::relative($file->getPathname(), $projectRoot);
            }
        }

        return array_values(array_unique($testFiles));
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    public static function onlyTests(array $paths): array
    {
        return array_values(array_filter($paths, static fn (string $path): bool => str_ends_with($path, 'Test.php')));
    }

    /**
     * The files beside the test files that a test may depend on: Pest datasets and configuration, base test cases,
     * traits and fixtures. They sit in the top-level directory of a test file.
     *
     * @param list<string> $testFiles
     * @return list<string>
     */
    public static function supportFiles(array $testFiles, string $projectRoot): array
    {
        $directories = array_unique(array_map(static fn (string $testFile): string => (string) strtok($testFile, '/'), $testFiles));
        $supportFiles = [];

        foreach ($directories as $directory) {
            $absolutePath = $projectRoot . DIRECTORY_SEPARATOR . $directory;

            if ($directory === '..' || !is_dir($absolutePath)) {
                continue;
            }

            foreach (Finder::create()->files()->in($absolutePath)->notName(self::PATTERN)->sortByName() as $file) {
                $supportFiles[] = ProjectPath::relative($file->getPathname(), $projectRoot);
            }
        }

        return array_values(array_diff($supportFiles, $testFiles));
    }

    /**
     * @param list<string> $paths
     * @return list<list<string>>
     */
    public static function chunks(array $paths, string $projectRoot, int $maxFilesPerChunk): array
    {
        $chunks = [];
        $looseFiles = [];

        foreach ($paths as $path) {
            $relativePath = ProjectPath::relative($path, $projectRoot);
            $absolutePath = $projectRoot . DIRECTORY_SEPARATOR . $relativePath;

            if (!is_dir($absolutePath)) {
                $looseFiles[] = $relativePath;

                continue;
            }

            $chunks = [...$chunks, ...self::directoryChunks($relativePath, $projectRoot, $maxFilesPerChunk)];
        }

        return $looseFiles === [] ? $chunks : [...$chunks, $looseFiles];
    }

    /**
     * @return list<list<string>>
     */
    private static function directoryChunks(string $relativePath, string $projectRoot, int $maxFilesPerChunk): array
    {
        $absolutePath = $projectRoot . DIRECTORY_SEPARATOR . $relativePath;

        if (Finder::create()->files()->in($absolutePath)->name(self::PATTERN)->count() <= $maxFilesPerChunk) {
            return [[$relativePath]];
        }

        $directories = self::namesIn(Finder::create()->directories()->in($absolutePath)->depth(0), $relativePath);
        $files = self::namesIn(Finder::create()->files()->in($absolutePath)->depth(0)->name(self::PATTERN), $relativePath);

        return [...self::chunks($directories, $projectRoot, $maxFilesPerChunk), ...($files === [] ? [] : [$files])];
    }

    /**
     * @return list<string>
     */
    private static function namesIn(Finder $finder, string $relativePath): array
    {
        $names = [];

        foreach ($finder->sortByName() as $entry) {
            $names[] = $relativePath . '/' . $entry->getFilename();
        }

        return $names;
    }

}
