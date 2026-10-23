<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

use JsonException;
use Pekral\TestRedundancy\Support\TestFiles;
use RuntimeException;

final readonly class CoverageBaseline
{

    /**
     * @param list<string> $testFiles
     * @param list<string> $lines
     * @param array<string, array{hash: string, lines: list<string>}> $files
     * @param array<string, string> $hashesBySourceFilePath
     * @param string $supportHash a digest of the test support files; empty in a baseline written before it existed
     */
    public function __construct(
        public array $testFiles,
        public array $lines,
        public bool $across,
        public array $files,
        public array $hashesBySourceFilePath,
        public string $supportHash,
    ) {
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

        $sources = [];

        foreach (array_keys($lines) as $line) {
            $source = self::sourceOf($line);
            $sources[$source] ??= self::hashOf($projectRoot, $source);
        }

        $testFiles = array_keys($testsByFile);

        return new self($testFiles, array_keys($lines), $across, $files, $sources, self::supportHashOf($projectRoot, $testFiles));
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

        /** @var array<string, string> $sources */
        $sources = is_array($baseline['sources'] ?? null) ? $baseline['sources'] : [];

        $support = is_string($baseline['support'] ?? null) ? $baseline['support'] : '';

        return new self($testFiles, $lines, ($baseline['across'] ?? false) === true, $files, $sources, $support);
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    public function unstableAmong(array $lines, string $projectRoot): array
    {
        if ($this->supportHashDiffers($projectRoot)) {
            return [];
        }

        $unchanged = [];
        $changed = [];

        foreach ($this->files as $testFile => $file) {
            if (self::hashOf($projectRoot, $testFile) === $file['hash']) {
                $unchanged += array_flip($file['lines']);

                continue;
            }

            $changed += array_flip($file['lines']);
        }

        $unchangedSources = array_filter(
            $this->hashesBySourceFilePath,
            static fn (string $hash, string $source): bool => self::hashOf($projectRoot, $source) === $hash,
            ARRAY_FILTER_USE_BOTH,
        );

        return array_values(array_filter(
            $lines,
            static fn (string $line): bool => isset($unchanged[$line], $unchangedSources[self::sourceOf($line)]) && !isset($changed[$line]),
        ));
    }

    public function supportChanged(string $projectRoot): bool
    {
        return $this->supportHash !== '' && $this->supportHashDiffers($projectRoot);
    }

    /**
     * @throws \JsonException
     */
    public function save(string $path): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), recursive: true);
        }

        $baseline = [
            'across' => $this->across,
            'files' => $this->files,
            'lines' => $this->lines,
            'sources' => $this->hashesBySourceFilePath,
            'support' => $this->supportHash,
            'testFiles' => $this->testFiles,
        ];
        file_put_contents($path, json_encode($baseline, JSON_THROW_ON_ERROR));
    }

    private function supportHashDiffers(string $projectRoot): bool
    {
        return self::supportHashOf($projectRoot, $this->testFiles) !== $this->supportHash;
    }

    /**
     * @param list<string> $testFiles
     */
    private static function supportHashOf(string $projectRoot, array $testFiles): string
    {
        $hashes = [];

        foreach (TestFiles::supportFiles($testFiles, $projectRoot) as $supportFile) {
            $hashes[$supportFile] = self::hashOf($projectRoot, $supportFile);
        }

        return hash('sha256', serialize($hashes));
    }

    private static function sourceOf(string $line): string
    {
        return substr($line, 0, (int) strrpos($line, ':'));
    }

    private static function hashOf(string $projectRoot, string $file): string
    {
        $path = $projectRoot . DIRECTORY_SEPARATOR . $file;

        return is_file($path) ? (string) hash_file('sha256', $path) : '';
    }

}
