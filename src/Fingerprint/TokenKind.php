<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

final class TokenKind
{

    public static function isInputKey(string $token): bool
    {
        return self::isKey($token) && !TokenCollector::isAssertionToken($token);
    }

    /**
     * @param list<string> $coveringOnlyTokens
     */
    public static function isInputOrKeyOfSharedAssertion(string $token, array $coveringOnlyTokens): bool
    {
        if (!TokenCollector::isAssertionToken($token)) {
            return true;
        }

        $position = TokenCollector::argumentPosition($token, ofAssertion: true);

        return $position !== null
            && self::isKey($token)
            && !in_array((string) preg_replace('~^in:|#\w+/$~', '', $position), $coveringOnlyTokens, strict: true);
    }

    public static function isArgument(string $token): bool
    {
        return TokenCollector::argumentPosition($token, ofAssertion: false) !== null || NonLiteralArguments::isCallArgument($token);
    }

    public static function isKey(string $token): bool
    {
        return preg_match(TokenCollector::KEY_TOKEN_PATTERN, $token) === 1;
    }

    public static function isExpectation(string $token): bool
    {
        return self::isData($token) && TokenCollector::isAssertionToken($token) && !AssertionSubject::isFailureMessage($token);
    }

    public static function isData(string $token): bool
    {
        return preg_match(TokenCollector::DATA_TOKEN_PATTERN, $token) === 1
            && !NonLiteralArguments::isNameOrPosition($token)
            && !OrderTokens::isToken($token);
    }

}
