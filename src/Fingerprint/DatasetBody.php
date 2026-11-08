<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Expr\YieldFrom;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

final class DatasetBody
{

    /**
     * The rows of a dataset closure or function: its yields, its yield from and its returned value, with each local
     * variable that the body assigns once before them replaced by its value.
     *
     * @param array<\PhpParser\Node\Stmt> $statements
     * @param callable(\PhpParser\Node\Expr): array<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_|null> $rowsOf
     * @return array<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_|null>
     */
    public static function rows(array $statements, callable $rowsOf): array
    {
        $writes = self::writeCounts($statements);
        $values = [];
        $rows = [];

        foreach (array_values($statements) as $statement) {
            $name = self::onceAssignedName($statement, $writes);

            if ($name !== null && $rows === [] && $statement instanceof Expression && $statement->expr instanceof Assign) {
                $values[$name] = LocalValues::substituted($statement->expr->expr, $values);

                continue;
            }

            $part = self::part($statement, $values, $rowsOf, $rows === []);

            if ($part === []) {
                return [];
            }

            $rows = [...$rows, ...$part];
        }

        return $rows;
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     * @return array<string, int> how often the statements, at any depth, write each variable
     */
    public static function writeCounts(array $statements): array
    {
        $counts = [];

        foreach (new NodeFinder()->find($statements, self::isWrite(...)) as $write) {
            $name = self::writtenName($write);

            if ($name !== null) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param array<string, \PhpParser\Node\Expr> $values
     * @param callable(\PhpParser\Node\Expr): array<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_|null> $rowsOf
     * @return array<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_|null>
     */
    private static function part(Stmt $statement, array $values, callable $rowsOf, bool $isFirst): array
    {
        $expression = $statement instanceof Expression ? $statement->expr : null;

        return match (true) {
            $expression instanceof Yield_ => [self::yielded($expression, $values)],
            $expression instanceof YieldFrom => $rowsOf(LocalValues::substituted($expression->expr, $values)),
            $statement instanceof Return_ && $isFirst && $statement->expr instanceof Expr => $rowsOf(
                LocalValues::substituted($statement->expr, $values),
            ),
            default => [],
        };
    }

    /**
     * @param array<string, \PhpParser\Node\Expr> $values
     */
    private static function yielded(Yield_ $yield, array $values): Yield_
    {
        if ($values === []) {
            return $yield;
        }

        $substituted = clone $yield;
        $substituted->key = $yield->key instanceof Expr ? LocalValues::substituted($yield->key, $values) : null;
        $substituted->value = $yield->value instanceof Expr ? LocalValues::substituted($yield->value, $values) : null;

        return $substituted;
    }

    /**
     * @param array<string, int> $writes
     */
    private static function onceAssignedName(Stmt $statement, array $writes): ?string
    {
        $assignment = $statement instanceof Expression && $statement->expr instanceof Assign ? $statement->expr : null;
        $name = $assignment?->var instanceof Variable && is_string($assignment->var->name) ? $assignment->var->name : null;

        return $name !== null && ($writes[$name] ?? 0) === 1 ? $name : null;
    }

    private static function isWrite(Node $node): bool
    {
        return $node instanceof Assign || $node instanceof AssignOp || $node instanceof AssignRef;
    }

    private static function writtenName(Node $write): ?string
    {
        $target = $write instanceof Assign || $write instanceof AssignOp || $write instanceof AssignRef ? $write->var : null;

        while ($target instanceof ArrayDimFetch) {
            $target = $target->var;
        }

        return $target instanceof Variable && is_string($target->name) ? $target->name : null;
    }

}
