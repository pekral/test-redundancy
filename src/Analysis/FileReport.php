<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

use JsonSerializable;

final readonly class FileReport implements JsonSerializable
{

    /**
     * @param list<\Pekral\TestRedundancy\Analysis\Candidate> $candidates
     * @param list<string> $testsWithoutSourceLines
     * @param array<string, string> $failedTests
     */
    public function __construct(
        public string $testFile,
        public int $testCases,
        public int $coveredLines,
        public array $candidates,
        public array $testsWithoutSourceLines,
        public bool $skipped = false,
        public array $failedTests = [],
    ) {
    }

    public static function skipped(string $testFile): self
    {
        return new self($testFile, 1, 0, [], [], skipped: true);
    }

    public function hasFindings(): bool
    {
        return $this->candidates !== [] || $this->testsWithoutSourceLines !== [] || $this->failedTests !== [];
    }

    /**
     * @return array{
     *     candidates: list<\Pekral\TestRedundancy\Analysis\Candidate>,
     *     coveredLines: int,
     *     failedTests: list<array{message: string, test: string}>,
     *     file: string,
     *     testCases: int,
     *     testsWithoutSourceLines: list<string>,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'candidates' => $this->candidates,
            'coveredLines' => $this->coveredLines,
            'failedTests' => array_map(
                static fn (string $test, string $message): array => ['message' => $message, 'test' => $test],
                array_keys($this->failedTests),
                $this->failedTests,
            ),
            'file' => $this->testFile,
            'testCases' => $this->testCases,
            'testsWithoutSourceLines' => $this->testsWithoutSourceLines,
        ];
    }

}
