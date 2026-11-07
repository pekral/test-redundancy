<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

final class RejectionAssertion
{

    private const array METHODS = [
        'assertBadRequest',
        'assertClientError',
        'assertConflict',
        'assertForbidden',
        'assertGone',
        'assertInternalServerError',
        'assertInvalid',
        'assertJsonValidationErrorFor',
        'assertJsonValidationErrors',
        'assertMethodNotAllowed',
        'assertNotAcceptable',
        'assertNotFound',
        'assertPaymentRequired',
        'assertRequestTimeout',
        'assertServerError',
        'assertServiceUnavailable',
        'assertSessionHasErrors',
        'assertSessionHasErrorsIn',
        'assertThrows',
        'assertTooManyRequests',
        'assertUnauthorized',
        'assertUnprocessable',
        'assertUnsupportedMediaType',
        'expectException',
        'expectExceptionCode',
        'expectExceptionMessage',
        'expectExceptionMessageMatches',
        'expectExceptionObject',
        'throws',
        'throwsIf',
        'throwsUnless',
        'toThrow',
    ];

    private const string CALL_PATTERN = '~(?:^|/|in:)call:(?:[^/#]*::)?(\w+)(?:#|/|$)~';

    private const string ERROR_STATUS_PATTERN = '~(?:^|/|in:)call:assertStatus#0/value:[45]\d\d$~';

    private const string DATA_PATTERN = '~[\'"].*$|(?:^|/)(?:key:)?(?:value|const):.*$~s';

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    public static function tokensOf(array $tokens): array
    {
        return array_values(array_filter(
            $tokens,
            static fn (string $token): bool => !TokenCollector::isReadToken($token) && self::isRejection($token),
        ));
    }

    private static function isRejection(string $token): bool
    {
        return preg_match(self::ERROR_STATUS_PATTERN, $token) === 1
            || (preg_match_all(self::CALL_PATTERN, (string) preg_replace(self::DATA_PATTERN, '', $token), $matches) > 0
                && array_intersect($matches[1], self::METHODS) !== []);
    }

}
