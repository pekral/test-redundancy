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
     * @param array<string, true> $passedTestKeys
     * @param array<string, string> $failureByTestId
     */
    private function __construct(
        private array $fileByClass,
        private array $descriptionByEvaluable,
        private array $testSeconds,
        private array $passedTestKeys,
        private array $failureByTestId,
    ) {
    }

    /**
     * @param list<string> $searchRoots
     */
    public static function fromFile(string $junitFile, string $projectRoot, array $searchRoots): self
    {
        $fileByClass = [];
        $descriptionByEvaluable = [];
        $testSeconds = [];
        $passedTestKeys = [];
        $failureByTestId = [];

        foreach (self::testCasesOf($junitFile) as $testCase) {
            $class = (string) $testCase['class'];
            $name = (string) $testCase['name'];
            $file = explode('::', (string) $testCase['file'], 2)[0];
            $description = (string) preg_replace('/ with data set .*$/s', '', (string) $testCase['name']);

            if ($file !== '') {
                $fileByClass[$class] = ProjectPath::relative(self::locate($file, [$projectRoot, ...$searchRoots]), $projectRoot);
            }

            $descriptionByEvaluable[$class . '::' . PestName::evaluable($description)] = $description;
            $testKey = TestKey::make($fileByClass[$class] ?? $class, $description);
            $testSeconds[$testKey] = ($testSeconds[$testKey] ?? 0.0) + (float) $testCase['time'];
            $failure = $testCase->failure[0] ?? $testCase->error[0] ?? null;

            if ($failure === null) {
                $passedTestKeys[$testKey] = true;

                continue;
            }

            $failureByTestId[$class . '::' . $name] = self::failureMessage((string) $failure, $class, $name);
        }

        return new self($fileByClass, $descriptionByEvaluable, $testSeconds, $passedTestKeys, $failureByTestId);
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

        return array_keys(array_diff_key($this->passedTestKeys, $covered));
    }

    /**
     * @return array<string, string>
     */
    public function failureByTestId(): array
    {
        return $this->failureByTestId;
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
     * @return list<\SimpleXMLElement>
     */
    private static function testCasesOf(string $junitFile): array
    {
        $previousErrorHandling = libxml_use_internal_errors(use_errors: true);
        $report = is_file($junitFile) ? simplexml_load_string((string) file_get_contents($junitFile)) : false;
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorHandling);
        $testCases = $report === false ? null : $report->xpath('//testcase');

        return is_array($testCases) ? array_values($testCases) : [];
    }

    private static function failureMessage(string $text, string $class, string $name): string
    {
        $message = trim($text);

        foreach ([$class . '::' . $name, $name] as $title) {
            if (str_starts_with($message, $title)) {
                return trim(substr($message, strlen($title)));
            }
        }

        return $message;
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
