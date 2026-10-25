<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\NodeFinder;

final class NonLiteralArguments
{

    private const string SUFFIX = 'non-literal';

    private const string IDENTITY = '(?:variable|property):[^/]+';

    private const string POSITION = '(in:(?:.*/)?(?:call|function|new):[^/#]+#\w+/)';

    private const string POSITION_PATTERN = '~^' . self::POSITION . self::SUFFIX . '$~';

    private const string IDENTITY_POSITION_PATTERN = '~^' . self::POSITION . self::IDENTITY . '$~';

    private const string CALL_PATTERN = '~((?:call|function|new):[^/#]+)#\w+/(?:' . self::SUFFIX . '|' . self::IDENTITY . ')$~';

    public static function isToken(string $token): bool
    {
        return self::positionOf($token) !== null;
    }

    public static function positionOf(string $token): ?string
    {
        return preg_match(self::POSITION_PATTERN, $token, $matches) === 1 ? $matches[1] : null;
    }

    public static function identityPositionOf(string $token): ?string
    {
        return preg_match(self::IDENTITY_POSITION_PATTERN, $token, $matches) === 1 ? $matches[1] : null;
    }

    public static function callOf(string $token): ?string
    {
        return preg_match(self::CALL_PATTERN, $token, $matches) === 1 ? $matches[1] : null;
    }

    public static function identity(Expr $value): ?string
    {
        return match (true) {
            $value instanceof Variable && is_string($value->name) => 'variable:' . $value->name,
            $value instanceof PropertyFetch && $value->name instanceof Identifier => 'property:' . $value->name->toString(),
            default => null,
        };
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
     * @param list<string> $parameters
     * @return array<string, int>
     */
    public static function tokens(NodeFinder $nodeFinder, Node|array $nodes, array $paths, array $assertionReads, array $parameters): array
    {
        $calls = $nodeFinder->findInstanceOf($nodes, CallLike::class);
        $reads = self::counts(
            array_filter($calls, static fn (CallLike $call): bool => isset($assertionReads[spl_object_id($call)])),
            $paths,
            $parameters,
        );

        return TokenCollector::sum(
            self::counts($calls, $paths, $parameters),
            array_combine(array_map(TokenCollector::readToken(...), array_keys($reads)), $reads),
        );
    }

    /**
     * @param array<\PhpParser\Node\Expr\CallLike> $calls
     * @param array<int, list<string>> $paths
     * @param list<string> $parameters
     * @return array<string, int>
     */
    private static function counts(array $calls, array $paths, array $parameters): array
    {
        return array_count_values(array_merge([], ...array_map(
            static fn (CallLike $call): array => self::argumentTokens($call, $paths, $parameters),
            $calls,
        )));
    }

    /**
     * @param array<int, list<string>> $paths
     * @param list<string> $parameters
     * @return list<string>
     */
    private static function argumentTokens(CallLike $call, array $paths, array $parameters): array
    {
        $callToken = TokenCollector::token($call);
        $isAssertion = $callToken !== null && TokenCollector::isAssertionToken($callToken);

        return array_merge([], ...array_map(
            static fn (Expr $value): array => array_map(
                static fn (string $suffix): string => NodeContext::pathPrefix($paths, $value) . $suffix,
                array_values(array_filter(
                    [$isAssertion ? null : self::SUFFIX, self::nameOf($value, $parameters)],
                    static fn (?string $suffix): bool => $suffix !== null,
                )),
            ),
            $callToken === null ? [] : self::nonLiteralValues($call),
        ));
    }

    /**
     * @param list<string> $parameters
     */
    private static function nameOf(Expr $value, array $parameters): ?string
    {
        $isParameter = $value instanceof Variable && in_array($value->name, $parameters, strict: true);

        return $isParameter ? null : self::identity($value);
    }

    /**
     * @return list<\PhpParser\Node\Expr>
     */
    private static function nonLiteralValues(CallLike $call): array
    {
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
