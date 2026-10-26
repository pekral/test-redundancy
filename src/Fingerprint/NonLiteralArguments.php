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
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\NodeFinder;

final class NonLiteralArguments
{

    private const string SUFFIX = 'non-literal';

    private const string ELEMENT_PREFIX = 'element:';

    private const string OPERAND_PREFIX = 'operand:';

    private const string OPERATOR_PREFIX = 'operator:';

    private const string SPREAD = 'spread';

    private const string KIND = '(?:' . self::ELEMENT_PREFIX . '|' . self::OPERAND_PREFIX . ')';

    private const string NAME = '(?:variable|property):[^/]+(?:/property:[^/]+)*';

    private const string PLACES = '(?:(?!' . self::CALL . ')[^/]+/)*';

    private const string OPERATOR = self::OPERATOR_PREFIX . '\\w+';

    private const string IDENTITY = '(?:(?:' . self::PLACES . self::KIND . '+)?' . self::NAME . '|' . self::PLACES . self::OPERATOR . ')';

    private const string KINDED_NAME_PATTERN = '~(?:^|/)\\K' . self::KIND . '*' . self::NAME . '$~';

    private const string CALL = '((?:call|function|new):[^/#]+)#\w+/';

    private const string POSITION = '(in:(?:.*/)?(?:call|function|new):[^/#]+#\w+/)';

    private const string POSITION_PATTERN = '~^' . self::POSITION . self::SUFFIX . '$~s';

    private const string IDENTITY_POSITION_PATTERN = '~^' . self::POSITION . self::IDENTITY . '$~s';

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

    public static function isNameOrPosition(string $token): bool
    {
        return self::identityPositionOf($token) !== null || self::isToken($token);
    }

    public static function callOf(string $token): ?string
    {
        return preg_match(self::CALL_PATTERN, $token, $matches) === 1 ? $matches[1] : null;
    }

    public static function identity(Expr $value): ?string
    {
        return match (true) {
            $value instanceof Variable && is_string($value->name) => 'variable:' . $value->name,
            $value instanceof PropertyFetch, $value instanceof NullsafePropertyFetch => self::propertyOf($value->var, $value->name),
            $value instanceof StaticPropertyFetch => self::propertyOf($value->class, $value->name),
            default => null,
        };
    }

    public static function isOperator(Expr $value): bool
    {
        return self::operandsOf($value) !== null;
    }

    public static function isCallArgument(string $token): bool
    {
        return self::isToken($token)
            || (str_ends_with($token, '/' . self::OPERATOR_PREFIX . self::SPREAD) && !TokenCollector::isAssertionToken($token));
    }

    public static function nameOrToken(?Expr $node): ?string
    {
        return match (true) {
            $node === null => null,
            $node instanceof Variable,
            $node instanceof PropertyFetch,
            $node instanceof NullsafePropertyFetch,
            $node instanceof StaticPropertyFetch => self::identity($node),
            default => TokenCollector::token($node),
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
     * @param list<string> $parameters
     * @return list<string>
     */
    public static function namesOf(Expr $value, array $parameters): array
    {
        $operands = self::operandsOf($value);

        return match (true) {
            $operands !== null => self::operatorNames(NodeContext::kindOf($value), $operands, $parameters),
            $value instanceof Array_ => array_merge([], ...array_map(
                static fn (ArrayItem $item): array => [
                    ...self::kindedNames(self::ELEMENT_PREFIX, [$item->key], $parameters),
                    ...self::placed(self::itemPosition($item), self::kindedNames(self::ELEMENT_PREFIX, [$item->value], $parameters)),
                ],
                $value->items,
            )),
            $value instanceof ArrayDimFetch => self::kindedNames(self::ELEMENT_PREFIX, [$value->var, $value->dim], $parameters),
            $value instanceof Cast => self::namesOf($value->expr, $parameters),
            $value instanceof Variable && in_array($value->name, $parameters, strict: true) => [],
            NodeContext::enumCaseReadBy($value) !== null => [],
            default => array_filter([self::identity($value)], is_string(...)),
        };
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
            static fn (Arg $argument): array => array_map(
                static fn (string $suffix): string => NodeContext::pathPrefix($paths, $argument->value) . $suffix,
                [
                    ...($isAssertion || !self::isNonLiteral($argument->value) ? [] : [self::SUFFIX]),
                    ...($argument->unpack
                        ? self::operatorNames(self::SPREAD, [$argument->value], $parameters)
                        : self::namesOf($argument->value, $parameters)),
                ],
            ),
            $callToken === null ? [] : self::tokenizedArguments($call),
        ));
    }

    /**
     * @param array<\PhpParser\Node|null> $values
     * @param list<string> $parameters
     * @return list<string>
     */
    private static function kindedNames(string $kind, array $values, array $parameters): array
    {
        return array_map(
            static fn (string $name): string => preg_replace_callback(
                self::KINDED_NAME_PATTERN,
                static fn (array $identity): string => str_contains($identity[0], $kind) ? $identity[0] : $kind . $identity[0],
                $name,
            ) ?? $name,
            self::namesOfEach($values, $parameters),
        );
    }

    /**
     * @return array<\PhpParser\Node|null>|null
     */
    private static function operandsOf(Expr $value): ?array
    {
        return match (true) {
            TokenCollector::isData($value) => null,
            $value instanceof BinaryOp => [$value->left, $value->right],
            $value instanceof InterpolatedString => $value->parts,
            $value instanceof Ternary => [$value->cond, $value->if, $value->else],
            $value instanceof UnaryMinus || $value instanceof UnaryPlus || $value instanceof BooleanNot
                || $value instanceof BitwiseNot => [$value->expr],
            default => null,
        };
    }

    /**
     * @param array<\PhpParser\Node|null> $operands
     * @param list<string> $parameters
     * @return list<string>
     */
    private static function operatorNames(string $kind, array $operands, array $parameters): array
    {
        $token = self::OPERATOR_PREFIX . $kind;

        return array_merge([$token], ...array_map(
            static fn (int $place, ?Node $operand): array => self::placed($token . '#' . $place, self::operandNames($operand, $parameters)),
            array_keys($operands),
            $operands,
        ));
    }

    /**
     * @param list<string> $parameters
     * @return list<string>
     */
    private static function operandNames(?Node $operand, array $parameters): array
    {
        return $operand !== null && TokenCollector::isData($operand)
            ? array_filter([TokenCollector::token($operand)], is_string(...))
            : self::kindedNames(self::OPERAND_PREFIX, [$operand], $parameters);
    }

    private static function propertyOf(Expr|Name $object, Expr|Identifier $property): ?string
    {
        return match (true) {
            !$property instanceof Identifier => null,
            $object instanceof Name => sprintf('property:%s::%s', $object->toString(), $property->toString()),
            default => implode('/', array_filter([self::identity($object), 'property:' . $property->toString()], is_string(...))),
        };
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function placed(?string $position, array $names): array
    {
        return $position === null ? $names : array_map(static fn (string $name): string => $position . '/' . $name, $names);
    }

    private static function itemPosition(ArrayItem $item): ?string
    {
        $key = $item->key;

        return $key === null
            ? TokenCollector::LIST_ITEM
            : TokenCollector::nameOf($key, NodeContext::readProperties(new NodeFinder(), $key))[1] ?? null;
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
     * @return list<\PhpParser\Node\Arg>
     */
    private static function tokenizedArguments(CallLike $call): array
    {
        return array_values(array_filter(
            $call->getRawArgs(),
            static fn (Node $argument): bool => $argument instanceof Arg
                && ($argument->unpack || self::isNonLiteral($argument->value) || $argument->value instanceof Array_),
        ));
    }

    private static function isNonLiteral(Expr $value): bool
    {
        return !TokenCollector::isData($value) && !$value instanceof Array_ && NodeContext::enumCaseReadBy($value) === null;
    }

}
