<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use Pekral\TestRedundancy\Support\TestKey;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\StaticVar;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

final class DatasetRows
{

    /**
     * @param array<\PhpParser\Node> $body
     * @param array<\PhpParser\Node\Param> $parameters
     * @param array<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_|null> $rows
     * @return array<string, array<string, int>>
     */
    public static function fingerprints(string $testCase, array $body, array $parameters, array $rows): array
    {
        if ($rows === []) {
            return [$testCase => self::bodyTokens($body, $parameters)];
        }

        $fingerprints = [];

        foreach ($rows as $row) {
            if ($row === null || !$row->key instanceof String_ || $row->value === null) {
                continue;
            }

            $fingerprints[$testCase . TestKey::DATASET_SEPARATOR . $row->key->value] = self::rowTokens($body, $parameters, $row->value);
        }

        return $fingerprints;
    }

    /**
     * @param array<\PhpParser\Node> $body
     * @param array<\PhpParser\Node\Param> $parameters
     */
    public static function parameters(array $body, array $parameters): DatasetParameters
    {
        return DatasetParameters::fromSentinelRowTokens(
            array_keys(self::rowTokens($body, $parameters, DatasetParameters::sentinelRow($parameters))),
            $parameters,
        );
    }

    /**
     * @param array<\PhpParser\Node> $body
     * @param array<\PhpParser\Node\Param> $parameters
     * @return array<string, int>
     */
    private static function rowTokens(array $body, array $parameters, Expr $row): array
    {
        $items = $row instanceof Array_ ? array_values($row->items) : [new ArrayItem($row)];
        $arguments = [];

        foreach ($items as $position => $item) {
            $name = self::parameterName($item, $parameters[$position] ?? null);

            if ($name !== null) {
                $arguments[$name] = $item;
            }
        }

        $binder = new ParameterBinder(array_map(static fn (ArrayItem $item): Expr => $item->value, $arguments), dropped: []);
        $boundBody = $binder->bind($body);
        $boundItems = array_intersect_key($arguments, $binder->boundNames());
        $unboundItems = array_filter($items, static fn (ArrayItem $item): bool => !in_array($item, $boundItems, strict: true));

        return TokenCollector::sum(
            self::bodyTokens($boundBody, $parameters),
            TokenCollector::collect($unboundItems),
            ...array_map(self::closureToken(...), array_values($unboundItems)),
        );
    }

    /**
     * @return array<string, int>
     */
    private static function closureToken(ArrayItem $item): array
    {
        return $item->value instanceof Closure || $item->value instanceof ArrowFunction
            ? ['value:closure:' . hash('xxh3', new Standard()->prettyPrintExpr($item->value)) => 1]
            : [];
    }

    /**
     * @param array<\PhpParser\Node> $body
     * @param array<\PhpParser\Node\Param> $parameters
     * @return array<string, int>
     */
    private static function bodyTokens(array $body, array $parameters): array
    {
        $assignments = self::onceAssignments($body, $parameters);
        $assignedValues = array_map(static fn (Assign $assignment): Expr => $assignment->expr, $assignments);
        $boundReads = self::passedOnFirstReads($body, $assignments);
        $values = [...array_filter($assignedValues, self::isBindable(...)), ...array_intersect_key($assignedValues, $boundReads)];
        $binder = new ParameterBinder($values, array_values(array_intersect_key($assignments, $values)), $boundReads);
        $parameterNames = array_values(array_filter(
            array_map(static fn (Param $parameter): ?string => self::variableName($parameter->var), $parameters),
            static fn (?string $name): bool => $name !== null,
        ));

        return TokenCollector::sum(
            TokenCollector::collect($binder->bind($body), $assignedValues, $parameterNames),
            TokenCollector::collect(array_values(array_diff_key($values, $binder->boundNames()))),
        );
    }

    /**
     * @param array<\PhpParser\Node> $body
     * @param array<\PhpParser\Node\Param> $parameters
     * @return array<string, \PhpParser\Node\Expr\Assign>
     */
    private static function onceAssignments(array $body, array $parameters): array
    {
        $writes = self::writeCounts([...$parameters, ...$body]);
        $assignments = [];

        foreach (self::testStatements($body) as $statement) {
            $assignment = self::assignment($statement);
            $name = $assignment === null ? null : self::variableName($assignment->var);

            if ($assignment !== null && $name !== null && $writes[$name] === 1) {
                $assignments[$name] = $assignment;
            }
        }

        return $assignments;
    }

    /**
     * @param array<\PhpParser\Node> $body
     * @param array<string, \PhpParser\Node\Expr\Assign> $assignments
     * @return array<string, \PhpParser\Node\Expr\Variable>
     */
    private static function passedOnFirstReads(array $body, array $assignments): array
    {
        $nodeFinder = new NodeFinder();
        $loopBodies = NodeContext::loopBodies($nodeFinder, $body);
        $passedOnValues = array_map(
            static fn (Arg|ArrayItem $item): int => spl_object_id($item->value),
            [...$nodeFinder->findInstanceOf($body, Arg::class), ...$nodeFinder->findInstanceOf($body, ArrayItem::class)],
        );
        $callResultAssignments = array_filter($assignments, static fn (Assign $assignment): bool => $assignment->expr instanceof CallLike);
        $firstReads = [];

        foreach ($callResultAssignments as $name => $assignment) {
            $firstRead = $nodeFinder->findFirst(
                $body,
                static fn (Node $node): bool => $node !== $assignment->var && self::variableName($node) === $name,
            );

            if ($firstRead instanceof Variable
                && in_array(spl_object_id($firstRead), $passedOnValues, strict: true)
                && !isset($loopBodies[spl_object_id($firstRead)])
            ) {
                $firstReads[$name] = $firstRead;
            }
        }

        return $firstReads;
    }

    /**
     * @param array<\PhpParser\Node> $body
     * @return array<\PhpParser\Node>
     */
    private static function testStatements(array $body): array
    {
        return array_merge(...array_map(
            static fn (Node $node): array => $node instanceof FunctionLike ? $node->getStmts() ?? [] : [$node],
            $body,
        ));
    }

    private static function assignment(Node $statement): ?Assign
    {
        $expression = $statement instanceof Expression ? $statement->expr : null;

        return $expression instanceof Assign ? $expression : null;
    }

    /**
     * @param array<\PhpParser\Node> $nodes
     * @return array<string, int>
     */
    private static function writeCounts(array $nodes): array
    {
        $nodeFinder = new NodeFinder();
        $counts = [];

        foreach ($nodeFinder->find($nodes, static fn (): bool => true) as $node) {
            foreach ($nodeFinder->findInstanceOf(self::writeTargets($node), Variable::class) as $variable) {
                $name = self::variableName($variable);

                if ($name !== null) {
                    $counts[$name] = ($counts[$name] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * @return array<\PhpParser\Node>
     */
    private static function writeTargets(Node $node): array
    {
        return match (true) {
            $node instanceof Assign, $node instanceof AssignRef, $node instanceof AssignOp => [$node->var],
            $node instanceof Param, $node instanceof StaticVar => [$node->var],
            $node instanceof PreInc, $node instanceof PreDec, $node instanceof PostInc, $node instanceof PostDec => [$node->var],
            $node instanceof ClosureUse => $node->byRef ? [$node->var] : [],
            $node instanceof Catch_ => array_filter([$node->var]),
            $node instanceof Foreach_ => array_filter([$node->keyVar, $node->valueVar]),
            $node instanceof Unset_, $node instanceof Global_ => $node->vars,
            default => [],
        };
    }

    private static function parameterName(ArrayItem $item, ?Param $parameter): ?string
    {
        $isUnbindable = $item->key !== null
            || $item->unpack
            || $parameter === null
            || $parameter->variadic
            || !self::isBindable($item->value);

        return self::variableName($isUnbindable ? null : $parameter->var);
    }

    private static function isBindable(Expr $value): bool
    {
        return TokenCollector::isData($value)
            || ($value instanceof Array_ && $value->items !== [] && (self::isDataList($value) || self::isDataMap($value)));
    }

    private static function isDataList(Array_ $value): bool
    {
        return array_all($value->items, static fn (ArrayItem $item): bool => $item->key === null && self::isBindable($item->value));
    }

    private static function isDataMap(Array_ $value): bool
    {
        return array_all($value->items, static fn (ArrayItem $item): bool => $item->key !== null
            && TokenCollector::isData($item->key)
            && self::isBindable($item->value));
    }

    private static function variableName(?Node $node): ?string
    {
        return $node instanceof Variable && is_string($node->name) ? $node->name : null;
    }

}
