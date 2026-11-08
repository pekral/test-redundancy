<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

use Pekral\TestRedundancy\Fingerprint\NonLiteralArguments;
use Pekral\TestRedundancy\Fingerprint\TokenCollector;
use Pekral\TestRedundancy\Fingerprint\TokenKind;

final readonly class InputCombination
{

    /**
     * @param list<string> $coveringOnly the tokens only the covering test has, as coveringTokensForReview lists them
     * @param list<string> $coveringKeys
     * @param list<string> $coveringArguments
     */
    public function __construct(private array $coveringOnly, private array $coveringKeys, private array $coveringArguments)
    {
    }

    public function isOtherInput(): bool
    {
        return $this->coveringOnly !== []
            && array_diff($this->coveringOnly, $this->coveringKeys, $this->coveringArguments) === []
            && array_all($this->coveringOnly, self::isLiteral(...));
    }

    public static function isOtherLiteralInput(Candidate $candidate): bool
    {
        return $candidate->differsBy !== null
            && $candidate->differsBy !== []
            && self::holdsOnlyLiteralInput($candidate->differsBy)
            && self::holdsOnlyLiteralInput($candidate->coveringDifference)
            && $candidate->omittedByCovering === [];
    }

    /**
     * @param list<string>|null $tokens
     */
    public static function holdsOnlyLiteralInput(?array $tokens): bool
    {
        return array_all($tokens ?? [], self::isLiteralInput(...));
    }

    private static function isLiteralInput(string $token): bool
    {
        return self::isLiteral($token) && !TokenCollector::isAssertionToken($token);
    }

    private static function isLiteral(string $token): bool
    {
        return TokenKind::isData($token) && !NonLiteralArguments::isToken($token) && !str_contains($token, 'cast:');
    }

}
