<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

use Pekral\TestRedundancy\Configuration;
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
    public function linesByTest(array $paths, bool $parallel): array
    {
        $coverageFile = (string) tempnam(sys_get_temp_dir(), 'test-redundancy-coverage-');
        $junitFile = (string) tempnam(sys_get_temp_dir(), 'test-redundancy-junit-');

        try {
            $process = new Process(
                $this->runner->command($paths, $parallel, $this->configuration->processes, $coverageFile, $junitFile),
                $this->configuration->projectRoot,
                [
                    ...CoverageDriver::environment(),
                    ...($parallel ? $this->configuration->parallelEnvironment : $this->configuration->environment),
                ],
                timeout: self::TIMEOUT_SECONDS,
            );
            $process->mustRun();

            $junit = JunitIndex::fromFile(
                $junitFile,
                $this->configuration->projectRoot,
                [$this->runner->rootDirectory($this->configuration->projectRoot)],
            );

            return (new CoverageReader($this->configuration))->linesByTest($coverageFile, $junit);
        } finally {
            unlink($coverageFile);
            unlink($junitFile);
        }
    }

}
