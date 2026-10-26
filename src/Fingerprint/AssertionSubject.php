<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use Pest\Expectation;
use Pest\Mixins\Expectation as ExpectationMixin;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Assert;
use ReflectionMethod;
use ReflectionParameter;

final class AssertionSubject
{

    private const string MESSAGE_PARAMETER = 'message';

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node> $nodes
     * @param array<string, \PhpParser\Node\Expr> $assignedValues
     * @return array<int, \PhpParser\Node\Expr>
     */
    public static function byAssertion(NodeFinder $nodeFinder, Node|array $nodes, array $assignedValues): array
    {
        $subjects = [];

        foreach ($nodeFinder->find($nodes, TokenCollector::carriesContract(...)) as $node) {
            $token = TokenCollector::token($node);
            $subject = $token === null ? null : self::of($node, TokenCollector::calledMethod($token), $assignedValues);

            if ($subject !== null) {
                $subjects[spl_object_id($node)] = $subject;
            }
        }

        return $subjects;
    }

    /**
     * @param array<int, \PhpParser\Node\Expr> $subjects
     * @return array<int, \PhpParser\Node\Expr>
     */
    public static function named(array $subjects): array
    {
        return array_filter($subjects, static fn (Expr $subject): bool => self::token($subject) !== null);
    }

    /**
     * @param array<int, \PhpParser\Node\Expr> $subjects
     * @return array<int, \PhpParser\Node\Expr>
     */
    public static function paired(array $subjects): array
    {
        $named = self::named($subjects);
        $unnamed = array_map(self::tokenOrSource(...), array_diff_key($subjects, $named));

        return count(array_unique($unnamed)) > 1 ? $subjects : $named;
    }

    public static function tokenOrSource(Expr $subject): string
    {
        return self::token($subject) ?? match (true) {
            TokenCollector::isData($subject) => (string) TokenCollector::token($subject),
            default => OrderTokens::sourceOf($subject),
        };
    }

    public static function token(Expr $subject, bool $withArguments = false): ?string
    {
        $token = self::partToken($subject, $withArguments);
        $receiver = $subject instanceof MethodCall || $subject instanceof NullsafeMethodCall
            ? NonLiteralArguments::identity($subject->var) ?? self::partToken($subject->var, $withArguments)
            : null;

        return $token === null || $receiver === null ? $token : $receiver . '/' . $token;
    }

    public static function arguments(Node $call, ?string $assertionMethod = null): string
    {
        $arguments = self::argumentsBesideMessage($call, $assertionMethod);

        return implode('', array_map(self::argument(...), $arguments, array_keys($arguments)));
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
     */
    private static function of(Node $assertion, string $method, array $assignedValues): ?Expr
    {
        return self::candidates($assertion, $method, $assignedValues)[0] ?? null;
    }

    /**
     * @param array<string, \PhpParser\Node\Expr> $assignedValues
     * @return list<\PhpParser\Node\Expr>
     */
    private static function candidates(Node $assertion, string $method, array $assignedValues): array
    {
        return array_merge([], ...array_map(
            static function (Expr $subject) use ($assignedValues): array {
                $value = $subject instanceof Variable && is_string($subject->name) ? $assignedValues[$subject->name] ?? $subject : $subject;

                return NodeContext::returnedValues($value) ?? [$value];
            },
            array_values(array_filter(self::subjects($assertion, $method))),
        ));
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
                && str_starts_with($method, 'assert') => self::actualArgument($assertion, $method),
            default => [],
        };
    }

    /**
     * @return list<\PhpParser\Node\Expr>
     */
    private static function actualArgument(CallLike $call, string $method): array
    {
        $values = array_map(static fn (Arg $argument): Expr => $argument->value, self::argumentsBesideMessage($call, $method));
        $nonDataValues = array_filter($values, static fn (Expr $value): bool => !TokenCollector::isData($value));

        return array_slice(array_values($nonDataValues === [] ? $values : $nonDataValues), -1);
    }

    /**
     * @return array<\PhpParser\Node\Arg>
     */
    private static function argumentsBesideMessage(Node $call, ?string $assertionMethod): array
    {
        $messagePosition = $assertionMethod === null ? null : self::messagePosition($assertionMethod);

        return array_filter(
            $call instanceof CallLike ? $call->getRawArgs() : [],
            static fn (Node $argument, int $position): bool => $argument instanceof Arg
                && !self::fillsMessage($argument->name?->toString() ?? (string) $position, $messagePosition),
            ARRAY_FILTER_USE_BOTH,
        );
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

    private static function argument(Arg $argument, int $position): string
    {
        $literal = TokenCollector::isData($argument->value) ? (string) TokenCollector::token($argument->value) : null;
        $names = new NodeFinder()->findFirst($argument->value, TokenCollector::isData(...)) === null
            ? NonLiteralArguments::namesOf($argument->value, parameters: [])
            : [];

        return sprintf('#%s:%s', $argument->name?->toString() ?? $position, match (true) {
            $literal !== null => substr($literal, (int) strpos($literal, ':') + 1),
            $names !== [] => implode(',', $names),
            default => OrderTokens::sourceOf($argument->value),
        });
    }

    private static function fillsMessage(string $argument, ?int $messagePosition): bool
    {
        return $messagePosition !== null && in_array($argument, [self::MESSAGE_PARAMETER, (string) $messagePosition], strict: true);
    }

    private static function partToken(Expr $part, bool $withArguments): ?string
    {
        $token = match (true) {
            $part instanceof Cast => CastToken::of($part, $withArguments ? self::castOperand($part->expr) : self::token($part->expr)),
            NodeContext::enumCaseReadBy($part) !== null => null,
            default => NonLiteralArguments::nameOrToken($part),
        };

        return match (true) {
            $token === null => null,
            $withArguments => $token . self::arguments($part),
            preg_match(TokenCollector::DATA_TOKEN_PATTERN, $token) === 1 => null,
            default => $token,
        };
    }

    private static function castOperand(Expr $operand): string
    {
        return self::token($operand) === null ? self::tokenOrSource($operand) : (string) self::token($operand, withArguments: true);
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
