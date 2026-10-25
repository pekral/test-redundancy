<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

use Pekral\TestRedundancy\Configuration;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final readonly class CoverageRun
{

    private const int TIMEOUT_SECONDS = 7_200;

    private const array INHERITED_PARALLEL_ENVIRONMENT = ['PARATEST' => false, 'TEST_TOKEN' => false, 'UNIQUE_TEST_TOKEN' => false];

    public function __construct(private Configuration $configuration, private TestRunner $runner)
    {
    }

    /**
     * A test case that fails aborts the run.
     *
     * @param list<string> $paths
     * @return array<string, array<string, true>>
     */
    public function linesByTest(array $paths): array
    {
        return $this->runGroups($this->groups($paths), tolerateFailedTests: false)['linesByTest'];
    }

    /**
     * A test case that fails is left out of the lines and listed with its failure message in "failedTests".
     *
     * @param list<string> $paths
     * @return array{linesByTest: array<string, array<string, true>>, failedTests: array<string, string>}
     */
    public function run(array $paths): array
    {
        return $this->runGroups($this->groups($paths), tolerateFailedTests: true);
    }

    /**
     * @param list<string> $paths
     * @return list<array{0: list<string>, 1: bool, 2: array<string, string>}>
     */
    private function groups(array $paths): array
    {
        if (count($paths) === 1) {
            return [[$paths, true, $this->configuration->environment]];
        }

        $groups = TestDurations::load($this->configuration->absoluteDurationsPath())
            ->split($paths, min(count($paths), $this->configuration->processes));

        return array_map(
            fn (array $group, int $token): array => [$group, false, $this->workerEnvironment($token + 1)],
            $groups,
            array_keys($groups),
        );
    }

    /**
     * @return array<string, string>
     */
    private function workerEnvironment(int $token): array
    {
        return [...$this->configuration->environment, 'TEST_TOKEN' => (string) $token, 'UNIQUE_TEST_TOKEN' => uniqid($token . '_')];
    }

    /**
     * @param list<array{0: list<string>, 1: bool, 2: array<string, string>}> $groups
     * @return array{linesByTest: array<string, array<string, true>>, failedTests: array<string, string>}
     */
    private function runGroups(array $groups, bool $tolerateFailedTests): array
    {
        $runs = [];

        try {
            foreach ($groups as [$paths, $parallel, $environment]) {
                $runs[] = $this->start($paths, $parallel, $environment);
            }

            $reader = new CoverageReader($this->configuration);
            $linesByTest = [];
            $failedTests = [];
            $secondsByFile = [];

            foreach ($runs as [$process, $coverageFile, $junitFile]) {
                $exitCode = $process->wait();
                $junit = JunitIndex::fromFile($junitFile, $this->configuration->projectRoot, [$this->runner->rootDirectory()]);
                $groupFailedTests = $reader->failedTests($junit);

                if ($exitCode !== 0 && !($tolerateFailedTests && $groupFailedTests !== [] && filesize($coverageFile) > 0)) {
                    throw new ProcessFailedException($process);
                }

                $linesByTest = [...$linesByTest, ...$reader->linesByTest($coverageFile, $junit)];
                $failedTests = [...$failedTests, ...$groupFailedTests];
                $secondsByFile = [...$secondsByFile, ...$junit->secondsByFile()];
            }

            $this->recordDurations($secondsByFile);

            return ['linesByTest' => $linesByTest, 'failedTests' => $failedTests];
        } finally {
            foreach ($runs as [$process, $coverageFile, $junitFile]) {
                $process->stop();
                unlink($coverageFile);
                unlink($junitFile);
            }
        }
    }

    /**
     * @param list<string> $paths
     * @param array<string, string> $environment
     * @return array{0: \Symfony\Component\Process\Process, 1: string, 2: string}
     */
    private function start(array $paths, bool $parallel, array $environment): array
    {
        $coverageFile = (string) tempnam(sys_get_temp_dir(), 'test-redundancy-coverage-');
        $junitFile = (string) tempnam(sys_get_temp_dir(), 'test-redundancy-junit-');
        $process = new Process(
            $this->runner->command($paths, $parallel, $this->configuration->processes, $coverageFile, $junitFile),
            $this->configuration->projectRoot,
            [...self::INHERITED_PARALLEL_ENVIRONMENT, ...CoverageDriver::environment(), ...$environment],
            timeout: self::TIMEOUT_SECONDS,
        );
        $process->start();

        return [$process, $coverageFile, $junitFile];
    }

    /**
     * @param array<string, float> $secondsByFile
     */
    private function recordDurations(array $secondsByFile): void
    {
        $path = $this->configuration->absoluteDurationsPath();

        if ($secondsByFile !== []) {
            TestDurations::load($path)->with($secondsByFile)->save($path);
        }
    }

}
