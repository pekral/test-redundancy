<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

use JsonSerializable;
use Pekral\TestRedundancy\Fingerprint\TokenCollector;
use Pekral\TestRedundancy\Support\TestKey;

final readonly class Candidate implements JsonSerializable
{

    /**
     * @param list<string> $coveredBy
     * @param list<string>|null $differsBy
     * @param list<string>|null $coveringDiffersBy
     * @param list<string> $unionOnlyLines
     */
    public function __construct(
        public string $testKey,
        public array $coveredBy,
        public ?array $differsBy,
        public ?array $coveringDiffersBy,
        public array $unionOnlyLines,
    ) {
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
        return $this->differsBy === [] && $this->unionOnlyLines === [];
    }

    public function action(): Action
    {
        return match (true) {
            $this->differsBy === null => Action::Review,
            $this->addsNoValueOrCall() && $this->coveringTokensForReview() === [] => Action::Delete,
            $this->differsBy === [] => Action::Review,
            $this->nonDataTokens($this->differsBy) !== [] => Action::Review,
            $this->isDatasetRow() && $this->isCoveredBySiblingRow() => Action::ReviewDatasetRow,
            $this->differsOnlyInDataFromOneTestOfTheSameFile() && $this->coveringTokensForReview() === [] => Action::MergeIntoDataset,
            default => Action::Review,
        };
    }

    /**
     * @return list<string>
     */
    public function coveringTokensForReview(): array
    {
        return match (true) {
            $this->addsNoValueOrCall() => $this->keysOnlyTheCoveringTestSets(),
            $this->differsOnlyInDataFromOneTestOfTheSameFile() => $this->nonDataTokens($this->coveringDiffersBy ?? []),
            default => [],
        };
    }

    /**
     * @return array{
     *     action: string,
     *     coveredBy: list<string>,
     *     coveringDiffersBy: list<string>,
     *     differsBy: list<string>|null,
     *     steps: list<string>,
     *     test: string,
     *     unionOnlyLines: list<string>,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'action' => $this->action()->value,
            'coveredBy' => $this->coveredBy,
            'coveringDiffersBy' => $this->coveringTokensForReview(),
            'differsBy' => $this->differsBy,
            'steps' => $this->steps(),
            'test' => $this->testCase(),
            'unionOnlyLines' => $this->unionOnlyLines,
        ];
    }

    /**
     * @return list<string>
     */
    public function steps(): array
    {
        if ($this->unionOnlyLines !== []) {
            return [sprintf(Action::UNION_ONLY_LINES_STEP, $this->quoted($this->coveredBy)), Action::VERIFY_STEP];
        }

        if ($this->addsNoValueOrCall() && $this->coveringTokensForReview() !== []) {
            return [
                sprintf(Action::MISSING_KEY_STEP, $this->quoted($this->coveredBy), implode(', ', $this->coveringTokensForReview())),
                Action::VERIFY_STEP,
            ];
        }

        $coveringTests = $this->action() === Action::ReviewDatasetRow ? [(string) $this->siblingRow()] : $this->coveredBy;
        $steps = $this->action()->steps($this->quoted($coveringTests), $this->coveringTokensForReview());

        return $this->differsBy === null
            ? ['This test or its dataset row could not be parsed; compare it with coveredBy by hand.', ...$steps]
            : $steps;
    }

    /**
     * @param list<string> $testNames
     */
    private function quoted(array $testNames): string
    {
        return '"' . implode('", "', $testNames) . '"';
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private function nonDataTokens(array $tokens): array
    {
        return array_values(
            array_filter($tokens, static fn (string $token): bool => preg_match(TokenCollector::DATA_TOKEN_PATTERN, $token) !== 1),
        );
    }

    /**
     * @return list<string>
     */
    private function keysOnlyTheCoveringTestSets(): array
    {
        return array_values(
            array_filter(
                $this->coveringDiffersBy ?? [],
                static fn (string $token): bool => str_starts_with($token, TokenCollector::KEY_PREFIX),
            ),
        );
    }

    private function differsOnlyInDataFromOneTestOfTheSameFile(): bool
    {
        return $this->differsBy !== null
            && $this->differsBy !== []
            && $this->nonDataTokens($this->differsBy) === []
            && !$this->isDatasetRow()
            && $this->isCoveredByOneTestOfTheSameFile();
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
