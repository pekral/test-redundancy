<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

use JsonException;
use RuntimeException;

final readonly class CoverageBaseline
{

    /**
     * @param list<string> $testFiles
     * @param list<string> $lines
     * @param array<string, array{hash: string, lines: list<string>}> $files
     */
    public function __construct(public array $testFiles, public array $lines, public bool $across, public array $files)
    {
    }

    /**
     * @param array<string, array<string, array<string, true>>> $testsByFile
     */
    public static function fromTestsByFile(array $testsByFile, bool $across, string $projectRoot): self
    {
        $lines = [];
        $files = [];

        foreach ($testsByFile as $testFile => $tests) {
            $fileLines = [];

            foreach ($tests as $testLines) {
                $fileLines += $testLines;
            }

            $lines += $fileLines;
            $files[$testFile] = ['hash' => self::hashOf($projectRoot, $testFile), 'lines' => array_keys($fileLines)];
        }

        return new self(array_keys($testsByFile), array_keys($lines), $across, $files);
    }

    /**
     * @throws \JsonException
     */
    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('No baseline at %s. Run "test-redundancy analyse" first.', $path));
        }

        $baseline = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($baseline) || !is_array($baseline['testFiles'] ?? null) || !is_array($baseline['lines'] ?? null)) {
            throw new JsonException(sprintf('%s is not a coverage baseline.', $path));
        }

        /** @var list<string> $testFiles */
        $testFiles = $baseline['testFiles'];
        /** @var list<string> $lines */
        $lines = $baseline['lines'];

        /** @var array<string, array{hash: string, lines: list<string>}> $files */
        $files = is_array($baseline['files'] ?? null) ? $baseline['files'] : [];

        return new self($testFiles, $lines, ($baseline['across'] ?? false) === true, $files);
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    public function unstableAmong(array $lines, string $projectRoot): array
    {
        $unchanged = [];
        $changed = [];

        foreach ($this->files as $testFile => $file) {
            if (self::hashOf($projectRoot, $testFile) === $file['hash']) {
                $unchanged += array_flip($file['lines']);

                continue;
            }

            $changed += array_flip($file['lines']);
        }

        return array_values(array_filter($lines, static fn (string $line): bool => isset($unchanged[$line]) && !isset($changed[$line])));
    }

    /**
     * @throws \JsonException
     */
    public function save(string $path): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), recursive: true);
        }

        $baseline = ['across' => $this->across, 'files' => $this->files, 'lines' => $this->lines, 'testFiles' => $this->testFiles];
        file_put_contents($path, json_encode($baseline, JSON_THROW_ON_ERROR));
    }

    private static function hashOf(string $projectRoot, string $testFile): string
    {
        $path = $projectRoot . DIRECTORY_SEPARATOR . $testFile;

        return is_file($path) ? hash('sha256', (string) file_get_contents($path)) : '';
    }

}
