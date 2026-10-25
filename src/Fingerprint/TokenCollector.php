<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use Pest\Expectation;
use Pest\Mixins\Expectation as ExpectationMixin;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\NodeFinder;

final class TokenCollector
{

    public const string DATA_TOKEN_PATTERN = '/^(in:.*\/)?(key:)?(value|const):/';

    public const string KEY_TOKEN_PATTERN = '/^(in:.*\/)?key:/';

    private const string KEY_PREFIX = 'key:';

    private const string BOUND_PREFIX = 'bound:';

    private const string ARGUMENT_PATTERN = '~^(in:(?:.*?/)?(?:not:)?(?:call|function|new):(?:[^/#]*::)?([^/#:]+)#\w+/)'
        . '(?:key:)?(?:value|const):~';

    private const string ARGUMENT_POSITION_PATTERN = '~^in:.*(?:call|function|new):(?:[^/#]*::)?[^/#:]+#\w+/(?=(?:value|const):)~';

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node>|null $nodes
     * @param array<string, \PhpParser\Node\Expr> $assignedValues
     * @return array<string, int>
     */
    public static function collect(null|Node|array $nodes, array $assignedValues = []): array
    {
        if ($nodes === null) {
            return [];
        }

        $nodeFinder = new NodeFinder();
        $negatedScalars = NodeContext::negatedScalars($nodeFinder, $nodes);
        $readProperties = NodeContext::readProperties($nodeFinder, $nodes);
        $arrayKeys = NodeContext::arrayKeys($nodeFinder, $nodes);
        $paths = self::paths($nodeFinder, $nodes, $assignedValues, $readProperties);
        $loopBodies = NodeContext::loopBodies($nodeFinder, $nodes);
        $tokens = [];
        $carriesToken = static fn (Node $node): bool => self::carriesContract($node) || self::listLength($node) > 0;

        foreach ($nodeFinder->find($nodes, $carriesToken) as $node) {
            $token = isset($negatedScalars[spl_object_id($node)]) ? null : self::tokenWithSubject($node, $assignedValues);

            if ($token === null) {
                continue;
            }

            $token .= $readProperties[spl_object_id($node)] ?? '';
            $prefixedToken = self::prefix($arrayKeys, $paths, $node, $token) . $token;
            $prefixedToken = self::loopPrefix($loopBodies, $node, $prefixedToken) . $prefixedToken;
            $tokens[$prefixedToken] = ($tokens[$prefixedToken] ?? 0) + max(1, self::listLength($node));
        }

        return self::sum(
            $tokens,
            KeyOrders::tokens($nodeFinder, $nodes, $paths, $readProperties),
            self::boundAssertions($nodeFinder, $nodes, $assignedValues),
        );
    }

    public static function isData(Node $node): bool
    {
        $token = self::carriesContract($node) ? self::token($node) : null;

        return $token !== null && preg_match(self::DATA_TOKEN_PATTERN, $token) === 1;
    }

    public static function argumentPositionOfCodeUnderTest(string $token): ?string
    {
        return preg_match(self::ARGUMENT_PATTERN, $token, $matches) === 1 && !self::isAssertion($matches[2])
            && preg_match(self::ARGUMENT_POSITION_PATTERN, $token, $position) === 1 ? $position[0] : null;
    }

    public static function isAssertionToken(string $token): bool
    {
        return match (true) {
            str_starts_with($token, self::BOUND_PREFIX) => true,
            preg_match(self::DATA_TOKEN_PATTERN, $token) === 1 => preg_match(self::ARGUMENT_PATTERN, $token, $matches) === 1
                && self::isAssertion($matches[2]),
            default => self::isAssertion(self::calledMethod($token)),
        };
    }

    public static function token(Node $node): ?string
    {
        return match (true) {
            $node instanceof Scalar => self::scalar($node, ''),
            $node instanceof UnaryMinus => $node->expr instanceof Scalar ? self::scalar($node->expr, '-') : null,
            $node instanceof ConstFetch => 'value:' . $node->name->toLowerString(),
            $node instanceof ClassConstFetch => $node->class instanceof Name && $node->name instanceof Identifier
                ? sprintf('const:%s::%s', $node->class->toString(), $node->name->toString())
                : null,
            $node instanceof MethodCall, $node instanceof PropertyFetch => $node->name instanceof Identifier
                ? self::negation($node->var) . 'call:' . $node->name->toString()
                : null,
            $node instanceof StaticCall => $node->class instanceof Name && $node->name instanceof Identifier
                ? sprintf('call:%s::%s', $node->class->toString(), $node->name->toString())
                : null,
            $node instanceof FuncCall => $node->name instanceof Name ? 'function:' . $node->name->toString() : null,
            $node instanceof New_ => $node->class instanceof Name ? 'new:' . $node->class->toString() : null,
            $node instanceof Array_ => self::arrayToken($node),
            default => null,
        };
    }

    /**
     * @param array<int, string> $readProperties
     * @return array{0: \PhpParser\Node, 1: string}|null
     */
    public static function keyOf(ArrayItem $arrayItem, array $readProperties): ?array
    {
        $key = $arrayItem->key === null ? null : NodeContext::enumCaseReadBy($arrayItem->key) ?? $arrayItem->key;
        $keyToken = $key === null ? null : self::token($key);

        return $key === null || $keyToken === null ? null : [$key, $keyToken . ($readProperties[spl_object_id($key)] ?? '')];
    }

    /**
     * @param array<string, int> ...$fingerprints
     * @return array<string, int>
     */
    public static function sum(array ...$fingerprints): array
    {
        $sum = [];

        foreach ($fingerprints as $fingerprint) {
            foreach ($fingerprint as $token => $count) {
                $sum[$token] = ($sum[$token] ?? 0) + $count;
            }
        }

        return $sum;
    }

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node> $nodes
     * @param array<string, \PhpParser\Node\Expr> $assignedValues
     * @param array<int, string> $readProperties
     * @return array<int, list<string>>
     */
    private static function paths(NodeFinder $nodeFinder, Node|array $nodes, array $assignedValues, array $readProperties): array
    {
        $paths = [];
        $pathNodes = $nodeFinder->find($nodes, static fn (Node $node): bool => $node instanceof ArrayItem || $node instanceof CallLike);

        foreach ($pathNodes as $pathNode) {
            $segments = $pathNode instanceof ArrayItem
                ? self::keySegments($nodeFinder, $pathNode, $readProperties)
                : self::argumentSegments($pathNode, $assignedValues);

            foreach ($segments as $nodeId => $segment) {
                $paths[$nodeId][] = $segment;
            }
        }

        return $paths;
    }

    /**
     * @param array<int, string> $readProperties
     * @return array<int, string>
     */
    private static function keySegments(NodeFinder $nodeFinder, ArrayItem $arrayItem, array $readProperties): array
    {
        $key = self::keyOf($arrayItem, $readProperties);

        return $key === null ? [] : array_fill_keys(self::nodeIds($nodeFinder, $arrayItem->value), $key[1]);
    }

    /**
     * @param array<string, \PhpParser\Node\Expr> $assignedValues
     * @return array<int, string>
     */
    private static function argumentSegments(Node $call, array $assignedValues): array
    {
        $callToken = self::tokenWithSubject($call, $assignedValues);
        $arguments = $call instanceof CallLike ? $call->getRawArgs() : [];
        $isAssertion = $callToken !== null && self::isAssertion(self::calledMethod($callToken));
        $segments = [];

        foreach ($callToken === null ? [] : $arguments as $position => $argument) {
            if ($argument instanceof Arg) {
                $segment = $callToken . '#' . ($argument->name?->toString() ?? $position);
                $segments += array_fill_keys(self::argumentValueIds($argument->value, $isAssertion, isArgument: true), $segment);
            }
        }

        return $segments;
    }

    /**
     * @return list<int>
     */
    private static function argumentValueIds(Expr $value, bool $isAssertion, bool $isArgument): array
    {
        $passedItems = match (true) {
            $value instanceof Array_ => array_filter(
                $value->items,
                static fn (ArrayItem $item): bool => $item->key !== null || $isArgument,
            ),
            $value instanceof CallLike && !$isAssertion => array_filter(
                $value->getRawArgs(),
                static fn (Node $argument): bool => $argument instanceof Arg,
            ),
            default => [],
        };

        return array_merge(self::valueIds($value), ...array_map(
            static fn (ArrayItem|Arg $item): array => match (true) {
                $item instanceof Arg => self::argumentValueIds($item->value, $isAssertion, isArgument: true),
                $item->key === null => self::valueIds($item->value),
                default => [...self::valueIds($item->key), ...self::argumentValueIds($item->value, $isAssertion, isArgument: false)],
            },
            array_values($passedItems),
        ));
    }

    /**
     * @return list<int>
     */
    private static function valueIds(Expr $value): array
    {
        $enumCase = NodeContext::enumCaseReadBy($value);

        return $enumCase === null ? [spl_object_id($value)] : [spl_object_id($value), spl_object_id($enumCase)];
    }

    /**
     * @return array<int>
     */
    private static function nodeIds(NodeFinder $nodeFinder, Node $node): array
    {
        return array_map(spl_object_id(...), $nodeFinder->find($node, static fn (): bool => true));
    }

    private static function carriesContract(Node $node): bool
    {
        return $node instanceof Scalar
            || $node instanceof UnaryMinus
            || $node instanceof ConstFetch
            || $node instanceof ClassConstFetch
            || ($node instanceof MethodCall && !self::isNegation($node))
            || ($node instanceof PropertyFetch && self::callsExpectationMethod($node))
            || $node instanceof StaticCall
            || $node instanceof FuncCall
            || $node instanceof New_
            || ($node instanceof Array_ && $node->items === []);
    }

    private static function listLength(Node $node): int
    {
        $isListItem = static fn (ArrayItem $item): bool => $item->key === null && !$item->unpack;

        return $node instanceof Array_ && array_all($node->items, $isListItem) ? count($node->items) : 0;
    }

    private static function arrayToken(Array_ $array): string
    {
        return self::listLength($array) > 0 ? 'value:list-item' : 'value:[]';
    }

    /**
     * @param array<string, \PhpParser\Node\Expr> $assignedValues
     */
    private static function tokenWithSubject(Node $node, array $assignedValues): ?string
    {
        $token = self::token($node);
        $subject = $token === null ? null : AssertionSubject::of($node, self::calledMethod($token), $assignedValues);

        return $token === null ? null : ($subject === null ? '' : 'expect:' . AssertionSubject::token($subject) . '/') . $token;
    }

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node> $nodes
     * @param array<string, \PhpParser\Node\Expr> $assignedValues
     * @return array<string, int>
     */
    private static function boundAssertions(NodeFinder $nodeFinder, Node|array $nodes, array $assignedValues): array
    {
        $assertionsBySubject = [];

        foreach ($nodeFinder->find($nodes, self::carriesContract(...)) as $node) {
            $token = self::token($node);
            $subject = $token === null ? null : AssertionSubject::of($node, self::calledMethod($token), $assignedValues);

            if ($token === null || $subject === null) {
                continue;
            }

            $boundSubject = (string) AssertionSubject::token($subject, withLiteralArguments: true);
            $assertionsBySubject[(string) AssertionSubject::token($subject)][] = [
                $boundSubject,
                self::BOUND_PREFIX . 'expect:' . $boundSubject . '/' . $token
                    . AssertionSubject::literalArguments($node, self::calledMethod($token)),
            ];
        }

        return self::sum(...array_map(self::boundTokens(...), array_values($assertionsBySubject)));
    }

    /**
     * @param list<array{string, string}> $assertions
     * @return array<string, int>
     */
    private static function boundTokens(array $assertions): array
    {
        return count(array_unique(array_column($assertions, 0))) > 1 ? array_count_values(array_column($assertions, 1)) : [];
    }

    /**
     * @param array<int, true> $arrayKeys
     * @param array<int, list<string>> $paths
     */
    private static function prefix(array $arrayKeys, array $paths, Node $node, string $token): string
    {
        $pathPrefix = preg_match(self::DATA_TOKEN_PATTERN, $token) === 1 ? NodeContext::pathPrefix($paths, $node) : '';

        return $pathPrefix . (isset($arrayKeys[spl_object_id($node)]) ? self::KEY_PREFIX : '');
    }

    /**
     * @param array<int, true> $loopBodies
     */
    private static function loopPrefix(array $loopBodies, Node $node, string $token): string
    {
        return isset($loopBodies[spl_object_id($node)]) && preg_match(self::DATA_TOKEN_PATTERN, $token) !== 1 ? 'loop:' : '';
    }

    private static function negation(Expr $receiver): string
    {
        return self::isNegation($receiver) ? 'not:' : '';
    }

    private static function callsExpectationMethod(PropertyFetch $propertyFetch): bool
    {
        return !self::isNegation($propertyFetch)
            && $propertyFetch->name instanceof Identifier
            && self::isExpectationMethod($propertyFetch->name->toString())
            && AssertionSubject::isExpectationChain($propertyFetch->var);
    }

    private static function calledMethod(string $callToken): string
    {
        return substr($callToken, (int) strrpos($callToken, ':') + 1);
    }

    private static function isAssertion(string $method): bool
    {
        return str_starts_with($method, 'assert') || str_starts_with($method, 'expect') || self::isExpectationMethod($method);
    }

    private static function isExpectationMethod(string $method): bool
    {
        return method_exists(Expectation::class, $method) || method_exists(ExpectationMixin::class, $method);
    }

    private static function isNegation(Expr $node): bool
    {
        return ($node instanceof PropertyFetch || $node instanceof MethodCall)
            && $node->name instanceof Identifier
            && $node->name->toString() === 'not';
    }

    private static function scalar(Scalar $scalar, string $sign): ?string
    {
        $value = property_exists($scalar, 'value') ? $scalar->value : null;

        return is_scalar($value) ? 'value:' . $sign . var_export($value, return: true) : null;
    }

}
