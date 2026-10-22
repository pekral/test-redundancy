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
     */
    public function __construct(public array $testFiles, public array $lines, public bool $across)
    {
    }

    /**
     * @param array<string, array<string, array<string, true>>> $testsByFile
     */
    public static function fromTestsByFile(array $testsByFile, bool $across): self
    {
        $lines = [];

        foreach ($testsByFile as $tests) {
            foreach ($tests as $testLines) {
                $lines += $testLines;
            }
        }

        return new self(array_keys($testsByFile), array_keys($lines), $across);
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

        return new self($testFiles, $lines, ($baseline['across'] ?? false) === true);
    }

    /**
     * @throws \JsonException
     */
    public function save(string $path): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), recursive: true);
        }

        $baseline = ['across' => $this->across, 'lines' => $this->lines, 'testFiles' => $this->testFiles];
        file_put_contents($path, json_encode($baseline, JSON_THROW_ON_ERROR));
    }

}
