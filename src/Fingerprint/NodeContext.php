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
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;

final class NodeContext
{

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

            if ($enumCase !== null && $propertyFetch->name instanceof Identifier) {
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
    public static function argumentValueIds(Expr $value, bool $isAssertion, bool $isArgument): array
    {
        if ($value instanceof Cast) {
            return [...self::valueIds($value), ...self::argumentValueIds($value->expr, $isAssertion, $isArgument)];
        }

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
     * @return list<int>
     */
    private static function valueIds(Expr $value): array
    {
        $enumCase = self::enumCaseReadBy($value);

        return $enumCase === null ? [spl_object_id($value)] : [spl_object_id($value), spl_object_id($enumCase)];
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
