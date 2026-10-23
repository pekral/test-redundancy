<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

final readonly class TestDurations
{

    /**
     * @param array<string, float> $secondsByFile
     */
    private function __construct(private array $secondsByFile)
    {
    }

    public static function load(string $path): self
    {
        $values = is_file($path) ? json_decode((string) file_get_contents($path), associative: true) : null;
        $secondsByFile = [];

        foreach (is_array($values) ? $values : [] as $file => $seconds) {
            if (self::isDuration($seconds)) {
                $secondsByFile[(string) $file] = (float) $seconds;
            }
        }

        return new self($secondsByFile);
    }

    /**
     * @param array<string, float> $secondsByFile
     */
    public function with(array $secondsByFile): self
    {
        return new self([...$this->secondsByFile, ...$secondsByFile]);
    }

    /**
     * @throws \JsonException
     */
    public function save(string $path): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), recursive: true);
        }

        file_put_contents($path, json_encode($this->secondsByFile, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $paths
     * @return list<list<string>>
     */
    public function split(array $paths, int $groupCount): array
    {
        $recorded = array_intersect_key($this->secondsByFile, array_flip($paths));

        return $recorded === [] ? $this->roundRobin($paths, $groupCount) : $this->balanced($paths, $groupCount, $recorded);
    }

    /**
     * @param list<string> $paths
     * @return list<list<string>>
     */
    private function roundRobin(array $paths, int $groupCount): array
    {
        $groups = [];

        foreach ($paths as $index => $path) {
            $groups[$index % $groupCount][] = $path;
        }

        return array_values($groups);
    }

    /**
     * @param list<string> $paths
     * @param non-empty-array<string, float> $recorded
     * @return list<list<string>>
     */
    private function balanced(array $paths, int $groupCount, array $recorded): array
    {
        $unknownSeconds = array_sum($recorded) / count($recorded);
        $seconds = [];

        foreach ($paths as $path) {
            $seconds[$path] = $recorded[$path] ?? $unknownSeconds;
        }

        $rank = static fn (string $path): array => [isset($recorded[$path]) ? 1 : 0, -$seconds[$path]];
        usort($paths, static fn (string $first, string $second): int => $rank($first) <=> $rank($second));

        $groups = array_fill(0, $groupCount, []);
        $totals = array_fill(0, $groupCount, 0.0);

        foreach ($paths as $path) {
            $group = $this->lightestGroup($totals, $groups);
            $groups[$group][] = $path;
            $totals[$group] += $seconds[$path];
        }

        return $groups;
    }

    /**
     * @param array<int, float> $totals
     * @param array<int, list<string>> $groups
     */
    private function lightestGroup(array $totals, array $groups): int
    {
        $lightest = 0;

        foreach (array_keys($groups) as $group) {
            if ([$totals[$group], count($groups[$group])] < [$totals[$lightest], count($groups[$lightest])]) {
                $lightest = $group;
            }
        }

        return $lightest;
    }

    /**
     * @phpstan-assert-if-true int|float $seconds
     */
    private static function isDuration(mixed $seconds): bool
    {
        return (is_int($seconds) || is_float($seconds)) && is_finite((float) $seconds) && $seconds >= 0;
    }

}
