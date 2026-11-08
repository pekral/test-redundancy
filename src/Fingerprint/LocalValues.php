<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;

final class LocalValues extends NodeVisitorAbstract
{

    /**
     * @param array<string, \PhpParser\Node\Expr> $values
     */
    private function __construct(private readonly array $values)
    {
    }

    public function enterNode(Node $node): ?int
    {
        return $node instanceof Closure ? NodeVisitor::DONT_TRAVERSE_CHILDREN : null;
    }

    public function leaveNode(Node $node): ?Expr
    {
        return $node instanceof Variable && is_string($node->name) ? $this->values[$node->name] ?? null : null;
    }

    /**
     * @param array<string, \PhpParser\Node\Expr> $values
     */
    public static function substituted(Expr $expression, array $values): Expr
    {
        if ($values === []) {
            return $expression;
        }

        $substituted = new NodeTraverser(new CloningVisitor(), new self($values))->traverse([$expression])[0] ?? $expression;

        return $substituted instanceof Expr ? $substituted : $expression;
    }

}
