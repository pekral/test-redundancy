<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

use Pekral\TestRedundancy\Fingerprint\DatasetParameters;
use Pekral\TestRedundancy\Fingerprint\NonLiteralArguments;
use Pekral\TestRedundancy\Fingerprint\TokenCollector;
use Pekral\TestRedundancy\Fingerprint\TokenKind;

final readonly class CoveringSlots
{

    /**
     * @param list<string> $differsBy
     */
    public function __construct(private array $differsBy, private ?DatasetParameters $coveringDatasetParameters)
    {
    }

    public function isFilledOnlyByTheCoveringTest(string $token): bool
    {
        return (!TokenKind::isData($token) && !NonLiteralArguments::isToken($token))
            || (TokenKind::isInputKey($token) && !$this->isInValueDiffersByLists($token))
            || $this->isInArgumentPositionOnlyTheCoveringTestFills(NonLiteralArguments::argumentPositionOf($token))
            || $this->isExpectationOnlyTheCoveringTestHas($token);
    }

    private function isInValueDiffersByLists(string $key): bool
    {
        return array_any(
            $this->differsBy,
            static fn (string $token): bool => preg_match(TokenCollector::DATA_TOKEN_PATTERN, $token, $matches) === 1
                && $matches[1] !== ''
                && $matches[2] === ''
                && str_starts_with($key, $matches[1]),
        );
    }

    private function isInArgumentPositionOnlyTheCoveringTestFills(?string $position): bool
    {
        return $position !== null && !NonLiteralArguments::isPositionFilledBy($position, $this->differsBy);
    }

    private function isListOnlyTheCoveringTestExpects(string $token): bool
    {
        return str_ends_with($token, TokenCollector::LIST_ITEM)
            && !in_array(substr($token, 0, -strlen(TokenCollector::LIST_ITEM)) . 'value:[]', $this->differsBy, strict: true);
    }

    private function isExpectationOnlyTheCoveringTestHas(string $token): bool
    {
        return TokenKind::isExpectation($token)
            && $this->coveringDatasetParameters?->takes($token) !== true
            && ($this->isListOnlyTheCoveringTestExpects($token)
                || (TokenKind::isKey($token) && !$this->isInValueDiffersByLists($token))
                || $this->isInArgumentPositionOnlyTheCoveringTestFills(TokenCollector::argumentPosition($token, ofAssertion: true)));
    }

}
