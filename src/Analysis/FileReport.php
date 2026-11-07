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
     * @param list<string> $passedOnRetry
     */
    public function __construct(
        public string $testFile,
        public int $testCases,
        public int $coveredLines,
        public array $candidates,
        public array $testsWithoutSourceLines,
        public bool $skipped = false,
        public array $failedTests = [],
        public array $passedOnRetry = [],
    ) {
    }

    public static function skipped(string $testFile): self
    {
        return new self($testFile, 1, 0, [], [], skipped: true);
    }

    public function hasFindings(): bool
    {
        return $this->candidates !== []
            || $this->testsWithoutSourceLines !== []
            || $this->failedTests !== []
            || $this->passedOnRetry !== [];
    }

    public function candidateSeconds(): float
    {
        return array_sum(array_map(static fn (Candidate $candidate): float => $candidate->seconds ?? 0.0, $this->candidates));
    }

    /**
     * @return list<\Pekral\TestRedundancy\Analysis\Candidate>
     */
    public function candidatesBySeconds(): array
    {
        $candidates = $this->candidates;
        usort(
            $candidates,
            static fn (Candidate $first, Candidate $second): int => [$second->seconds !== null, $second->seconds]
                <=> [$first->seconds !== null, $first->seconds],
        );

        return $candidates;
    }

    /**
     * @return array{
     *     candidateSeconds: float,
     *     candidates: list<\Pekral\TestRedundancy\Analysis\Candidate>,
     *     coveredLines: int,
     *     failedTests: list<array{message: string, test: string}>,
     *     file: string,
     *     passedOnRetry: list<string>,
     *     testCases: int,
     *     testsWithoutSourceLines: list<string>,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'candidates' => $this->candidatesBySeconds(),
            'candidateSeconds' => round($this->candidateSeconds(), 3),
            'coveredLines' => $this->coveredLines,
            'failedTests' => array_map(
                static fn (string $test, string $message): array => ['message' => $message, 'test' => $test],
                array_keys($this->failedTests),
                $this->failedTests,
            ),
            'file' => $this->testFile,
            'passedOnRetry' => $this->passedOnRetry,
            'testCases' => $this->testCases,
            'testsWithoutSourceLines' => $this->testsWithoutSourceLines,
        ];
    }

}
