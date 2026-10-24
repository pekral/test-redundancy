<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
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
            if ($propertyFetch->var instanceof ClassConstFetch && $propertyFetch->name instanceof Identifier) {
                $readProperties[spl_object_id($propertyFetch->var)] = '->' . $propertyFetch->name->toString();
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
                $arrayKeys[spl_object_id($arrayItem->key)] = true;
            }
        }

        return $arrayKeys;
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

}
