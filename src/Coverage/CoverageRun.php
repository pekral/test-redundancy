<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

use Pekral\TestRedundancy\Configuration;
use Pekral\TestRedundancy\Fingerprint\ContractFingerprints;
use Pekral\TestRedundancy\Support\TestFiles;
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
     * @param list<string> $paths
     * @return array<string, array<string, true>>
     */
    public function linesByTest(array $paths): array
    {
        return $this->runGroups($this->groups($paths), tolerateFailedTests: false)->linesByTest;
    }

    /**
     * @param list<string> $paths
     */
    public function run(array $paths): CoverageResult
    {
        return $this->runGroups($this->groups($paths), tolerateFailedTests: true);
    }

    /**
     * @param list<string> $paths
     * @return list<array{0: list<string>, 1: array<string, string>}>
     */
    private function groups(array $paths): array
    {
        $testFiles = array_values(array_filter(
            TestFiles::expand($paths, $this->configuration->projectRoot),
            fn (string $testFile): bool => !ContractFingerprints::declaresNoTestCase(
                $this->configuration->projectRoot . DIRECTORY_SEPARATOR . $testFile,
            ),
        ));
        $groups = TestDurations::load($this->configuration->absoluteDurationsPath())
            ->split($testFiles, min(count($testFiles), $this->configuration->processes));

        return array_map(
            fn (array $group, int $token): array => [$group, $this->workerEnvironment($token + 1)],
            $groups,
            array_keys($groups),
        );
    }

    /**
     * @return array<string, string>
     */
    private function workerEnvironment(int $token): array
    {
        return [
            'LARAVEL_PARALLEL_TESTING' => '1',
            ...$this->configuration->environment,
            'TEST_TOKEN' => (string) $token,
            'UNIQUE_TEST_TOKEN' => uniqid($token . '_'),
        ];
    }

    /**
     * @param list<array{0: list<string>, 1: array<string, string>}> $groups
     */
    private function runGroups(array $groups, bool $tolerateFailedTests): CoverageResult
    {
        $runs = [];

        try {
            foreach ($groups as [$paths, $environment]) {
                $runs[] = $this->start($paths, $environment);
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

            return new CoverageResult($linesByTest, $failedTests);
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
    private function start(array $paths, array $environment): array
    {
        $coverageFile = (string) tempnam(sys_get_temp_dir(), 'test-redundancy-coverage-');
        $junitFile = (string) tempnam(sys_get_temp_dir(), 'test-redundancy-junit-');
        $process = new Process(
            $this->runner->command(
                $paths,
                coverageFile: $coverageFile,
                junitFile: $junitFile,
            ),
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
