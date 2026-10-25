<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

final class PhpUnitFingerprints
{

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     * @return array<string, array<string, int>>
     */
    public static function of(array $statements): array
    {
        return self::analyse($statements)->fingerprints;
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     */
    public static function analyse(array $statements): FileFingerprints
    {
        $fingerprints = [];
        $datasetParameters = [];

        foreach (new NodeFinder()->findInstanceOf($statements, Class_::class) as $class) {
            $tests = self::tests($class);
            $fingerprints = [...$fingerprints, ...$tests->fingerprints];
            $datasetParameters = [...$datasetParameters, ...$tests->datasetParameters];
        }

        return new FileFingerprints($fingerprints, [], $datasetParameters);
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     */
    public static function declaresDataset(array $statements): bool
    {
        return array_any(
            new NodeFinder()->findInstanceOf($statements, ClassMethod::class),
            static fn (ClassMethod $method): bool => array_any(
                self::attributes($method),
                static fn (Attribute $attribute): bool => str_starts_with($attribute->name->getLast(), 'DataProvider')
                    || str_starts_with($attribute->name->getLast(), 'TestWith'),
            ) || preg_match('/@(?:dataProvider|testWith)\b/i', (string) $method->getDocComment()?->getText()) === 1,
        );
    }

    private static function tests(Class_ $class): FileFingerprints
    {
        $fingerprints = [];
        $datasetParameters = [];

        foreach ($class->getMethods() as $method) {
            if (!self::isTest($method)) {
                continue;
            }

            $provider = self::dataProviderName($method);
            $rows = $provider === null ? [] : self::providerRows($class->getMethod($provider));
            $rowFingerprints = DatasetRows::fingerprints($method->name->toString(), $method->stmts ?? [], $method->params, $rows);
            $fingerprints = [...$fingerprints, ...$rowFingerprints];
            $datasetParameters = [
                ...$datasetParameters,
                ...$rows === [] ? [] : array_fill_keys(
                    array_keys($rowFingerprints),
                    DatasetRows::parameters($method->stmts ?? [], $method->params),
                ),
            ];
        }

        return new FileFingerprints($fingerprints, [], $datasetParameters);
    }

    private static function isTest(ClassMethod $method): bool
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

            if ($attribute->name->getLast() === 'DataProvider' && $argument?->value instanceof String_) {
                return $argument->value->value;
            }
        }

        $matched = preg_match('/@dataProvider\s+(\w+)/', (string) $method->getDocComment()?->getText(), $matches) === 1;

        return $matched ? $matches[1] : null;
    }

    /**
     * @return list<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_|null>
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

    private static function hasAttribute(ClassMethod $method, string $name): bool
    {
        foreach (self::attributes($method) as $attribute) {
            if ($attribute->name->getLast() === $name) {
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

}
