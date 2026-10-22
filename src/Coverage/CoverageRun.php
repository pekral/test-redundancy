<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

use Pekral\TestRedundancy\Configuration;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final readonly class CoverageRun
{

    private const TIMEOUT_SECONDS = 7_200;

    public function __construct(private Configuration $configuration, private TestRunner $runner)
    {
    }

    /**
     * @param list<string> $paths
     * @return array<string, array<string, true>>
     */
    public function linesByTest(array $paths): array
    {
        $groupCount = min(count($paths), $this->configuration->processes);

        if ($groupCount <= 1) {
            $parallel = count($paths) === 1;
            $environment = $this->runner->runsInParallel($parallel, $this->configuration->processes)
                ? $this->configuration->parallelEnvironment
                : $this->configuration->environment;

            return $this->runGroups([[$paths, $parallel, $environment]]);
        }

        $groups = [];

        foreach (array_keys($paths) as $index) {
            $groups[$index % $groupCount][] = $paths[$index];
        }

        return $this->runGroups(array_map(
            fn (array $group, int $token): array => [$group, false, $this->workerEnvironment($token + 1)],
            $groups,
            array_keys($groups),
        ));
    }

    /**
     * @return array<string, string>
     */
    private function workerEnvironment(int $token): array
    {
        return [...$this->configuration->parallelEnvironment, 'TEST_TOKEN' => (string) $token, 'UNIQUE_TEST_TOKEN' => uniqid($token . '_')];
    }

    /**
     * @param list<array{0: list<string>, 1: bool, 2: array<string, string>}> $groups
     * @return array<string, array<string, true>>
     */
    private function runGroups(array $groups): array
    {
        $runs = [];

        try {
            foreach ($groups as [$paths, $parallel, $environment]) {
                $runs[] = $this->start($paths, $parallel, $environment);
            }

            $linesByTest = [];

            foreach ($runs as [$process, $coverageFile, $junitFile]) {
                if ($process->wait() !== 0) {
                    throw new ProcessFailedException($process);
                }

                $linesByTest = [...$linesByTest, ...$this->read($coverageFile, $junitFile)];
            }

            return $linesByTest;
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
            [...CoverageDriver::environment(), ...$environment],
            timeout: self::TIMEOUT_SECONDS,
        );
        $process->start();

        return [$process, $coverageFile, $junitFile];
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function read(string $coverageFile, string $junitFile): array
    {
        $junit = JunitIndex::fromFile($junitFile, $this->configuration->projectRoot, [$this->runner->rootDirectory()]);

        return (new CoverageReader($this->configuration))->linesByTest($coverageFile, $junit);
    }

}
