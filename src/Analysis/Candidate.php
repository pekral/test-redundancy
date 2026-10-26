<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

use JsonSerializable;
use Pekral\TestRedundancy\Fingerprint\AssertionSubject;
use Pekral\TestRedundancy\Fingerprint\DatasetParameters;
use Pekral\TestRedundancy\Fingerprint\NonLiteralArguments;
use Pekral\TestRedundancy\Fingerprint\SharedDataset;
use Pekral\TestRedundancy\Fingerprint\TokenCollector;
use Pekral\TestRedundancy\Support\TestKey;

final readonly class Candidate implements JsonSerializable
{

    /**
     * @param list<string> $coveredBy
     * @param list<string>|null $differsBy
     * @param list<string>|null $coveringDiffersBy
     * @param list<string> $unionOnlyLines
     * @param list<string> $omittedByCovering
     */
    public function __construct(
        public string $testKey,
        public array $coveredBy,
        public ?array $differsBy,
        public ?array $coveringDiffersBy,
        public array $unionOnlyLines,
        public bool $assertsNothing,
        public ?SharedDataset $sharedDataset = null,
        public ?DatasetParameters $coveringDatasetParameters = null,
        public array $omittedByCovering = [],
        public ?SharedDataset $coveringSharedDataset = null,
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
            $this->addsNoValueOrCall() && $this->coveringTokensForReview() === [] => $this->deleteOrReviewSharedRow(),
            $this->differsBy === [] => Action::Review,
            $this->nonDataTokens($this->differsBy) !== [] => Action::Review,
            $this->isDatasetRow() && $this->siblingRow() !== null => Action::ReviewDatasetRow,
            $this->differsOnlyInDataFromOneTestOfTheSameFile()
                && $this->coveringTokensForReview() === []
                && $this->coveringDatasetTakesDiffersBy()
                && $this->omittedByCovering === [] => Action::MergeIntoDataset,
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
                $this->coveringTokens(),
                fn (string $token): bool => (!self::isData($token) && !NonLiteralArguments::isToken($token))
                    || ($this->isInputKey($token) && !$this->isInValueDiffersByLists($token))
                    || $this->isInArgumentPositionOnlyTheCoveringTestFills(NonLiteralArguments::argumentPositionOf($token))
                    || $this->isExpectationOnlyTheCoveringTestHas($token),
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
        $sharedSteps = $this->sharedDataset === null ? [] : [
            sprintf(Action::SHARED_DATASET_STEP, $this->sharedDataset->name, $this->sharedDataset->quotedOtherTests()),
        ];

        if ($this->unionOnlyLines !== []) {
            return [...$sharedSteps, sprintf(Action::UNION_ONLY_LINES_STEP, $this->quoted($this->coveredBy)), Action::VERIFY_STEP];
        }

        if ($this->addsNoValueOrCall() && $this->coveringTokensForReview() !== []) {
            return [...$sharedSteps, ...$this->missingInputSteps(), Action::VERIFY_STEP];
        }

        if ($this->sharedDataset !== null && $this->addsNoValueOrCall()) {
            return [...$sharedSteps, sprintf(Action::CONFIRM_STEP, $this->quoted($this->coveredBy)), Action::VERIFY_STEP];
        }

        $coveringTests = $this->action() === Action::ReviewDatasetRow ? [(string) $this->siblingRow()] : $this->coveredBy;
        $steps = [
            ...$this->missingKeySteps(),
            ...$this->missingArgumentSteps(),
            ...$this->missingExpectationSteps(),
            ...$this->coveringTokenSteps(Action::OMITTED_ARGUMENT_STEP, $this->omittedByCovering),
            ...$sharedSteps,
            ...$this->action()->steps(
                $this->quoted($coveringTests),
                $this->otherCoveringTokens(),
                TestKey::datasetOf($this->coveredBy[0] ?? ''),
                $this->coveringSharedDataset,
            ),
        ];

        return $this->differsBy === null
            ? ['This test or its dataset row could not be parsed; compare it with coveredBy by hand.', ...$steps]
            : $steps;
    }

    private function deleteOrReviewSharedRow(): Action
    {
        return $this->sharedDataset === null ? Action::Delete : Action::Review;
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
        return $this->coveringTokenSteps(Action::MISSING_KEY_STEP, $this->coveringKeys());
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
        return $this->coveringTokenSteps(Action::MISSING_ARGUMENT_STEP, $this->coveringArguments());
    }

    /**
     * @return list<string>
     */
    private function coveringExpectations(): array
    {
        return array_values(array_filter($this->coveringTokensForReview(), $this->isExpectation(...)));
    }

    /**
     * @return list<string>
     */
    private function missingExpectationSteps(): array
    {
        return $this->coveringTokenSteps(Action::MISSING_EXPECTATION_STEP, $this->coveringExpectations());
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private function coveringTokenSteps(string $step, array $tokens): array
    {
        return $tokens === [] ? [] : [sprintf($step, $this->quoted($this->coveredBy), implode(', ', $tokens))];
    }

    /**
     * @return list<string>
     */
    private function missingInputSteps(): array
    {
        return [
            ...$this->missingKeySteps(),
            ...$this->missingArgumentSteps(),
            ...$this->coveringTokenSteps(
                $this->assertsNothing ? Action::NO_ASSERTION_STEP : Action::MISSING_CALL_STEP,
                $this->otherCoveringTokens(),
            ),
        ];
    }

    /**
     * @return list<string>
     */
    private function otherCoveringTokens(): array
    {
        return array_values(array_diff(
            $this->coveringTokensForReview(),
            $this->coveringKeys(),
            $this->coveringArguments(),
            $this->coveringExpectations(),
        ));
    }

    /**
     * @return list<string>
     */
    private function inputOnlyTheCoveringTestPasses(): array
    {
        return array_values(
            array_filter(
                $this->assertsNothing ? $this->coveringTokens() : $this->coveringDiffersBy ?? [],
                fn (string $token): bool => !TokenCollector::isAssertionToken($token)
                    && (NonLiteralArguments::identityPositionOf($token) === null || NonLiteralArguments::isCallArgument($token))
                    && ($this->assertsNothing || !self::isData($token) || $this->isKey($token) || $this->isArgument($token)),
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function coveringTokens(): array
    {
        return array_map(TokenCollector::withoutRead(...), $this->coveringDiffersBy ?? []);
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

    private function isInArgumentPositionOnlyTheCoveringTestFills(?string $position): bool
    {
        return $position !== null && !NonLiteralArguments::isPositionFilledBy($position, $this->differsBy ?? []);
    }

    private function isListOnlyTheCoveringTestExpects(string $token): bool
    {
        return str_ends_with($token, TokenCollector::LIST_ITEM)
            && !in_array(substr($token, 0, -strlen(TokenCollector::LIST_ITEM)) . 'value:[]', $this->differsBy ?? [], strict: true);
    }

    private function isExpectationOnlyTheCoveringTestHas(string $token): bool
    {
        return $this->isExpectation($token)
            && $this->coveringDatasetParameters?->takes($token) !== true
            && ($this->isListOnlyTheCoveringTestExpects($token)
                || ($this->isKey($token) && !$this->isInValueDiffersByLists($token))
                || $this->isInArgumentPositionOnlyTheCoveringTestFills(TokenCollector::argumentPosition($token, ofAssertion: true)));
    }

    private function differsOnlyInDataFromOneTestOfTheSameFile(): bool
    {
        return $this->differsBy !== null
            && $this->differsBy !== []
            && $this->nonDataTokens($this->differsBy) === []
            && !$this->isDatasetRow()
            && $this->isCoveredByOneTestOfTheSameFile();
    }

    private function coveringDatasetTakesDiffersBy(): bool
    {
        return $this->coveringDatasetParameters === null
            || (array_all($this->differsBy ?? [], $this->coveringDatasetParameters->takes(...))
                && !array_any($this->coveringDiffersBy ?? [], $this->coveringDatasetParameters->hardCodesInBodyList(...)));
    }

    private function isCoveredByOneTestOfTheSameFile(): bool
    {
        return count($this->coveredBy) === 1 && !str_contains($this->coveredBy[0], ' :: ');
    }

    private function siblingRow(): ?string
    {
        $test = TestKey::withoutDatasetRow($this->testCase());

        foreach ($this->coveredBy as $coveringTest) {
            if (str_starts_with($coveringTest, $test . TestKey::DATASET_SEPARATOR)) {
                return $coveringTest;
            }
        }

        return null;
    }

    private function isInputKey(string $token): bool
    {
        return $this->isKey($token) && !TokenCollector::isAssertionToken($token);
    }

    private function isArgument(string $token): bool
    {
        return TokenCollector::argumentPosition($token, ofAssertion: false) !== null || NonLiteralArguments::isCallArgument($token);
    }

    private function isKey(string $token): bool
    {
        return preg_match(TokenCollector::KEY_TOKEN_PATTERN, $token) === 1;
    }

    private function isExpectation(string $token): bool
    {
        return self::isData($token) && TokenCollector::isAssertionToken($token) && !AssertionSubject::isFailureMessage($token);
    }

    private static function isData(string $token): bool
    {
        return preg_match(TokenCollector::DATA_TOKEN_PATTERN, $token) === 1 && !NonLiteralArguments::isNameOrPosition($token);
    }

}
