<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;

final class NodeContext
{

    private const string LIST_SEGMENT = '/list';

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node> $nodes
     * @return array<int, true>
     */
    public static function negatedScalars(NodeFinder $nodeFinder, Node|array $nodes): array
    {
        $negatedScalars = [];

        foreach ($nodeFinder->findInstanceOf($nodes, UnaryMinus::class) as $unaryMinus) {
            if ($unaryMinus->expr instanceof Scalar) {
                $negatedScalars[spl_object_id($unaryMinus->expr)] = true;
            }
        }

        return $negatedScalars;
    }

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node> $nodes
     * @return array<int, string>
     */
    public static function readProperties(NodeFinder $nodeFinder, Node|array $nodes): array
    {
        $readProperties = [];

        foreach ($nodeFinder->findInstanceOf($nodes, PropertyFetch::class) as $propertyFetch) {
            $enumCase = self::enumCaseReadBy($propertyFetch);

            if ($enumCase instanceof ClassConstFetch && $propertyFetch->name instanceof Identifier) {
                $readProperties[spl_object_id($enumCase)] = '->' . $propertyFetch->name->toString();
            }
        }

        return $readProperties;
    }

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node> $nodes
     * @return array<int, true>
     */
    public static function arrayKeys(NodeFinder $nodeFinder, Node|array $nodes): array
    {
        $arrayKeys = [];

        foreach ($nodeFinder->findInstanceOf($nodes, ArrayItem::class) as $arrayItem) {
            if ($arrayItem->key !== null) {
                $arrayKeys[spl_object_id(self::enumCaseReadBy($arrayItem->key) ?? $arrayItem->key)] = true;
            }
        }

        return $arrayKeys;
    }

    /**
     * @param array<int, list<string>> $paths
     */
    public static function pathPrefix(array $paths, Node $node): string
    {
        $path = $paths[spl_object_id($node)] ?? null;

        return $path === null ? '' : 'in:' . implode('/', $path) . '/';
    }

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node> $nodes
     * @param array<int, list<string>> $paths
     * @param array<int, string> $callArguments
     * @return array<int, list<string>>
     */
    public static function withClosureRoutes(NodeFinder $nodeFinder, Node|array $nodes, array $paths, array $callArguments): array
    {
        $passedClosures = $nodeFinder->find(
            $nodes,
            static fn (Node $node): bool => ($node instanceof Closure || $node instanceof ArrowFunction)
                && isset($callArguments[spl_object_id($node)]),
        );

        foreach ($passedClosures as $closure) {
            $body = self::childrenOf($closure, $closure instanceof ArrowFunction ? 'expr' : 'stmts');
            $routes = self::routes($body, [...$paths[spl_object_id($closure)], 'closure']);
            $paths = array_replace($paths, array_diff_key($routes, $callArguments));
        }

        return $paths;
    }

    /**
     * @param array<int, string> $readProperties
     * @return array<int, string>
     */
    public static function keySegments(NodeFinder $nodeFinder, ArrayItem $arrayItem, array $readProperties): array
    {
        $key = TokenCollector::nameOf($arrayItem->key, $readProperties);

        if ($key === null) {
            return [];
        }

        $segments = array_fill_keys(self::nodeIds($nodeFinder, $arrayItem->value), $key[1]);

        foreach ($arrayItem->value instanceof Array_ ? $arrayItem->value->items : [] as $listItem) {
            $segments = $listItem->key instanceof Expr
                ? $segments
                : array_replace($segments, array_fill_keys(self::nodeIds($nodeFinder, $listItem->value), $key[1] . self::LIST_SEGMENT));
        }

        return $segments;
    }

    public static function kindOf(Node $node): string
    {
        return strtolower(substr((string) strrchr($node->getType(), '_'), 1));
    }

    public static function enumCaseReadBy(Node $node): ?ClassConstFetch
    {
        return $node instanceof PropertyFetch && $node->var instanceof ClassConstFetch ? $node->var : null;
    }

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node> $nodes
     * @return array<int, true>
     */
    public static function loopBodies(NodeFinder $nodeFinder, Node|array $nodes): array
    {
        $loopBodies = [];

        foreach ([Do_::class, For_::class, Foreach_::class, While_::class] as $loopClass) {
            foreach ($nodeFinder->findInstanceOf($nodes, $loopClass) as $loop) {
                foreach ($nodeFinder->find($loop->stmts, static fn (): bool => true) as $node) {
                    $loopBodies[spl_object_id($node)] = true;
                }
            }
        }

        return $loopBodies;
    }

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node> $nodes
     * @return array<int, true>
     */
    public static function assertionReads(NodeFinder $nodeFinder, Node|array $nodes): array
    {
        $reads = [];

        foreach ($nodeFinder->findInstanceOf($nodes, CallLike::class) as $assertion) {
            if (!self::isAssertionCall($assertion)) {
                continue;
            }

            foreach ($assertion->getRawArgs() as $argument) {
                $reads += $argument instanceof Arg ? self::readIds($nodeFinder, $argument->value) : [];
            }
        }

        return $reads;
    }

    /**
     * @return list<int>
     */
    public static function argumentValueIds(Expr $value, bool $isAssertion, bool $isArgument, bool $isUnderKey = false): array
    {
        $innerValues = $value instanceof Cast ? [$value->expr] : self::returnedValues($value);

        if ($innerValues !== null) {
            return array_merge(self::valueIds($value), ...array_map(
                static fn (Expr $innerValue): array => self::argumentValueIds($innerValue, $isAssertion, $isArgument),
                $innerValues,
            ));
        }

        $passedItems = match (true) {
            $value instanceof Array_ => array_filter(
                $value->items,
                static fn (ArrayItem $item): bool => $item->key instanceof Expr || $isArgument || $isUnderKey,
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
                !$item->key instanceof Expr && $isUnderKey => self::argumentValueIds($item->value, $isAssertion, isArgument: false),
                !$item->key instanceof Expr => self::valueIds($item->value),
                default => [
                    ...self::valueIds($item->key),
                    ...self::argumentValueIds($item->value, $isAssertion, isArgument: false, isUnderKey: true),
                ],
            },
            array_values($passedItems),
        ));
    }

    /**
     * @return array<int>
     */
    public static function nodeIds(NodeFinder $nodeFinder, Node $node): array
    {
        return array_map(spl_object_id(...), $nodeFinder->find($node, static fn (): bool => true));
    }

    public static function isAssertionCall(CallLike $call): bool
    {
        $token = TokenCollector::token($call);

        return $token !== null && TokenCollector::isAssertionToken($token);
    }

    /**
     * @return list<\PhpParser\Node\Expr>|null
     */
    public static function returnedValues(Expr $value): ?array
    {
        return match (true) {
            $value instanceof ArrowFunction => [$value->expr],
            $value instanceof Closure => array_values(array_filter(array_map(
                static fn (Return_ $return): ?Expr => $return->expr,
                self::ownReturns(new NodeFinder(), $value->stmts),
            ))),
            default => null,
        };
    }

    /**
     * @template TChild
     * @param array<TChild> $children
     * @return array<string, TChild>
     */
    public static function listedChildren(string $name, array $children): array
    {
        return array_combine(
            array_map(static fn (int|string $index): string => $name . '#' . $index, array_keys($children)),
            $children,
        );
    }

    /**
     * @param array<\PhpParser\Node> $statements
     * @return list<\PhpParser\Node\Stmt\Return_>
     */
    private static function ownReturns(NodeFinder $nodeFinder, array $statements): array
    {
        $nestedReturns = $nodeFinder->find(
            $nodeFinder->findInstanceOf($statements, FunctionLike::class),
            static fn (Node $node): bool => $node instanceof Return_,
        );

        return array_values(array_filter(
            $nodeFinder->findInstanceOf($statements, Return_::class),
            static fn (Return_ $return): bool => !in_array($return, $nestedReturns, strict: true),
        ));
    }

    /**
     * @param array<string, \PhpParser\Node|null> $children
     * @param list<string> $route
     * @return array<int, list<string>>
     */
    private static function routes(array $children, array $route): array
    {
        $routes = [];

        foreach (array_filter($children, static fn (?Node $child): bool => $child instanceof Node) as $step => $child) {
            $childRoute = [...$route, $step];
            $routes += TokenCollector::isData($child) ? [spl_object_id($child) => $childRoute] : [];
            $routes += self::routes(
                NonLiteralArguments::operandPositions($child) ?? self::childrenOf($child, ...$child->getSubNodeNames()),
                $childRoute,
            );
        }

        return $routes;
    }

    /**
     * @return array<string, \PhpParser\Node>
     */
    private static function childrenOf(Node $node, string ...$names): array
    {
        $children = [];

        foreach (array_intersect_key(get_object_vars($node), array_flip($names)) as $name => $child) {
            $children += is_array($child) ? self::listedChildren($name, $child) : [$name => $child];
        }

        return array_filter($children, static fn (mixed $child): bool => $child instanceof Node);
    }

    /**
     * @return list<int>
     */
    private static function valueIds(Expr $value): array
    {
        $enumCase = self::enumCaseReadBy($value);

        return !$enumCase instanceof ClassConstFetch ? [spl_object_id($value)] : [spl_object_id($value), spl_object_id($enumCase)];
    }

    /**
     * @return array<int, true>
     */
    private static function readIds(NodeFinder $nodeFinder, Node $value): array
    {
        $arranged = [];

        foreach ($nodeFinder->find($value, static fn (Node $node): bool => $node instanceof Closure || $node instanceof ArrowFunction
            || ($node instanceof CallLike && !self::isAssertionCall($node))) as $node) {
            $parts = $node instanceof CallLike ? array_values($node->getRawArgs()) : [$node];

            foreach ($nodeFinder->find($parts, static fn (): bool => true) as $part) {
                $arranged[spl_object_id($part)] = true;
            }
        }

        $reads = [];

        foreach ($nodeFinder->find($value, static fn (): bool => true) as $node) {
            $reads[spl_object_id($node)] = true;
        }

        return array_diff_key($reads, $arranged);
    }

}
