<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use Pekral\TestRedundancy\Support\TestKey;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

final class ContractFingerprints
{

    private const PEST_FUNCTIONS = ['it', 'test'];

    /**
     * @return array<string, array<string, true>>
     */
    public static function of(string $testFile): array
    {
        $statements = (new ParserFactory())->createForHostVersion()->parse((string) file_get_contents($testFile)) ?? [];
        $fingerprints = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Expression) {
                $fingerprints = [...$fingerprints, ...self::pestTest($statement->expr)];
            }
        }

        foreach ((new NodeFinder())->findInstanceOf($statements, Class_::class) as $class) {
            $fingerprints = [...$fingerprints, ...self::phpUnitTests($class)];
        }

        return $fingerprints;
    }

    /**
     * @return array<string, array<string, true>>
     */
    private static function pestTest(Node $expression): array
    {
        $datasetRows = [];
        $modifierTokens = [];

        while ($expression instanceof MethodCall) {
            if (self::isDatasetCall($expression)) {
                $datasetRows = self::datasetRows($expression);
            } else {
                $modifierTokens += TokenCollector::collect($expression->args) + self::methodToken($expression);
            }

            $expression = $expression->var;
        }

        $description = self::pestDescription($expression);

        if ($description === null || !$expression instanceof FuncCall) {
            return [];
        }

        $body = TokenCollector::collect($expression->args[1] ?? null) + $modifierTokens;

        return self::withDatasets($description, $body, $datasetRows);
    }

    private static function isDatasetCall(MethodCall $call): bool
    {
        return $call->name instanceof Identifier && $call->name->toString() === 'with';
    }

    /**
     * @return array<\PhpParser\Node\ArrayItem|null>
     */
    private static function datasetRows(MethodCall $call): array
    {
        $firstArgument = $call->args[0] ?? null;

        return $firstArgument instanceof Arg && $firstArgument->value instanceof Array_ ? $firstArgument->value->items : [];
    }

    /**
     * @return array<string, true>
     */
    private static function methodToken(MethodCall $call): array
    {
        return $call->name instanceof Identifier ? ['call:' . $call->name->toString() => true] : [];
    }

    private static function pestDescription(Node $expression): ?string
    {
        if (!$expression instanceof FuncCall || !$expression->name instanceof Name) {
            return null;
        }

        $function = $expression->name->toLowerString();
        $firstArgument = $expression->args[0] ?? null;

        $isPestTest = in_array($function, self::PEST_FUNCTIONS, strict: true);

        if (!$isPestTest || !$firstArgument instanceof Arg || !$firstArgument->value instanceof String_) {
            return null;
        }

        return ($function === 'it' ? 'it ' : '') . $firstArgument->value->value;
    }

    /**
     * @return array<string, array<string, true>>
     */
    private static function phpUnitTests(Class_ $class): array
    {
        $fingerprints = [];

        foreach ($class->getMethods() as $method) {
            if (!self::isPhpUnitTest($method)) {
                continue;
            }

            $provider = self::dataProviderName($method);
            $rows = $provider === null ? [] : self::providerRows($class->getMethod($provider));
            $body = TokenCollector::collect($method->stmts);
            $fingerprints = [...$fingerprints, ...self::withDatasets($method->name->toString(), $body, $rows)];
        }

        return $fingerprints;
    }

    private static function isPhpUnitTest(ClassMethod $method): bool
    {
        if (!$method->isPublic()) {
            return false;
        }

        return str_starts_with($method->name->toString(), 'test')
            || self::hasAttribute($method, 'Test')
            || str_contains((string) $method->getDocComment()?->getText(), '@test');
    }

    private static function dataProviderName(ClassMethod $method): ?string
    {
        foreach (self::attributes($method) as $attribute) {
            $argument = $attribute->args[0] ?? null;

            if (self::shortName($attribute->name) === 'DataProvider' && $argument?->value instanceof String_) {
                return $argument->value->value;
            }
        }

        $matched = preg_match('/@dataProvider\s+(\w+)/', (string) $method->getDocComment()?->getText(), $matches) === 1;

        return $matched ? $matches[1] : null;
    }

    /**
     * @return list<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_>
     */
    private static function providerRows(?ClassMethod $provider): array
    {
        if ($provider === null) {
            return [];
        }

        $nodeFinder = new NodeFinder();
        $yields = $nodeFinder->findInstanceOf($provider->stmts ?? [], Yield_::class);

        if ($yields !== []) {
            return array_values($yields);
        }

        $return = $nodeFinder->findFirstInstanceOf($provider->stmts ?? [], Return_::class);

        return $return?->expr instanceof Array_ ? array_values($return->expr->items) : [];
    }

    /**
     * @param array<string, true> $body
     * @param array<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_|null> $rows
     * @return array<string, array<string, true>>
     */
    private static function withDatasets(string $testCase, array $body, array $rows): array
    {
        if ($rows === []) {
            return [$testCase => $body];
        }

        $fingerprints = [];

        foreach ($rows as $row) {
            if ($row === null || !$row->key instanceof String_ || $row->value === null) {
                continue;
            }

            $fingerprints[$testCase . TestKey::DATASET_SEPARATOR . $row->key->value] = $body + TokenCollector::collect($row->value);
        }

        return $fingerprints;
    }

    private static function hasAttribute(ClassMethod $method, string $name): bool
    {
        foreach (self::attributes($method) as $attribute) {
            if (self::shortName($attribute->name) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<\PhpParser\Node\Attribute>
     */
    private static function attributes(ClassMethod $method): array
    {
        $attributes = [];

        foreach ($method->attrGroups as $group) {
            $attributes = [...$attributes, ...array_values($group->attrs)];
        }

        return $attributes;
    }

    private static function shortName(Name $name): string
    {
        return $name->getLast();
    }

}
