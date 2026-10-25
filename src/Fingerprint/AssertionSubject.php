<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

final class AssertionSubject
{

    /**
     * @param array<string, \PhpParser\Node\Expr> $assignedValues
     * @return list<\PhpParser\Node\Expr>
     */
    public static function candidates(Node $assertion, string $method, array $assignedValues): array
    {
        return array_values(array_filter(array_map(
            static fn (?Expr $subject): ?Expr => $subject instanceof Variable && is_string($subject->name)
                ? $assignedValues[$subject->name] ?? $subject
                : $subject,
            self::subjects($assertion, $method),
        )));
    }

    public static function isExpectationChain(Expr $node): bool
    {
        return match (true) {
            $node instanceof FuncCall => $node->name instanceof Name && $node->name->toLowerString() === 'expect',
            $node instanceof MethodCall, $node instanceof PropertyFetch => self::isExpectationChain($node->var),
            default => false,
        };
    }

    /**
     * @return list<\PhpParser\Node\Expr|null>
     */
    private static function subjects(Node $assertion, string $method): array
    {
        return match (true) {
            ($assertion instanceof MethodCall || $assertion instanceof PropertyFetch) && self::isExpectationChain($assertion->var) => [
                self::expectedValue($assertion->var),
            ],
            ($assertion instanceof MethodCall || $assertion instanceof StaticCall || $assertion instanceof FuncCall)
                && str_starts_with($method, 'assert') => self::lastNonDataArgument($assertion),
            default => [],
        };
    }

    /**
     * @return list<\PhpParser\Node\Expr>
     */
    private static function lastNonDataArgument(CallLike $call): array
    {
        return array_slice(array_values(array_filter(
            array_map(
                static fn (Node $argument): ?Expr => $argument instanceof Arg ? $argument->value : null,
                $call->getRawArgs(),
            ),
            static fn (?Expr $value): bool => $value !== null && !TokenCollector::isData($value),
        )), -1);
    }

    private static function expectedValue(Expr $chain): ?Expr
    {
        $isAnd = $chain instanceof MethodCall && $chain->name instanceof Identifier && $chain->name->toString() === 'and';
        $argument = $chain instanceof CallLike ? $chain->getRawArgs()[0] ?? null : null;

        return match (true) {
            ($chain instanceof MethodCall && !$isAnd) || $chain instanceof PropertyFetch => self::expectedValue($chain->var),
            default => $argument instanceof Arg ? $argument->value : null,
        };
    }

}
