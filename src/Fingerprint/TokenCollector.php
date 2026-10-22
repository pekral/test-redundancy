<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node;
use PhpParser\Node\Expr;
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

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node>|null $nodes
     * @return array<string, true>
     */
    public static function collect(null|Node|array $nodes): array
    {
        if ($nodes === null) {
            return [];
        }

        $nodeFinder = new NodeFinder();
        $negatedScalars = self::negatedScalars($nodeFinder, $nodes);
        $tokens = [];

        foreach ($nodeFinder->find($nodes, self::carriesContract(...)) as $node) {
            $token = isset($negatedScalars[spl_object_id($node)]) ? null : self::token($node);

            if ($token !== null) {
                $tokens[$token] = true;
            }
        }

        return $tokens;
    }

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node> $nodes
     * @return array<int, true>
     */
    private static function negatedScalars(NodeFinder $nodeFinder, Node|array $nodes): array
    {
        $negatedScalars = [];

        foreach ($nodeFinder->findInstanceOf($nodes, UnaryMinus::class) as $unaryMinus) {
            if ($unaryMinus->expr instanceof Scalar) {
                $negatedScalars[spl_object_id($unaryMinus->expr)] = true;
            }
        }

        return $negatedScalars;
    }

    private static function carriesContract(Node $node): bool
    {
        return $node instanceof Scalar
            || $node instanceof UnaryMinus
            || $node instanceof ConstFetch
            || $node instanceof ClassConstFetch
            || $node instanceof MethodCall
            || $node instanceof StaticCall
            || $node instanceof FuncCall
            || $node instanceof New_;
    }

    private static function token(Node $node): ?string
    {
        return match (true) {
            $node instanceof Scalar => self::scalar($node, ''),
            $node instanceof UnaryMinus => $node->expr instanceof Scalar ? self::scalar($node->expr, '-') : null,
            $node instanceof ConstFetch => 'value:' . $node->name->toLowerString(),
            $node instanceof ClassConstFetch => $node->class instanceof Name && $node->name instanceof Identifier
                ? sprintf('const:%s::%s', $node->class->toString(), $node->name->toString())
                : null,
            $node instanceof MethodCall => $node->name instanceof Identifier
                ? self::negation($node->var) . 'call:' . $node->name->toString()
                : null,
            $node instanceof StaticCall => $node->class instanceof Name && $node->name instanceof Identifier
                ? sprintf('call:%s::%s', $node->class->toString(), $node->name->toString())
                : null,
            $node instanceof FuncCall => $node->name instanceof Name ? 'function:' . $node->name->toString() : null,
            $node instanceof New_ => $node->class instanceof Name ? 'new:' . $node->class->toString() : null,
            default => null,
        };
    }

    private static function negation(Expr $receiver): string
    {
        return $receiver instanceof PropertyFetch && $receiver->name instanceof Identifier && $receiver->name->toString() === 'not'
            ? 'not:'
            : '';
    }

    private static function scalar(Scalar $scalar, string $sign): ?string
    {
        $value = property_exists($scalar, 'value') ? $scalar->value : null;

        return is_scalar($value) ? 'value:' . $sign . var_export($value, return: true) : null;
    }

}
