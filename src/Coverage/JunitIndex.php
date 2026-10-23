<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

use Pekral\TestRedundancy\Support\PestName;
use Pekral\TestRedundancy\Support\ProjectPath;
use Pekral\TestRedundancy\Support\TestKey;
use Symfony\Component\Filesystem\Path;

final readonly class JunitIndex
{

    /**
     * @param array<string, string> $fileByClass
     * @param array<string, string> $descriptionByEvaluable
     * @param array<string, float> $testSeconds
     */
    private function __construct(private array $fileByClass, private array $descriptionByEvaluable, private array $testSeconds)
    {
    }

    /**
     * @param list<string> $searchRoots
     */
    public static function fromFile(string $junitFile, string $projectRoot, array $searchRoots): self
    {
        $previousErrorHandling = libxml_use_internal_errors(use_errors: true);
        $report = is_file($junitFile) ? simplexml_load_string((string) file_get_contents($junitFile)) : false;
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorHandling);
        $fileByClass = [];
        $descriptionByEvaluable = [];
        $testSeconds = [];

        $testCases = $report === false ? null : $report->xpath('//testcase');

        foreach (is_array($testCases) ? $testCases : [] as $testCase) {
            $class = (string) $testCase['class'];
            $file = explode('::', (string) $testCase['file'], 2)[0];
            $description = (string) preg_replace('/ with data set .*$/s', '', (string) $testCase['name']);

            if ($file !== '') {
                $fileByClass[$class] = ProjectPath::relative(self::locate($file, [$projectRoot, ...$searchRoots]), $projectRoot);
            }

            $descriptionByEvaluable[$class . '::' . PestName::evaluable($description)] = $description;
            $testKey = TestKey::make($fileByClass[$class] ?? $class, $description);
            $testSeconds[$testKey] = ($testSeconds[$testKey] ?? 0.0) + (float) $testCase['time'];
        }

        return new self($fileByClass, $descriptionByEvaluable, $testSeconds);
    }

    /**
     * @param list<string> $coveredTestKeys
     * @return list<string>
     */
    public function testsWithoutCoverage(array $coveredTestKeys): array
    {
        $covered = [];

        foreach ($coveredTestKeys as $coveredTestKey) {
            $covered[explode(TestKey::DATASET_SEPARATOR, $coveredTestKey, 2)[0]] = true;
        }

        return array_keys(array_diff_key($this->testSeconds, $covered));
    }

    /**
     * @return array<string, float>
     */
    public function secondsByFile(): array
    {
        $secondsByFile = [];

        foreach ($this->testSeconds as $testKey => $seconds) {
            $file = TestKey::file($testKey);
            $secondsByFile[$file] = ($secondsByFile[$file] ?? 0.0) + $seconds;
        }

        return array_intersect_key($secondsByFile, array_flip($this->fileByClass));
    }

    public function fileOf(string $class): string
    {
        return $this->fileByClass[$class] ?? $class;
    }

    public function descriptionOf(string $class, string $method): string
    {
        return $this->descriptionByEvaluable[$class . '::' . $method] ?? $method;
    }

    /**
     * @param list<string> $roots
     */
    private static function locate(string $file, array $roots): string
    {
        foreach ($roots as $root) {
            $candidate = Path::isAbsolute($file) ? $file : Path::join($root, $file);

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $file;
    }

}
