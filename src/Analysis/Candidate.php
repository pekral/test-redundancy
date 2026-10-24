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
        public bool $assertsNothing,
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
            $this->addsNoValueOrCall() => $this->inputOnlyTheCoveringTestPasses(),
            $this->differsOnlyInDataFromOneTestOfTheSameFile() => array_values(array_filter(
                $this->coveringDiffersBy ?? [],
                fn (string $token): bool => !self::isData($token)
                    || ($this->isInputKey($token) && !$this->isInValueDiffersByLists($token))
                    || $this->isInArgumentPositionOnlyTheCoveringTestFills($token),
            )),
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
            return [...$this->missingInputSteps(), Action::VERIFY_STEP];
        }

        $coveringTests = $this->action() === Action::ReviewDatasetRow ? [(string) $this->siblingRow()] : $this->coveredBy;
        $steps = [
            ...$this->missingKeySteps(),
            ...$this->missingArgumentSteps(),
            ...$this->action()->steps(
                $this->quoted($coveringTests),
                $this->otherCoveringTokens(),
            ),
        ];

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
        return array_values(array_filter($tokens, static fn (string $token): bool => !self::isData($token)));
    }

    /**
     * @return list<string>
     */
    private function coveringKeys(): array
    {
        return array_values(array_filter($this->coveringTokensForReview(), $this->isInputKey(...)));
    }

    /**
     * @return list<string>
     */
    private function missingKeySteps(): array
    {
        return $this->coveringKeys() === []
            ? []
            : [sprintf(Action::MISSING_KEY_STEP, $this->quoted($this->coveredBy), implode(', ', $this->coveringKeys()))];
    }

    /**
     * @return list<string>
     */
    private function coveringArguments(): array
    {
        return array_values(
            array_diff(array_filter($this->coveringTokensForReview(), $this->isArgument(...)), $this->coveringKeys()),
        );
    }

    /**
     * @return list<string>
     */
    private function missingArgumentSteps(): array
    {
        return $this->coveringArguments() === []
            ? []
            : [sprintf(Action::MISSING_ARGUMENT_STEP, $this->quoted($this->coveredBy), implode(', ', $this->coveringArguments()))];
    }

    /**
     * @return list<string>
     */
    private function missingInputSteps(): array
    {
        $arrangement = $this->otherCoveringTokens();

        return [
            ...$this->missingKeySteps(),
            ...$this->missingArgumentSteps(),
            ...($arrangement === [] ? [] : [
                sprintf(Action::NO_ASSERTION_STEP, $this->quoted($this->coveredBy), implode(', ', $arrangement)),
            ]),
        ];
    }

    /**
     * @return list<string>
     */
    private function otherCoveringTokens(): array
    {
        return array_values(array_diff($this->coveringTokensForReview(), $this->coveringKeys(), $this->coveringArguments()));
    }

    /**
     * @return list<string>
     */
    private function inputOnlyTheCoveringTestPasses(): array
    {
        return array_values(
            array_filter(
                $this->coveringDiffersBy ?? [],
                fn (string $token): bool => $this->assertsNothing
                    ? !TokenCollector::isAssertionToken($token)
                    : $this->isInputKey($token) || $this->isArgument($token),
            ),
        );
    }

    private function isInValueDiffersByLists(string $key): bool
    {
        return array_any(
            $this->differsBy ?? [],
            static fn (string $token): bool => preg_match(TokenCollector::DATA_TOKEN_PATTERN, $token, $matches) === 1
                && $matches[1] !== ''
                && $matches[2] === ''
                && str_starts_with($key, $matches[1]),
        );
    }

    private function isInArgumentPositionOnlyTheCoveringTestFills(string $token): bool
    {
        $position = TokenCollector::argumentPositionOfCodeUnderTest($token);

        return $position !== null && !array_any($this->differsBy ?? [], static fn (string $own): bool => str_starts_with($own, $position));
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

    private function isInputKey(string $token): bool
    {
        return preg_match(TokenCollector::KEY_TOKEN_PATTERN, $token) === 1 && !TokenCollector::isAssertionToken($token);
    }

    private function isArgument(string $token): bool
    {
        return TokenCollector::argumentPositionOfCodeUnderTest($token) !== null;
    }

    private static function isData(string $token): bool
    {
        return preg_match(TokenCollector::DATA_TOKEN_PATTERN, $token) === 1;
    }

}
