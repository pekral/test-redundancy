<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

use JsonSerializable;
use Pekral\TestRedundancy\Support\TestKey;

final readonly class Candidate implements JsonSerializable
{

    private const DATA_TOKEN_PATTERN = '/^(value|const):/';

    private const UNPARSED_REASON = 'Runs only lines other tests run, but the test or its dataset row could not be parsed, '
        . 'so what it asserts is unknown. Compare it with "coveredBy" by hand.';

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
     * @return array{test: string, action: string, reason: string, coveredBy: list<string>, differsBy: list<string>|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'action' => $this->action()->value,
            'coveredBy' => $this->coveredBy,
            'differsBy' => $this->differsBy,
            'reason' => $this->differsBy === null ? self::UNPARSED_REASON : $this->action()->instruction(),
            'test' => $this->testCase(),
        ];
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
        $test = explode(TestKey::DATASET_SEPARATOR, $this->testCase(), 2)[0];

        foreach ($this->coveredBy as $coveringTest) {
            if (str_starts_with($coveringTest, $test . TestKey::DATASET_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

}
