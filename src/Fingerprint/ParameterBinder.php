<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\StaticVar;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;

final class ParameterBinder extends NodeVisitorAbstract
{

    /** @var array<string, true> */
    private array $boundNames = [];

    /** @var array<int, true> */
    private array $declarations = [];

    /**
     * @param array<string, \PhpParser\Node\Expr> $arguments
     * @param array<\PhpParser\Node\Expr\Assign> $dropped
     * @param array<string, \PhpParser\Node\Expr\Variable> $boundReads
     */
    public function __construct(private readonly array $arguments, private readonly array $dropped, private readonly array $boundReads = [])
    {
    }

    /**
     * @param array<\PhpParser\Node> $body
     * @return array<\PhpParser\Node>
     */
    public function bind(array $body): array
    {
        return new NodeTraverser($this)->traverse($this->copy($body));
    }

    /**
     * @return array<string, true>
     */
    public function boundNames(): array
    {
        return $this->boundNames;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Expression && in_array($node->expr->getAttribute('origNode'), $this->dropped, strict: true)) {
            return NodeVisitor::REMOVE_NODE;
        }

        $declaredVariable = $node instanceof Param || $node instanceof ClosureUse || $node instanceof StaticVar || $node instanceof Catch_
            ? $node->var
            : null;

        if ($declaredVariable !== null) {
            $this->declarations[spl_object_id($declaredVariable)] = true;
        }

        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        $name = $node instanceof Variable && is_string($node->name) ? $node->name : null;
        $argument = $name === null ? null : $this->arguments[$name] ?? null;

        if ($name === null || $argument === null || isset($this->declarations[spl_object_id($node)]) || !$this->bindsRead($name, $node)) {
            return null;
        }

        $this->boundNames[$name] = true;

        return $this->bind([$argument])[0];
    }

    private function bindsRead(string $name, Node $read): bool
    {
        return !isset($this->boundReads[$name]) || $read->getAttribute('origNode') === $this->boundReads[$name];
    }

    /**
     * @param array<\PhpParser\Node> $nodes
     * @return array<\PhpParser\Node>
     */
    private function copy(array $nodes): array
    {
        return new NodeTraverser(new CloningVisitor())->traverse($nodes);
    }

}
