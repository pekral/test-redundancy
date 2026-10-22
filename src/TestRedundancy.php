<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy;

use Pekral\TestRedundancy\Analysis\RedundancyAnalyzer;
use Pekral\TestRedundancy\Analysis\Verification;
use Pekral\TestRedundancy\Coverage\CoverageBaseline;
use Pekral\TestRedundancy\Coverage\CoverageMap;
use Pekral\TestRedundancy\Coverage\CoverageRun;
use Pekral\TestRedundancy\Coverage\TestRunner;
use Pekral\TestRedundancy\Fingerprint\FingerprintCache;
use Pekral\TestRedundancy\Support\Git;
use Pekral\TestRedundancy\Support\TestFiles;
use Pekral\TestRedundancy\Support\TestKey;

final readonly class TestRedundancy
{

    private const int MAX_TEST_FILES_PER_MAP_RUN = 400;

    private Git $git;

    public function __construct(private Configuration $configuration, private CoverageRun $coverageRun)
    {
        $this->git = new Git($configuration->projectRoot);
    }

    public static function forProject(string $projectRoot, ?int $processes): self
    {
        $configuration = Configuration::load($projectRoot);
        $configuration = ($processes === null ? $configuration : $configuration->withProcesses($processes))->validated();

        return new self($configuration, new CoverageRun($configuration, TestRunner::detect($configuration)));
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    public function testFiles(array $paths, ?string $baseRef): array
    {
        if ($paths !== []) {
            return TestFiles::expand($paths, $this->configuration->projectRoot);
        }

        return TestFiles::onlyTests($this->git->changedFilesAgainst($baseRef ?? $this->git->defaultBaseRef()));
    }

    /**
     * @param list<string> $testFiles
     * @param list<string> $requestedPaths
     * @return list<\Pekral\TestRedundancy\Analysis\FileReport>
     */
    public function analyse(array $testFiles, array $requestedPaths): array
    {
        $testsByFile = $this->testsByFile($testFiles, $this->linesByTest(count($requestedPaths) === 1 ? $requestedPaths : $testFiles));
        $this->saveBaseline($testsByFile, across: false);
        $analyzer = new RedundancyAnalyzer(new FingerprintCache($this->configuration->projectRoot));
        $reports = [];

        foreach ($testsByFile as $testFile => $tests) {
            $reports = [...$reports, ...$analyzer->analyze([$testFile => $tests], map: null)];
        }

        return $reports;
    }

    /**
     * @param list<string> $testFiles
     * @return list<\Pekral\TestRedundancy\Analysis\FileReport>
     */
    public function analyseAcross(array $testFiles, CoverageMap $map): array
    {
        $sourceChanged = $this->sourceChangedSince($map);
        $testsByFile = [];

        $unmapped = [];

        foreach ($testFiles as $testFile) {
            $mapped = $sourceChanged || $this->git->changedSince($map->commit, [$testFile]) !== [] ? [] : $map->testsOfFile($testFile);

            if ($mapped === []) {
                $unmapped[] = $testFile;

                continue;
            }

            $testsByFile[$testFile] = $mapped;
        }

        if ($unmapped !== []) {
            $testsByFile = [
                ...$testsByFile,
                ...$this->testsByFile($unmapped, $this->linesByTest($unmapped)),
            ];
        }

        $this->saveBaseline($testsByFile, across: true);

        return new RedundancyAnalyzer(new FingerprintCache($this->configuration->projectRoot))->analyze($testsByFile, $map);
    }

    public function verify(): Verification
    {
        $baseline = CoverageBaseline::load($this->configuration->absoluteBaselinePath());
        $existing = array_values(array_filter(
            $baseline->testFiles,
            fn (string $testFile): bool => is_file($this->configuration->projectRoot . DIRECTORY_SEPARATOR . $testFile),
        ));
        $covered = [];

        foreach ($existing === [] ? [] : $this->linesByTest($existing) as $lines) {
            $covered += $lines;
        }

        $lost = array_diff_key(array_fill_keys($baseline->lines, value: true), $covered);

        if ($baseline->across && $lost !== []) {
            $otherTests = $this->loadMap()->testsCovering($lost, array_fill_keys($baseline->testFiles, value: true));
            $lost = array_diff_key($lost, array_merge([], ...array_values($otherTests)));
        }

        $unstable = $baseline->unstableAmong(array_keys($lost), $this->configuration->projectRoot);

        return new Verification(
            count($baseline->lines),
            array_values(array_diff(array_keys($lost), $unstable)),
            array_values(array_diff($baseline->testFiles, $existing)),
            $unstable,
        );
    }

    public function sourceChangedSince(CoverageMap $map): bool
    {
        return $this->changedSourceFiles($map) !== [];
    }

    /**
     * @return list<string>
     */
    public function changedSourceFiles(CoverageMap $map): array
    {
        return $this->git->changedSince($map->commit, $this->configuration->sourcePaths);
    }

    public function loadMap(): CoverageMap
    {
        return CoverageMap::load($this->configuration->absoluteMapPath());
    }

    /**
     * @param list<string> $paths
     * @param callable(list<string>): void $onRun
     */
    public function buildMap(array $paths, callable $onRun): CoverageMap
    {
        $linesByTest = [];

        foreach (TestFiles::chunks($paths, $this->configuration->projectRoot, self::MAX_TEST_FILES_PER_MAP_RUN) as $chunk) {
            $onRun($chunk);
            $linesByTest = [...$linesByTest, ...$this->linesByTest($chunk)];
        }

        $map = CoverageMap::fromLinesByTest($this->git->headCommit(), $linesByTest);
        $map->save($this->configuration->absoluteMapPath());

        return $map;
    }

    /**
     * @param list<string> $paths
     * @return array<string, array<string, true>>
     */
    private function linesByTest(array $paths): array
    {
        return $this->coverageRun->linesByTest($paths);
    }

    /**
     * @param list<string> $testFiles
     * @param array<string, array<string, true>> $linesByTest
     * @return array<string, array<string, array<string, true>>>
     */
    private function testsByFile(array $testFiles, array $linesByTest): array
    {
        $testsByFile = array_fill_keys($testFiles, []);

        foreach ($linesByTest as $testKey => $lines) {
            $testFile = TestKey::file($testKey);

            if (isset($testsByFile[$testFile])) {
                $testsByFile[$testFile][$testKey] = $lines;
            }
        }

        return $testsByFile;
    }

    /**
     * @param array<string, array<string, array<string, true>>> $testsByFile
     */
    private function saveBaseline(array $testsByFile, bool $across): void
    {
        CoverageBaseline::fromTestsByFile($testsByFile, $across, $this->configuration->projectRoot)
            ->save($this->configuration->absoluteBaselinePath());
    }

}
