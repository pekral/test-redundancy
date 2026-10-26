<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BitwiseNot;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\NodeFinder;

final class NonLiteralArguments
{

    private const string SUFFIX = 'non-literal';

    private const string IDENTITY = '(?:variable|property):[^/]+';

    private const string CALL = '((?:call|function|new):[^/#]+)#\w+/';

    private const string POSITION = '(in:(?:.*/)?(?:call|function|new):[^/#]+#\w+/)';

    private const string POSITION_PATTERN = '~^' . self::POSITION . self::SUFFIX . '$~';

    private const string IDENTITY_POSITION_PATTERN = '~^' . self::POSITION . self::IDENTITY . '$~';

    private const string CALL_PATTERN = '~' . self::CALL . '(?:' . self::SUFFIX . '|' . self::IDENTITY . ')$~';

    private const string POSITION_CALL_PATTERN = '~' . self::CALL . '$~';

    public static function isToken(string $token): bool
    {
        return self::positionOf($token) !== null;
    }

    public static function positionOf(string $token): ?string
    {
        return preg_match(self::POSITION_PATTERN, $token, $matches) === 1 ? $matches[1] : null;
    }

    public static function argumentPositionOf(string $token): ?string
    {
        return self::positionOf($token) ?? TokenCollector::argumentPosition($token, ofAssertion: false);
    }

    /**
     * @param list<string> $tokens
     */
    public static function isPositionFilledBy(string $position, array $tokens): bool
    {
        return array_any($tokens, static fn (string $token): bool => str_starts_with($token, $position));
    }

    /**
     * @param list<string> $tokens
     */
    public static function isCallOfPositionMadeBy(string $position, array $tokens): bool
    {
        return preg_match(self::POSITION_CALL_PATTERN, $position, $matches) === 1
            && array_intersect(self::madeAs($matches[1]), $tokens) !== [];
    }

    /**
     * @return list<string>
     */
    public static function madeAs(string $call): array
    {
        return [$call, 'loop:' . $call];
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
            ($value instanceof PropertyFetch || $value instanceof NullsafePropertyFetch || $value instanceof StaticPropertyFetch)
                && $value->name instanceof Identifier => 'property:' . $value->name->toString(),
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
                [...($isAssertion || $value instanceof Array_ ? [] : [self::SUFFIX]), ...self::namesOf($value, $parameters)],
            ),
            $callToken === null ? [] : self::nonLiteralValues($call),
        ));
    }

    /**
     * @param list<string> $parameters
     * @return list<string>
     */
    private static function namesOf(Expr $value, array $parameters): array
    {
        return match (true) {
            $value instanceof BinaryOp => self::namesOfEach([$value->left, $value->right], $parameters),
            $value instanceof InterpolatedString => self::namesOfEach($value->parts, $parameters),
            $value instanceof Array_ => self::namesOfEach(
                array_merge([], ...array_map(static fn (ArrayItem $item): array => [$item->key, $item->value], $value->items)),
                $parameters,
            ),
            $value instanceof ArrayDimFetch => self::namesOfEach([$value->var, $value->dim], $parameters),
            $value instanceof Ternary => self::namesOfEach([$value->cond, $value->if, $value->else], $parameters),
            $value instanceof Cast || $value instanceof UnaryMinus || $value instanceof UnaryPlus || $value instanceof BooleanNot
                || $value instanceof BitwiseNot => self::namesOf($value->expr, $parameters),
            $value instanceof Variable && in_array($value->name, $parameters, strict: true) => [],
            NodeContext::enumCaseReadBy($value) !== null => [],
            default => array_filter([self::identity($value)], is_string(...)),
        };
    }

    /**
     * @param array<\PhpParser\Node|null> $values
     * @param list<string> $parameters
     * @return list<string>
     */
    private static function namesOfEach(array $values, array $parameters): array
    {
        return array_merge([], ...array_map(
            static fn (Expr $value): array => self::namesOf($value, $parameters),
            array_values(array_filter($values, static fn (?Node $value): bool => $value instanceof Expr)),
        ));
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

        return array_values(array_filter(
            $values,
            static fn (?Expr $value): bool => $value !== null && (self::isNonLiteral($value) || $value instanceof Array_),
        ));
    }

    private static function isNonLiteral(Expr $value): bool
    {
        return !TokenCollector::isData($value) && !$value instanceof Array_ && NodeContext::enumCaseReadBy($value) === null;
    }

}
