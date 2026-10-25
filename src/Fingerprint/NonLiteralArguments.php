<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\NodeFinder;

final class NonLiteralArguments
{

    public const string SUFFIX = 'non-literal';

    private const string POSITION_PATTERN = '~^(in:(?:.*/)?(?:call|function|new):[^/#]+#\w+/)' . self::SUFFIX . '$~';

    private const string CALL_PATTERN = '~((?:call|function|new):[^/#]+)#\w+/' . self::SUFFIX . '$~';

    public static function isToken(string $token): bool
    {
        return self::positionOf($token) !== null;
    }

    public static function positionOf(string $token): ?string
    {
        return preg_match(self::POSITION_PATTERN, $token, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * @param list<string> $difference
     * @return list<string>
     */
    public static function withoutImplied(array $difference): array
    {
        $calls = array_map(static fn (string $token): string => preg_replace('/^loop:/', '', $token) ?? $token, $difference);

        return array_values(array_filter(
            $difference,
            static fn (string $token): bool => preg_match(self::CALL_PATTERN, $token, $matches) !== 1
                || !in_array($matches[1], $calls, strict: true),
        ));
    }

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node> $nodes
     * @param array<int, list<string>> $paths
     * @return array<string, int>
     */
    public static function tokens(NodeFinder $nodeFinder, Node|array $nodes, array $paths): array
    {
        $values = array_merge([], ...array_map(self::nonLiteralValues(...), $nodeFinder->findInstanceOf($nodes, CallLike::class)));

        return array_count_values(array_map(
            static fn (Expr $value): string => NodeContext::pathPrefix($paths, $value) . self::SUFFIX,
            $values,
        ));
    }

    /**
     * @return list<\PhpParser\Node\Expr>
     */
    private static function nonLiteralValues(CallLike $call): array
    {
        $callToken = TokenCollector::token($call);

        if ($callToken === null || TokenCollector::isAssertionToken($callToken)) {
            return [];
        }

        $values = array_map(
            static fn (Node $argument): ?Expr => $argument instanceof Arg ? $argument->value : null,
            $call->getRawArgs(),
        );

        return array_values(array_filter($values, static fn (?Expr $value): bool => $value !== null && self::isNonLiteral($value)));
    }

    private static function isNonLiteral(Expr $value): bool
    {
        return !TokenCollector::isData($value) && !$value instanceof Array_ && NodeContext::enumCaseReadBy($value) === null;
    }

}
