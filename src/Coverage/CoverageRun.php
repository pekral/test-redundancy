<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

use Pekral\TestRedundancy\Configuration;
use Pekral\TestRedundancy\Fingerprint\ContractFingerprints;
use Pekral\TestRedundancy\Support\TestFiles;
use Pekral\TestRedundancy\Support\TestKey;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final readonly class CoverageRun
{

    private const int TIMEOUT_SECONDS = 7_200;

    private const string TEST_CASE = 'PHPUnit\\Framework\\TestCase';

    private const string DECLARES_NO_TEST_CASE = 'require $argv[1]; $before = get_declared_classes(); require $argv[2];'
        . ' exit(!array_any(array_diff(get_declared_classes(), $before),'
        . ' static fn (string $class): bool => is_subclass_of($class, $argv[3])) ? 0 : 1);';

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
        $result = $this->runGroups($this->groups($paths), tolerateFailedTests: true);
        $failedFiles = $this->filesOf($result->failedTests);

        if ($failedFiles === []) {
            return $result;
        }

        try {
            return $result->withRetry($this->runGroups($this->groups($failedFiles), tolerateFailedTests: true), $failedFiles);
        } catch (ProcessFailedException) {
            return $result;
        }
    }

    /**
     * @param array<string, string> $failedTests
     * @return list<string>
     */
    private function filesOf(array $failedTests): array
    {
        return array_values(array_unique(array_filter(
            array_map(TestKey::file(...), array_keys($failedTests)),
            fn (string $file): bool => is_file($this->configuration->projectRoot . DIRECTORY_SEPARATOR . $file),
        )));
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
                $this->extendsNoTestCase(...),
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

    private function extendsNoTestCase(string $testFile): bool
    {
        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                self::DECLARES_NO_TEST_CASE,
                Path::join($this->runner->rootDirectory(), 'vendor', 'autoload.php'),
                $testFile,
                self::TEST_CASE,
            ],
            $this->configuration->projectRoot,
            $this->configuration->environment,
            timeout: null,
        );

        return $process->run() === 0;
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

            return $this->collect($runs, $tolerateFailedTests);
        } finally {
            foreach ($runs as [$process, $coverageFile, $junitFile]) {
                $process->stop();
                unlink($coverageFile);
                unlink($junitFile);
            }
        }
    }

    /**
     * @param list<array{0: \Symfony\Component\Process\Process, 1: string, 2: string}> $runs
     */
    private function collect(array $runs, bool $tolerateFailedTests): CoverageResult
    {
        $reader = new CoverageReader($this->configuration);
        $linesByTest = [];
        $failedTests = [];
        $secondsByTest = [];
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
            $secondsByTest = [...$secondsByTest, ...$reader->secondsByTest($junit)];
            $secondsByFile = [...$secondsByFile, ...$junit->secondsByFile()];
        }

        $this->recordDurations($secondsByFile);

        return new CoverageResult($linesByTest, $failedTests, $secondsByTest, []);
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
