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
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;

final class TokenCollector
{

    public const string DATA_TOKEN_PATTERN = '/^(in:.*\/)?(key:)?(value|const):/';

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node>|null $nodes
     * @return array<string, int>
     */
    public static function collect(null|Node|array $nodes): array
    {
        if ($nodes === null) {
            return [];
        }

        $nodeFinder = new NodeFinder();
        $negatedScalars = self::negatedScalars($nodeFinder, $nodes);
        $arrayKeys = self::arrayKeys($nodeFinder, $nodes);
        $paths = self::paths($nodeFinder, $nodes);
        $loopBodies = self::loopBodies($nodeFinder, $nodes);
        $tokens = [];

        foreach ($nodeFinder->find($nodes, self::carriesContract(...)) as $node) {
            $token = isset($negatedScalars[spl_object_id($node)]) ? null : self::token($node);

            if ($token === null) {
                continue;
            }

            $prefixedToken = self::prefix($arrayKeys, $paths, $node, $token) . $token;
            $prefixedToken = self::loopPrefix($loopBodies, $node, $prefixedToken) . $prefixedToken;
            $tokens[$prefixedToken] = ($tokens[$prefixedToken] ?? 0) + 1;
        }

        return $tokens;
    }

    public static function isData(Node $node): bool
    {
        $token = self::carriesContract($node) ? self::token($node) : null;

        return $token !== null && preg_match(self::DATA_TOKEN_PATTERN, $token) === 1;
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

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node> $nodes
     * @return array<int, true>
     */
    private static function arrayKeys(NodeFinder $nodeFinder, Node|array $nodes): array
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
     * @return array<int, list<string>>
     */
    private static function paths(NodeFinder $nodeFinder, Node|array $nodes): array
    {
        $paths = [];
        $pathNodes = $nodeFinder->find($nodes, static fn (Node $node): bool => $node instanceof ArrayItem || $node instanceof CallLike);

        foreach ($pathNodes as $pathNode) {
            $segments = $pathNode instanceof ArrayItem ? self::keySegments($nodeFinder, $pathNode) : self::argumentSegments($pathNode);

            foreach ($segments as $nodeId => $segment) {
                $paths[$nodeId][] = $segment;
            }
        }

        return $paths;
    }

    /**
     * @return array<int, string>
     */
    private static function keySegments(NodeFinder $nodeFinder, ArrayItem $arrayItem): array
    {
        $keyToken = $arrayItem->key === null ? null : self::token($arrayItem->key);

        if ($keyToken === null) {
            return [];
        }

        $segments = [];

        foreach ($nodeFinder->find($arrayItem->value, static fn (): bool => true) as $node) {
            $segments[spl_object_id($node)] = $keyToken;
        }

        return $segments;
    }

    /**
     * @return array<int, string>
     */
    private static function argumentSegments(Node $call): array
    {
        $callToken = self::token($call);
        $arguments = $call instanceof CallLike ? $call->getRawArgs() : [];
        $segments = [];

        foreach ($callToken === null ? [] : $arguments as $position => $argument) {
            if ($argument instanceof Arg) {
                $segments[spl_object_id($argument->value)] = $callToken . '#' . ($argument->name?->toString() ?? $position);
            }
        }

        return $segments;
    }

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node> $nodes
     * @return array<int, true>
     */
    private static function loopBodies(NodeFinder $nodeFinder, Node|array $nodes): array
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

    private static function token(Node $node): ?string
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
            $node instanceof Array_ => 'value:[]',
            default => null,
        };
    }

    /**
     * @param array<int, true> $arrayKeys
     * @param array<int, list<string>> $paths
     */
    private static function prefix(array $arrayKeys, array $paths, Node $node, string $token): string
    {
        $path = $paths[spl_object_id($node)] ?? null;
        $isPathedData = $path !== null && preg_match(self::DATA_TOKEN_PATTERN, $token) === 1;

        return match (true) {
            isset($arrayKeys[spl_object_id($node)]) => 'key:',
            $isPathedData => 'in:' . implode('/', $path) . '/',
            default => '',
        };
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
            && (method_exists(Expectation::class, $propertyFetch->name->toString())
                || method_exists(ExpectationMixin::class, $propertyFetch->name->toString()))
            && self::isExpectationChain($propertyFetch->var);
    }

    private static function isExpectationChain(Expr $node): bool
    {
        return match (true) {
            $node instanceof FuncCall => $node->name instanceof Name && $node->name->toLowerString() === 'expect',
            $node instanceof MethodCall, $node instanceof PropertyFetch => self::isExpectationChain($node->var),
            default => false,
        };
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
