<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

use JsonSerializable;
use Pekral\TestRedundancy\Fingerprint\DatasetParameters;
use Pekral\TestRedundancy\Fingerprint\NonLiteralArguments;
use Pekral\TestRedundancy\Fingerprint\PestFingerprints;
use Pekral\TestRedundancy\Fingerprint\SharedDataset;
use Pekral\TestRedundancy\Fingerprint\TokenCollector;
use Pekral\TestRedundancy\Fingerprint\TokenKind;
use Pekral\TestRedundancy\Support\TestKey;

final readonly class Candidate implements JsonSerializable
{

    /**
     * @param list<string> $coveredBy
     * @param list<string>|null $differsBy
     * @param list<string>|null $coveringDifference
     * @param list<string> $unionOnlyLines
     * @param list<string> $omittedByCovering
     * @param list<string> $rejectionsTheCoveringTestsLack
     */
    public function __construct(
        public string $testKey,
        public array $coveredBy,
        public ?array $differsBy,
        public ?array $coveringDifference,
        public array $unionOnlyLines,
        public bool $assertsNothing,
        public ?SharedDataset $sharedDataset = null,
        public ?DatasetParameters $coveringDatasetParameters = null,
        public array $omittedByCovering = [],
        public ?SharedDataset $coveringSharedDataset = null,
        public bool $hasUnresolvedDatasetRow = false,
        public ?float $seconds = null,
        public array $rejectionsTheCoveringTestsLack = [],
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
            $this->addsNoValueOrCall() && $this->coveringTokensForReview() === [] => $this->sharedDataset instanceof SharedDataset
                ? Action::Review
                : Action::Delete,
            $this->otherInput() instanceof InputCombination => $this->unifiesIntoOneDataset() ? Action::MergeIntoDataset : Action::Keep,
            $this->differsBy === [] => Action::Review,
            $this->differsOnlyInDataFromOneTestOfTheSameFile()
                && $this->coveringTokensForReview() === []
                && $this->coveringDatasetTakesDiffersBy()
                && $this->omittedByCovering === [] => Action::MergeIntoDataset,
            $this->rejectionsTheCoveringTestsLack !== [] => Action::Keep,
            $this->nonDataTokens($this->differsBy) !== [] => Action::Review,
            $this->isDatasetRow() && $this->siblingRow() !== null => Action::ReviewDatasetRow,
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
                new CoveringSlots($this->differsBy ?? [], $this->coveringDatasetParameters)->isFilledOnlyByTheCoveringTest(...),
            )),
            $this->differsBy !== [] => $this->coveringExpectedExceptions(),
            default => [],
        };
    }

    /**
     * @return array{
     *     action: string,
     *     coveredBy: list<string>,
     *     coveringDiffersBy: list<string>,
     *     coveringOnly: list<string>,
     *     differsBy: list<string>|null,
     *     seconds: float|null,
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
            'coveringDiffersBy' => $this->coveringDiffersBy(),
            'coveringOnly' => $this->coveringOnly(),
            'differsBy' => $this->differsBy,
            'seconds' => $this->seconds === null ? null : round($this->seconds, 3),
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
        $action = $this->action();
        $coveringInput = implode(', ', $this->coveringTokensForReview());

        return match (true) {
            $action === Action::Keep && $this->rejectionsTheCoveringTestsLack === [] => [
                sprintf(Action::OTHER_INPUT_KEEP_STEP, Action::quoted($this->coveredBy), $coveringInput),
            ],
            $action === Action::Keep => Action::Keep->steps(Action::quoted($this->coveredBy), $this->rejectionsTheCoveringTestsLack),
            $action === Action::MergeIntoDataset && $this->otherInput() instanceof InputCombination => [
                sprintf(Action::OTHER_INPUT_MERGE_STEP, Action::quoted($this->coveredBy), $coveringInput),
                'Delete this test.',
                Action::VERIFY_STEP,
            ],
            default => $this->stepsToDecide(),
        };
    }

    /**
     * @return list<string>
     */
    public function coveringDiffersBy(): array
    {
        return $this->action()->coveringDiffersBy($this->coveringTokensForReview(), $this->coveringTokens());
    }

    /**
     * @return list<string>
     */
    public function coveringOnly(): array
    {
        return $this->differsBy === [] ? $this->coveringTokens() : [];
    }

    /**
     * @return list<string>
     */
    private function stepsToDecide(): array
    {
        $sharedSteps = $this->sharedSteps();

        if ($this->unionOnlyLines !== []) {
            return [...$sharedSteps, sprintf(Action::UNION_ONLY_LINES_STEP, Action::quoted($this->coveredBy)), Action::VERIFY_STEP];
        }

        if ($this->addsNoValueOrCall() && $this->coveringTokensForReview() !== []) {
            return [...$sharedSteps, ...$this->missingInputSteps(), Action::VERIFY_STEP];
        }

        if ($this->sharedDataset instanceof SharedDataset && $this->addsNoValueOrCall()) {
            return [...$sharedSteps, sprintf(Action::CONFIRM_STEP, Action::quoted($this->coveredBy)), Action::VERIFY_STEP];
        }

        $coveringTests = $this->action() === Action::ReviewDatasetRow ? [(string) $this->siblingRow()] : $this->coveredBy;
        $steps = [
            ...$this->missingKeySteps(),
            ...$this->missingArgumentSteps(),
            ...$this->coveringTokenSteps(Action::MISSING_EXPECTATION_STEP, $this->coveringExpectations()),
            ...$this->coveringTokenSteps(Action::EXPECTED_EXCEPTION_STEP, $this->coveringExpectedExceptions()),
            ...$this->coveringTokenSteps(Action::TEST_MODIFIER_STEP, PestFingerprints::testModifierCalls($this->differsBy ?? [])),
            ...$this->coveringTokenSteps(Action::OMITTED_ARGUMENT_STEP, $this->omittedByCovering),
            ...$sharedSteps,
            ...$this->action()->steps(
                Action::quoted($coveringTests),
                $this->otherCoveringTokens(),
                TestKey::datasetOf($this->coveredBy[0] ?? ''),
                $this->coveringSharedDataset,
            ),
        ];

        return $this->differsBy === null ? [Action::unparsedStep($this->hasUnresolvedDatasetRow), ...$steps] : $steps;
    }

    /**
     * @return list<string>
     */
    private function sharedSteps(): array
    {
        return !$this->sharedDataset instanceof SharedDataset ? [] : [
            sprintf(Action::SHARED_DATASET_STEP, $this->sharedDataset->name, Action::quoted($this->sharedDataset->otherTests)),
        ];
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private function nonDataTokens(array $tokens): array
    {
        return array_values(array_filter($tokens, static fn (string $token): bool => !TokenKind::isData($token)));
    }

    /**
     * @return list<string>
     */
    private function coveringKeys(): array
    {
        return array_values(array_filter($this->coveringTokensForReview(), TokenKind::isInputKey(...)));
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
            array_diff(array_filter($this->coveringTokensForReview(), TokenKind::isArgument(...)), $this->coveringKeys()),
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
        return array_values(array_filter($this->coveringTokensForReview(), TokenKind::isExpectation(...)));
    }

    /**
     * @return list<string>
     */
    private function coveringExpectedExceptions(): array
    {
        return count($this->coveredBy) === 1 ? PestFingerprints::expectedExceptionTokens($this->coveringTokens()) : [];
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private function coveringTokenSteps(string $step, array $tokens): array
    {
        return $tokens === [] ? [] : [sprintf($step, Action::quoted($this->coveredBy), implode(', ', $tokens))];
    }

    /**
     * @return list<string>
     */
    private function missingInputSteps(): array
    {
        return [
            ...$this->missingKeySteps(),
            ...$this->missingArgumentSteps(),
            ...$this->coveringTokenSteps(Action::MISSING_EXPECTATION_STEP, $this->coveringExpectations()),
            ...$this->coveringTokenSteps(Action::EXPECTED_EXCEPTION_STEP, $this->coveringExpectedExceptions()),
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
            $this->coveringExpectedExceptions(),
        ));
    }

    /**
     * @return list<string>
     */
    private function inputOnlyTheCoveringTestPasses(): array
    {
        return array_values(
            array_filter(
                $this->assertsNothing ? $this->coveringTokens() : $this->coveringDifference ?? [],
                fn (string $token): bool => TokenKind::isInputOrKeyOfSharedAssertion($token, $this->coveringTokens())
                    && (NonLiteralArguments::identityPositionOf($token) === null || NonLiteralArguments::isCallArgument($token))
                    && ($this->assertsNothing || !TokenKind::isData($token) || TokenKind::isKey($token) || TokenKind::isArgument($token)),
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function coveringTokens(): array
    {
        return array_map(TokenCollector::withoutRead(...), $this->coveringDifference ?? []);
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
        return !$this->coveringDatasetParameters instanceof DatasetParameters
            || (array_all($this->differsBy ?? [], $this->coveringDatasetParameters->takes(...))
                && !array_any($this->coveringDifference ?? [], $this->coveringDatasetParameters->hardCodesInBodyList(...)));
    }

    private function unifiesIntoOneDataset(): bool
    {
        return !$this->isDatasetRow()
            && !$this->sharedDataset instanceof SharedDataset
            && $this->isCoveredByOneTestOfTheSameFile()
            && TestKey::datasetOf($this->coveredBy[0]) === null
            && $this->omittedByCovering === []
            && $this->otherInput()?->differsOnlyInKeysOfTheArraysIn($this->differsBy ?? []) === true;
    }

    private function otherInput(): ?InputCombination
    {
        $input = new InputCombination($this->coveringTokensForReview(), $this->coveringKeys(), $this->coveringArguments());

        return ($this->addsNoValueOrCall() || $this->differsOnlyInDataFromOneTestOfTheSameFile()) && $input->isOtherInput() ? $input : null;
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

}
