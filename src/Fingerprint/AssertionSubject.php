<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use Pest\Expectation;
use Pest\Mixins\Expectation as ExpectationMixin;
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
use PHPUnit\Framework\Assert;
use ReflectionMethod;
use ReflectionParameter;

final class AssertionSubject
{

    private const string MESSAGE_PARAMETER = 'message';

    /**
     * @param array<string, \PhpParser\Node\Expr> $assignedValues
     */
    public static function of(Node $assertion, string $method, array $assignedValues): ?Expr
    {
        return array_find(
            self::candidates($assertion, $method, $assignedValues),
            static fn (Expr $subject): bool => self::token($subject) !== null,
        );
    }

    public static function token(Expr $subject, bool $withLiteralArguments = false): ?string
    {
        $token = self::partToken($subject, $withLiteralArguments);
        $receiver = match (true) {
            !$subject instanceof MethodCall => null,
            $subject->var instanceof Variable && is_string($subject->var->name) => 'variable:' . $subject->var->name,
            default => self::partToken($subject->var, $withLiteralArguments),
        };

        return $token === null || $receiver === null ? $token : $receiver . '/' . $token;
    }

    public static function literalArguments(Node $call, ?string $assertionMethod = null): string
    {
        $arguments = '';
        $messagePosition = $assertionMethod === null ? null : self::messagePosition($assertionMethod);

        foreach ($call instanceof CallLike ? $call->getRawArgs() : [] as $position => $argument) {
            $arguments .= $argument instanceof Arg
                && !self::fillsMessage($argument->name?->toString() ?? (string) $position, $messagePosition)
                ? self::literalArgument($argument, $position)
                : '';
        }

        return $arguments;
    }

    public static function isFailureMessage(string $token): bool
    {
        return preg_match(TokenCollector::ARGUMENT_PATTERN, $token, $matches) === 1
            && self::fillsMessage($matches[3], self::messagePosition($matches[2]));
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
     * @param array<string, \PhpParser\Node\Expr> $assignedValues
     * @return list<\PhpParser\Node\Expr>
     */
    private static function candidates(Node $assertion, string $method, array $assignedValues): array
    {
        return array_values(array_filter(array_map(
            static fn (?Expr $subject): ?Expr => $subject instanceof Variable && is_string($subject->name)
                ? $assignedValues[$subject->name] ?? $subject
                : $subject,
            self::subjects($assertion, $method),
        )));
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

    private static function messagePosition(string $assertionMethod): ?int
    {
        $class = array_find(
            [ExpectationMixin::class, Expectation::class, Assert::class],
            static fn (string $class): bool => method_exists($class, $assertionMethod),
        );

        return $class === null ? null : array_find(
            new ReflectionMethod($class, $assertionMethod)->getParameters(),
            static fn (ReflectionParameter $parameter): bool => $parameter->getName() === self::MESSAGE_PARAMETER,
        )?->getPosition();
    }

    private static function literalArgument(Arg $argument, int $position): string
    {
        $literal = TokenCollector::isData($argument->value) ? (string) TokenCollector::token($argument->value) : null;

        return $literal === null ? '' : sprintf(
            '#%s:%s',
            $argument->name?->toString() ?? $position,
            substr($literal, (int) strpos($literal, ':') + 1),
        );
    }

    private static function fillsMessage(string $argument, ?int $messagePosition): bool
    {
        return $messagePosition !== null && in_array($argument, [self::MESSAGE_PARAMETER, (string) $messagePosition], strict: true);
    }

    private static function partToken(Expr $part, bool $withLiteralArguments): ?string
    {
        $token = $part instanceof PropertyFetch && $part->name instanceof Identifier
            ? 'property:' . $part->name->toString()
            : TokenCollector::token($part);

        return match (true) {
            $token === null || preg_match(TokenCollector::DATA_TOKEN_PATTERN, $token) === 1 => null,
            $withLiteralArguments => $token . self::literalArguments($part),
            default => $token,
        };
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
