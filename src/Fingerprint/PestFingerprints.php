<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Namespace_;

final readonly class PestFingerprints
{

    private const array TEST_FUNCTIONS = ['it', 'test'];

    private const string DESCRIBE_SEPARATOR = ' → ';

    /**
     * @param array<string, array<\PhpParser\Node\ArrayItem|null>> $namedDatasets
     */
    private function __construct(private array $namedDatasets)
    {
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     * @return array<string, array<string, true>>
     */
    public static function of(array $statements): array
    {
        $statements = self::withoutNamespaces($statements);

        return new self(self::namedDatasets($statements))->walk($statements, '');
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     * @return array<string, array<string, true>>
     */
    private function walk(array $statements, string $prefix): array
    {
        $fingerprints = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Expression) {
                $fingerprints = [...$fingerprints, ...$this->expression($statement->expr, $prefix)];
            }
        }

        return $fingerprints;
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function expression(Node $expression, string $prefix): array
    {
        $describe = $expression instanceof FuncCall ? self::functionCall($expression, ['describe']) : null;
        $body = $expression instanceof FuncCall ? self::argument($expression, 1) : null;

        if ($describe !== null && $body instanceof Closure) {
            return $this->walk($body->stmts, $prefix . '`' . $describe . '`' . self::DESCRIBE_SEPARATOR);
        }

        return $this->test($expression, $prefix);
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function test(Node $expression, string $prefix): array
    {
        [$call, $datasetRows, $modifierTokens] = $this->unwrapChain($expression);
        $description = $call === null ? null : self::functionCall($call, self::TEST_FUNCTIONS);

        if ($call === null || $description === null) {
            return [];
        }

        $isIt = $call->name instanceof Name && $call->name->toLowerString() === 'it';
        $body = TokenCollector::collect(self::argument($call, 1)) + $modifierTokens;

        return DatasetRows::fingerprints($prefix . ($isIt ? 'it ' : '') . $description, $body, $datasetRows);
    }

    /**
     * @return array{0: \PhpParser\Node\Expr\FuncCall|null, 1: array<\PhpParser\Node\ArrayItem|null>, 2: array<string, true>}
     */
    private function unwrapChain(Node $expression): array
    {
        $datasetRows = [];
        $modifierTokens = [];

        while ($expression instanceof MethodCall) {
            $method = $expression->name instanceof Identifier ? $expression->name->toString() : null;

            if ($method === 'with') {
                $datasetRows = $this->datasetRows(self::argument($expression, 0));
            } elseif ($method !== null) {
                $modifierTokens += TokenCollector::collect($expression->args) + ['call:' . $method => true];
            }

            $expression = $expression->var;
        }

        return [$expression instanceof FuncCall ? $expression : null, $datasetRows, $modifierTokens];
    }

    /**
     * @return array<\PhpParser\Node\ArrayItem|null>
     */
    private function datasetRows(?Node $dataset): array
    {
        return match (true) {
            $dataset instanceof Array_ => $dataset->items,
            $dataset instanceof String_ => $this->namedDatasets[$dataset->value] ?? [],
            default => [],
        };
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     * @return array<\PhpParser\Node\Stmt>
     */
    private static function withoutNamespaces(array $statements): array
    {
        $flattened = [];

        foreach ($statements as $statement) {
            $flattened = [...$flattened, ...($statement instanceof Namespace_ ? $statement->stmts : [$statement])];
        }

        return $flattened;
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     * @return array<string, array<\PhpParser\Node\ArrayItem|null>>
     */
    private static function namedDatasets(array $statements): array
    {
        $datasets = [];

        foreach ($statements as $statement) {
            [$name, $rows] = self::datasetDefinition($statement);

            if ($name !== null) {
                $datasets[$name] = $rows;
            }
        }

        return $datasets;
    }

    /**
     * @return array{0: string|null, 1: array<\PhpParser\Node\ArrayItem|null>}
     */
    private static function datasetDefinition(Stmt $statement): array
    {
        $call = $statement instanceof Expression && $statement->expr instanceof FuncCall ? $statement->expr : null;
        $name = $call === null ? null : self::functionCall($call, ['dataset']);
        $rows = $call === null ? null : self::argument($call, 1);

        return $name !== null && $rows instanceof Array_ ? [$name, $rows->items] : [null, []];
    }

    /**
     * @param list<string> $functions
     */
    private static function functionCall(FuncCall $call, array $functions): ?string
    {
        $firstArgument = self::argument($call, 0);
        $isFunction = $call->name instanceof Name && in_array($call->name->toLowerString(), $functions, strict: true);

        return $isFunction && $firstArgument instanceof String_ ? $firstArgument->value : null;
    }

    private static function argument(FuncCall|MethodCall $call, int $position): ?Node
    {
        $argument = $call->args[$position] ?? null;

        return $argument instanceof Arg ? $argument->value : null;
    }

}
