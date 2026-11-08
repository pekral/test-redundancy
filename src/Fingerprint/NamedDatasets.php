<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;

final class NamedDatasets
{

    /**
     * The named datasets of the file and of its describe() blocks. A name that two of them declare is left out,
     * because the analysis cannot tell which declaration a test reads.
     *
     * @param array<\PhpParser\Node\Stmt> $statements
     * @return array<string, \PhpParser\Node>
     */
    public static function of(array $statements): array
    {
        $datasets = [];
        $declarations = [];

        foreach (self::declarations($statements) as [$name, $dataset]) {
            $datasets[$name] = $dataset;
            $declarations[$name] = ($declarations[$name] ?? 0) + 1;
        }

        return array_filter($datasets, static fn (string $name): bool => $declarations[$name] === 1, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     * @return list<array{0: string, 1: \PhpParser\Node}>
     */
    private static function declarations(array $statements): array
    {
        $declarations = [];

        foreach ($statements as $statement) {
            $declarations = [...$declarations, ...self::declarationsIn($statement)];
        }

        return $declarations;
    }

    /**
     * @return list<array{0: string, 1: \PhpParser\Node}>
     */
    private static function declarationsIn(Stmt $statement): array
    {
        $call = $statement instanceof Expression && $statement->expr instanceof FuncCall ? $statement->expr : null;
        $function = $call?->name instanceof Name ? $call->name->toLowerString() : null;
        $name = self::argument($call, 0);
        $value = self::argument($call, 1);

        return match (true) {
            $function === 'dataset' && $name instanceof String_ && $value instanceof Node => [[$name->value, $value]],
            $function === 'describe' && $value instanceof Closure => self::declarations($value->stmts),
            default => [],
        };
    }

    private static function argument(?FuncCall $call, int $position): ?Node
    {
        $argument = $call?->args[$position] ?? null;

        return $argument instanceof Arg ? $argument->value : null;
    }

}
