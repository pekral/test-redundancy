<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

use Pekral\TestRedundancy\Support\PestName;
use Pekral\TestRedundancy\Support\ProjectPath;
use Symfony\Component\Filesystem\Path;

final readonly class JunitIndex
{

    /**
     * @param array<string, string> $fileByClass
     * @param array<string, string> $descriptionByEvaluable
     */
    private function __construct(private array $fileByClass, private array $descriptionByEvaluable)
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

        $testCases = $report === false ? null : $report->xpath('//testcase');

        foreach (is_array($testCases) ? $testCases : [] as $testCase) {
            $class = (string) $testCase['class'];
            $file = explode('::', (string) $testCase['file'], 2)[0];
            $description = (string) preg_replace('/ with data set .*$/s', '', (string) $testCase['name']);

            if ($file !== '') {
                $fileByClass[$class] = ProjectPath::relative(self::locate($file, [$projectRoot, ...$searchRoots]), $projectRoot);
            }

            $descriptionByEvaluable[$class . '::' . PestName::evaluable($description)] = $description;
        }

        return new self($fileByClass, $descriptionByEvaluable);
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
