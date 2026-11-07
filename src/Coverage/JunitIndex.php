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
     * @param array<string, float> $secondsByTestId
     */
    private function __construct(
        private array $fileByClass,
        private array $descriptionByEvaluable,
        private array $testSeconds,
        private array $passedTestKeys,
        private array $failureByTestId,
        private array $secondsByTestId,
    ) {
    }

    /**
     * @param list<string> $searchRoots
     */
    public static function fromFile(string $junitFile, string $projectRoot, array $searchRoots, ?PhpUnitClasses $classes = null): self
    {
        $fileByClass = [];
        $descriptionByEvaluable = [];
        $testSeconds = [];
        $passedTestKeys = [];
        $failureByTestId = [];
        $secondsByTestId = [];

        foreach (self::testCasesOf($junitFile) as $testCase) {
            $class = (string) $testCase['class'];
            [$file, $name] = self::identity($testCase, [$projectRoot, ...$searchRoots], $classes);
            $description = (string) preg_replace('/ with data set .*$/s', '', $name);

            if ($file !== '') {
                $fileByClass[$class] = ProjectPath::relative($file, $projectRoot);
            }

            $descriptionByEvaluable[$class . '::' . PestName::evaluable($description)] = $description;
            $testKey = TestKey::make($fileByClass[$class] ?? $class, $description);
            $testSeconds[$testKey] = ($testSeconds[$testKey] ?? 0.0) + (float) $testCase['time'];
            $secondsByTestId[$class . '::' . $name] = ($secondsByTestId[$class . '::' . $name] ?? 0.0) + (float) $testCase['time'];
            $failure = $testCase->failure[0] ?? $testCase->error[0] ?? null;

            if ($failure === null) {
                $passedTestKeys[$testKey] = true;

                continue;
            }

            $failureByTestId[$class . '::' . $name] = self::failureMessage((string) $failure, $class, (string) $testCase['name']);
        }

        return new self($fileByClass, $descriptionByEvaluable, $testSeconds, $passedTestKeys, $failureByTestId, $secondsByTestId);
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
    public function secondsByTestId(): array
    {
        return $this->secondsByTestId;
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
        return $this->descriptionByEvaluable[$class . '::' . PestName::identifier(mb_scrub($method, 'UTF-8'))] ?? $method;
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    private static function testCasesOf(string $junitFile): array
    {
        $previousErrorHandling = libxml_use_internal_errors(use_errors: true);
        $report = is_file($junitFile) ? simplexml_load_string(mb_scrub((string) file_get_contents($junitFile), 'UTF-8')) : false;
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
     * Pest writes the JUnit entry of a PHPUnit class with a display name instead of the file and the method, such as
     * file="Foo (Tests\\Foo)" and name="Adds with data set #0" for Tests\\FooTest::testAdds. Such an entry takes the file
     * and the method name of the class it names.
     *
     * @param list<string> $roots
     * @return array{0: string, 1: string}
     */
    private static function identity(\SimpleXMLElement $testCase, array $roots, ?PhpUnitClasses $classes): array
    {
        $name = (string) $testCase['name'];
        $file = explode('::', (string) $testCase['file'], 2)[0];
        $located = $file === '' ? '' : self::locate($file, $roots);
        $declared = $located === '' || is_file($located) ? null : $classes?->of((string) $testCase['class']);

        if ($declared === null) {
            return [$located, $name];
        }

        [$description, $dataSet] = array_pad(explode(' with data set ', $name, 2), 2, value: null);
        $description = (string) $description;

        $method = $declared['methods'][PhpUnitClasses::normalized($description)] ?? $description;

        return [$declared['file'], $method . ($dataSet === null ? '' : ' with data set ' . $dataSet)];
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
