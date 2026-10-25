<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

use Pekral\TestRedundancy\Configuration;
use Pekral\TestRedundancy\Support\PestName;
use Pekral\TestRedundancy\Support\ProjectPath;
use Pekral\TestRedundancy\Support\TestKey;
use RuntimeException;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;

final readonly class CoverageReader
{

    private const string SERIALIZATION_HEADER = '<?php // phpunit/php-code-coverage serialization format';

    private const int HEADER_LENGTH = 64;

    public function __construct(private Configuration $configuration)
    {
    }

    /**
     * @return array<string, array<string, true>>
     */
    public function linesByTest(string $coverageFile, JunitIndex $junit): array
    {
        [$data, $basePath] = $this->load($coverageFile);
        $linesByTest = [];

        foreach ($this->linesByTestId($data, $basePath) as $testId => $lines) {
            $testKey = self::testKeyOf($testId, $junit);
            $linesByTest[$testKey] = ($linesByTest[$testKey] ?? []) + $lines;
        }

        $linesByTest = array_diff_key($linesByTest, $this->failedTests($junit));

        foreach ($junit->testsWithoutCoverage(array_keys($linesByTest)) as $testKey) {
            $linesByTest[$testKey] = [];
        }

        return $linesByTest;
    }

    /**
     * The failure message of every failed or errored test case, by test key.
     *
     * @return array<string, string>
     */
    public function failedTests(JunitIndex $junit): array
    {
        $failedTests = [];

        foreach ($junit->failureByTestId() as $testId => $message) {
            $failedTests[self::testKeyOf($testId, $junit)] = $message;
        }

        return $failedTests;
    }

    public static function testKeyOf(string $testId, JunitIndex $junit): string
    {
        [$class, $method] = array_pad(explode('::', (string) preg_replace('/^P\\\\/', '', $testId), 2), 2, '');
        [$method, $dataset] = self::splitDataset($method);
        $testCase = PestName::isEvaluable($method) ? $junit->descriptionOf($class, $method) : $method;

        return TestKey::make($junit->fileOf($class), $dataset === null ? $testCase : $testCase . TestKey::DATASET_SEPARATOR . $dataset);
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function linesByTestId(ProcessedCodeCoverageData $data, string $basePath): array
    {
        $linesByTestId = [];

        foreach ($data->lineCoverage() as $file => $lines) {
            $relativePath = ProjectPath::relative($basePath === '' ? $file : $basePath . '/' . $file, $this->configuration->projectRoot);

            if (!$this->configuration->isSource($relativePath)) {
                continue;
            }

            $linesByTestId = $this->withLinesOfFile($linesByTestId, $relativePath, $this->testIdsByLine($data, $lines));
        }

        return $linesByTestId;
    }

    /**
     * @param array<string, array<string, true>> $linesByTestId
     * @param array<int, list<string>> $testIdsByLine
     * @return array<string, array<string, true>>
     */
    private function withLinesOfFile(array $linesByTestId, string $relativePath, array $testIdsByLine): array
    {
        foreach ($testIdsByLine as $line => $testIds) {
            foreach ($testIds as $testId) {
                $linesByTestId[$testId][$relativePath . ':' . $line] = true;
            }
        }

        return $linesByTestId;
    }

    /**
     * @param array<int, array<int|string, mixed>|null> $lines
     * @return array<int, list<string>>
     */
    private function testIdsByLine(ProcessedCodeCoverageData $data, array $lines): array
    {
        return array_map(fn (?array $hits): array => $this->testIdsOf($data, $hits ?? []), $lines);
    }

    /**
     * @return array{0: \SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData, 1: string}
     */
    private function load(string $coverageFile): array
    {
        if ($coverageFile === '') {
            throw new RuntimeException('No coverage file was given.');
        }

        if (str_starts_with((string) file_get_contents($coverageFile, length: self::HEADER_LENGTH), self::SERIALIZATION_HEADER)) {
            $coverage = new Unserializer()->unserialize($coverageFile);

            return [$coverage['codeCoverage'], $coverage['basePath']];
        }

        $coverage = include $coverageFile;

        if (!$coverage instanceof CodeCoverage) {
            throw new RuntimeException(sprintf('%s does not hold a php-code-coverage report.', $coverageFile));
        }

        // @codeCoverageIgnoreStart
        return [$coverage->getData(), ''];
        // @codeCoverageIgnoreEnd
    }

    /**
     * @param array<int|string, mixed> $hits
     * @return list<string>
     */
    private function testIdsOf(ProcessedCodeCoverageData $data, array $hits): array
    {
        if ($hits === []) {
            return [];
        }

        $legacyTestIds = array_values(array_filter($hits, is_string(...)));

        // @codeCoverageIgnoreStart
        if ($legacyTestIds !== []) {
            return $legacyTestIds;
        }

        // @codeCoverageIgnoreEnd
        return array_values(array_intersect_key($data->testIds(), $hits));
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private static function splitDataset(string $method): array
    {
        if (preg_match('/^(.*?)(?: with data set |#)(.*)$/s', $method, $matches) !== 1) {
            return [$method, null];
        }

        return [$matches[1], trim((string) preg_replace('/^"?dataset\s*/', '', $matches[2]), '"')];
    }

}
