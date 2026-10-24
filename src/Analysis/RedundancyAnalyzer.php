<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

use Pekral\TestRedundancy\Coverage\CoverageMap;
use Pekral\TestRedundancy\Fingerprint\FingerprintCache;
use Pekral\TestRedundancy\Support\TestKey;

final readonly class RedundancyAnalyzer
{

    public function __construct(private FingerprintCache $fingerprints)
    {
    }

    /**
     * @param array<string, array<string, array<string, true>>> $testsByFile
     * @return list<\Pekral\TestRedundancy\Analysis\FileReport>
     */
    public function analyze(array $testsByFile, ?CoverageMap $map): array
    {
        $analyzedTests = array_filter(array_merge([], ...array_values($testsByFile)), static fn (array $lines): bool => $lines !== []);
        $fixedTests = $this->fixedTests($analyzedTests, $testsByFile, $map);
        $removed = $this->eliminate($analyzedTests, $fixedTests);
        $pool = [...array_diff_key($analyzedTests, $removed), ...$fixedTests];
        $reports = [];

        foreach ($testsByFile as $testFile => $tests) {
            $candidates = [];

            foreach (array_keys(array_intersect_key($removed, $tests)) as $testKey) {
                $coverPool = $this->coverPool($testKey, $analyzedTests, $removed, $pool);
                $coveredBy = $this->coveredBy($testKey, $tests[$testKey], $coverPool, $pool);
                $differsBy = $this->fingerprints->difference($testKey, $coveredBy);
                $candidates[] = new Candidate(
                    $testKey,
                    array_map(static fn (string $covering): string => self::display($covering, $testFile), $coveredBy),
                    $differsBy,
                    $this->fingerprints->coveringDifference($testKey, $coveredBy),
                    $differsBy === [] ? $this->linesOnlyTheUnionReaches($testKey, $tests[$testKey], $coveredBy, $coverPool) : [],
                    $this->fingerprints->assertsNothing($testKey),
                    $this->fingerprints->sharedDataset($testKey),
                );
            }

            $reports[] = new FileReport(
                $testFile,
                count($tests),
                count(array_merge([], ...array_values($tests))),
                $candidates,
                array_map(TestKey::testCase(...), array_keys(array_filter($tests, static fn (array $lines): bool => $lines === []))),
            );
        }

        return $reports;
    }

    /**
     * @param array<string, array<string, true>> $analyzedTests
     * @param array<string, array<string, array<string, true>>> $testsByFile
     * @return array<string, array<string, true>>
     */
    private function fixedTests(array $analyzedTests, array $testsByFile, ?CoverageMap $map): array
    {
        $analyzedLines = array_merge([], ...array_values($analyzedTests));

        return $map?->testsCovering($analyzedLines, array_fill_keys(array_keys($testsByFile), value: true)) ?? [];
    }

    /**
     * @param array<string, array<string, true>> $analyzedTests
     * @param array<string, true> $removed
     * @param array<string, array<string, true>> $pool
     * @return array<string, array<string, true>>
     */
    private function coverPool(string $testKey, array $analyzedTests, array $removed, array $pool): array
    {
        $removedLater = array_slice($removed, array_flip(array_keys($removed))[$testKey] + 1, preserve_keys: true);

        return [...$pool, ...array_intersect_key($analyzedTests, $removedLater)];
    }

    /**
     * @param array<string, true> $lines
     * @param array<string, array<string, true>> $coverPool
     * @param array<string, array<string, true>> $pool
     * @return list<string>
     */
    private function coveredBy(string $testKey, array $lines, array $coverPool, array $pool): array
    {
        return $this->singleCoverWithoutDifference($testKey, $lines, $coverPool) ?? $this->smallestCover($lines, $pool);
    }

    /**
     * @param array<string, array<string, true>> $tests
     * @param array<string, array<string, true>> $fixedTests
     * @return array<string, true>
     */
    private function eliminate(array $tests, array $fixedTests): array
    {
        $fixedLines = array_merge([], ...array_values($fixedTests));
        $coveringCount = array_count_values(array_merge(...array_map(array_keys(...), array_values($tests))));
        $removed = [];

        foreach ($this->removalOrder($tests) as $testKey) {
            $lines = array_keys($tests[$testKey]);

            if (!$this->isCoveredElsewhere($lines, $coveringCount, $fixedLines)) {
                continue;
            }

            foreach ($lines as $line) {
                $coveringCount[$line] -= 1;
            }

            $removed[$testKey] = true;
        }

        return $removed;
    }

    /**
     * @param array<string, true> $lines
     * @param array<string, array<string, true>> $candidates
     * @return list<string>|null
     */
    private function singleCoverWithoutDifference(string $testKey, array $lines, array $candidates): ?array
    {
        foreach ($candidates as $candidateKey => $candidateLines) {
            if (array_diff_key($lines, $candidateLines) === [] && $this->fingerprints->difference($testKey, [$candidateKey]) === []) {
                return [$candidateKey];
            }
        }

        return null;
    }

    /**
     * @param array<string, true> $lines
     * @param array<string, array<string, true>> $pool
     * @return list<string>
     */
    private function smallestCover(array $lines, array $pool): array
    {
        $cover = [];

        while ($lines !== []) {
            $overlaps = array_map(static fn (array $poolLines): int => count(array_intersect_key($lines, $poolLines)), $pool);
            arsort($overlaps);
            $best = (string) array_key_first($overlaps);
            $cover[] = $best;
            $lines = array_diff_key($lines, $pool[$best]);
        }

        return $cover;
    }

    /**
     * @param array<string, true> $lines
     * @param list<string> $coveredBy
     * @param array<string, array<string, true>> $pool
     * @return list<string>
     */
    private function linesOnlyTheUnionReaches(string $testKey, array $lines, array $coveredBy, array $pool): array
    {
        $missingByMatchingTest = array_map(
            static fn (string $covering): array => array_keys(array_diff_key($lines, $pool[$covering])),
            array_filter($coveredBy, fn (string $covering): bool => $this->fingerprints->difference($testKey, [$covering]) === []),
        );
        usort($missingByMatchingTest, static fn (array $first, array $second): int => count($first) <=> count($second));

        return $missingByMatchingTest[0] ?? [];
    }

    /**
     * @param list<string> $lines
     * @param array<string, int> $coveringCount
     * @param array<string, true> $fixedLines
     */
    private function isCoveredElsewhere(array $lines, array $coveringCount, array $fixedLines): bool
    {
        foreach ($lines as $line) {
            if ($coveringCount[$line] === 1 && !isset($fixedLines[$line])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, array<string, true>> $tests
     * @return list<string>
     */
    private function removalOrder(array $tests): array
    {
        $testKeys = array_keys($tests);
        usort(
            $testKeys,
            static fn (string $first, string $second): int => [count($tests[$first]), TestKey::isDatasetRow($first)]
                <=> [count($tests[$second]), TestKey::isDatasetRow($second)],
        );

        return $testKeys;
    }

    private static function display(string $testKey, string $relativeToFile): string
    {
        return TestKey::file($testKey) === $relativeToFile ? TestKey::testCase($testKey) : $testKey;
    }

}
