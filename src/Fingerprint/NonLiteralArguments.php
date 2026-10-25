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

    private const string SUFFIX = 'non-literal';

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

    public static function callOf(string $token): ?string
    {
        return preg_match(self::CALL_PATTERN, $token, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * @param list<string> $difference
     * @param list<string> $implying
     * @return list<string>
     */
    public static function withoutImplied(array $difference, array $implying): array
    {
        $calls = array_map(static fn (string $token): string => preg_replace('/^loop:/', '', $token) ?? $token, $implying);

        return array_values(array_filter(
            $difference,
            static fn (string $token): bool => !in_array(self::callOf($token), $calls, strict: true),
        ));
    }

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node> $nodes
     * @param array<int, list<string>> $paths
     * @param array<int, true> $assertionReads
     * @return array<string, int>
     */
    public static function tokens(NodeFinder $nodeFinder, Node|array $nodes, array $paths, array $assertionReads): array
    {
        $calls = $nodeFinder->findInstanceOf($nodes, CallLike::class);
        $reads = self::counts(
            array_filter($calls, static fn (CallLike $call): bool => isset($assertionReads[spl_object_id($call)])),
            $paths,
        );

        return TokenCollector::sum(
            self::counts($calls, $paths),
            array_combine(array_map(TokenCollector::readToken(...), array_keys($reads)), $reads),
        );
    }

    /**
     * @param array<\PhpParser\Node\Expr\CallLike> $calls
     * @param array<int, list<string>> $paths
     * @return array<string, int>
     */
    private static function counts(array $calls, array $paths): array
    {
        $values = array_merge([], ...array_map(self::nonLiteralValues(...), $calls));

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
