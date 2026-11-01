<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;

final class TestLevelArgumentTokens
{

    /**
     * @return array<string, int>
     */
    public static function of(string $method, MethodCall $call, bool $withSource): array
    {
        $tokens = ['call:' . $method => 1];

        foreach ($call->args as $position => $argument) {
            if (!$argument instanceof Arg) {
                continue;
            }

            $prefix = 'call:' . $method . '#' . ($argument->name?->toString() ?? $position) . '/';
            $sourceToken = $withSource ? self::sourceToken($argument) : [];

            foreach (TokenCollector::sum(TokenCollector::collect($argument), $sourceToken) as $token => $count) {
                $tokens[self::prefixed($prefix, $token)] = $count;
            }
        }

        return $tokens;
    }

    private static function prefixed(string $prefix, string $token): string
    {
        return TokenCollector::isReadToken($token)
            ? TokenCollector::readToken($prefix . TokenCollector::withoutRead($token))
            : $prefix . $token;
    }

    /**
     * @return array<string, int>
     */
    private static function sourceToken(Arg $argument): array
    {
        return TokenCollector::isData($argument->value) ? [] : ['source:' . OrderTokens::sourceOf($argument->value) => 1];
    }

}
