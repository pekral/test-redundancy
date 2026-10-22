<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

use JsonException;
use Pekral\TestRedundancy\Support\TestKey;
use RuntimeException;

final class CoverageMap
{

    /** @var array<int, list<int>>|null */
    private ?array $testsByLine = null;

    /**
     * @param list<string> $lines
     * @param array<string, list<int>> $tests
     */
    public function __construct(public readonly string $commit, private readonly array $lines, private readonly array $tests)
    {
    }

    /**
     * @param array<string, array<string, true>> $linesByTest
     */
    public static function fromLinesByTest(string $commit, array $linesByTest): self
    {
        $lineIds = [];
        $tests = [];

        foreach ($linesByTest as $testKey => $lines) {
            $tests[$testKey] = [];

            foreach (array_keys($lines) as $line) {
                $lineIds[$line] ??= count($lineIds);
                $tests[$testKey][] = $lineIds[$line];
            }
        }

        return new self($commit, array_keys($lineIds), $tests);
    }

    /**
     * @throws \JsonException
     */
    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('No coverage map at %s. Build it with "test-redundancy map".', $path));
        }

        $map = json_decode((string) gzdecode((string) file_get_contents($path)), associative: true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($map) || !is_string($map['commit'] ?? null) || !is_array($map['lines'] ?? null) || !is_array($map['tests'] ?? null)) {
            throw new JsonException(sprintf('%s is not a coverage map.', $path));
        }

        /** @var list<string> $lines */
        $lines = $map['lines'];
        /** @var array<string, list<int>> $tests */
        $tests = $map['tests'];

        return new self($map['commit'], $lines, $tests);
    }

    /**
     * @throws \JsonException
     */
    public function save(string $path): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), recursive: true);
        }

        file_put_contents(
            $path,
            gzencode(json_encode(['commit' => $this->commit, 'lines' => $this->lines, 'tests' => $this->tests], JSON_THROW_ON_ERROR)),
        );
    }

    public function testCount(): int
    {
        return count($this->tests);
    }

    /**
     * @return array<string, array<string, true>>
     */
    public function testsOfFile(string $testFile): array
    {
        $linesByTest = [];

        foreach ($this->tests as $testKey => $lineIds) {
            if (TestKey::file($testKey) === $testFile) {
                $linesByTest[$testKey] = $this->lineKeys($lineIds);
            }
        }

        return $linesByTest;
    }

    /**
     * @param array<string, true> $lines
     * @param array<string, true> $excludedFiles
     * @return array<string, array<string, true>>
     */
    public function testsCovering(array $lines, array $excludedFiles): array
    {
        $lineIds = array_flip($this->lines);
        $testKeys = array_keys($this->tests);
        $covering = [];

        foreach (array_keys($lines) as $line) {
            foreach ($this->testsByLine()[$lineIds[$line] ?? -1] ?? [] as $testIndex) {
                $covering[$testKeys[$testIndex]] = true;
            }
        }

        $linesByTest = [];

        foreach (array_keys($covering) as $testKey) {
            if (!isset($excludedFiles[TestKey::file($testKey)])) {
                $linesByTest[$testKey] = array_intersect_key($this->lineKeys($this->tests[$testKey]), $lines);
            }
        }

        return $linesByTest;
    }

    /**
     * @param list<int> $lineIds
     * @return array<string, true>
     */
    private function lineKeys(array $lineIds): array
    {
        $lineKeys = [];

        foreach ($lineIds as $lineId) {
            $lineKeys[$this->lines[$lineId]] = true;
        }

        return $lineKeys;
    }

    /**
     * @return array<int, list<int>>
     */
    private function testsByLine(): array
    {
        if ($this->testsByLine === null) {
            $this->testsByLine = [];
            $testIndex = 0;

            foreach ($this->tests as $lineIds) {
                foreach ($lineIds as $lineId) {
                    $this->testsByLine[$lineId][] = $testIndex;
                }

                $testIndex += 1;
            }
        }

        return $this->testsByLine;
    }

}
