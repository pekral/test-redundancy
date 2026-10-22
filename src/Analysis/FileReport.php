<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

use JsonSerializable;

final readonly class FileReport implements JsonSerializable
{

    /**
     * @param list<\Pekral\TestRedundancy\Analysis\Candidate> $candidates
     */
    public function __construct(public string $testFile, public int $testCases, public int $coveredLines, public array $candidates)
    {
    }

    /**
     * @return array{file: string, testCases: int, coveredLines: int, candidates: list<\Pekral\TestRedundancy\Analysis\Candidate>}
     */
    public function jsonSerialize(): array
    {
        return [
            'candidates' => $this->candidates,
            'coveredLines' => $this->coveredLines,
            'file' => $this->testFile,
            'testCases' => $this->testCases,
        ];
    }

}
