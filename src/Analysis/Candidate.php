<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

use JsonSerializable;
use Pekral\TestRedundancy\Support\TestKey;

final readonly class Candidate implements JsonSerializable
{

    private const string DATA_TOKEN_PATTERN = '/^(key:)?(value|const):/';

    /**
     * @param list<string> $coveredBy
     * @param list<string>|null $differsBy
     */
    public function __construct(public string $testKey, public array $coveredBy, public ?array $differsBy)
    {
    }

    public function testCase(): string
    {
        return TestKey::testCase($this->testKey);
    }

    public function isDatasetRow(): bool
    {
        return TestKey::isDatasetRow($this->testKey);
    }

    public function addsNoValueOrCall(): bool
    {
        return $this->differsBy === [];
    }

    public function action(): Action
    {
        return match (true) {
            $this->differsBy === null => Action::Review,
            $this->differsBy === [] => Action::Delete,
            !$this->differsOnlyInData() => Action::Review,
            $this->isDatasetRow() && $this->isCoveredBySiblingRow() => Action::ReviewDatasetRow,
            !$this->isDatasetRow() && $this->isCoveredByOneTestOfTheSameFile() => Action::MergeIntoDataset,
            default => Action::Review,
        };
    }

    /**
     * @return array{action: string, coveredBy: list<string>, differsBy: list<string>|null, steps: list<string>, test: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'action' => $this->action()->value,
            'coveredBy' => $this->coveredBy,
            'differsBy' => $this->differsBy,
            'steps' => $this->steps(),
            'test' => $this->testCase(),
        ];
    }

    /**
     * @return list<string>
     */
    public function steps(): array
    {
        $coveringTests = $this->action() === Action::ReviewDatasetRow ? [(string) $this->siblingRow()] : $this->coveredBy;
        $steps = $this->action()->steps('"' . implode('", "', $coveringTests) . '"');

        return $this->differsBy === null
            ? ['This test or its dataset row could not be parsed; compare it with coveredBy by hand.', ...$steps]
            : $steps;
    }

    private function differsOnlyInData(): bool
    {
        foreach ($this->differsBy ?? [] as $token) {
            if (preg_match(self::DATA_TOKEN_PATTERN, $token) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function isCoveredByOneTestOfTheSameFile(): bool
    {
        return count($this->coveredBy) === 1 && !str_contains($this->coveredBy[0], ' :: ');
    }

    private function isCoveredBySiblingRow(): bool
    {
        return $this->siblingRow() !== null;
    }

    private function siblingRow(): ?string
    {
        $test = explode(TestKey::DATASET_SEPARATOR, $this->testCase(), 2)[0];

        foreach ($this->coveredBy as $coveringTest) {
            if (str_starts_with($coveringTest, $test . TestKey::DATASET_SEPARATOR)) {
                return $coveringTest;
            }
        }

        return null;
    }

}
