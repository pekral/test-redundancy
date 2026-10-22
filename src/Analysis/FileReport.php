<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

use JsonSerializable;

final readonly class FileReport implements JsonSerializable
{

    /**
     * @param list<\Pekral\TestRedundancy\Analysis\Candidate> $candidates
     * @param list<string> $testsWithoutSourceLines
     */
    public function __construct(
        public string $testFile,
        public int $testCases,
        public int $coveredLines,
        public array $candidates,
        public array $testsWithoutSourceLines,
    ) {
    }

    public function hasFindings(): bool
    {
        return $this->candidates !== [] || $this->testsWithoutSourceLines !== [];
    }

    /**
     * @return array{
     *     candidates: list<\Pekral\TestRedundancy\Analysis\Candidate>,
     *     coveredLines: int,
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
            'file' => $this->testFile,
            'testCases' => $this->testCases,
            'testsWithoutSourceLines' => $this->testsWithoutSourceLines,
        ];
    }

}
