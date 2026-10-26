<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use Pekral\TestRedundancy\Support\TestKey;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;

/**
 * @phpstan-type Test array{
 *     tokens: array<string, int>,
 *     dataset: string|null,
 *     datasets: list<string>,
 *     parameters: \Pekral\TestRedundancy\Fingerprint\DatasetParameters|null,
 * }
 */
final readonly class PestFingerprints
{

    private const array TEST_FUNCTIONS = ['it', 'test'];

    private const string DESCRIBE_SEPARATOR = ' → ';

    private const array REPEATING_METHODS = ['with', 'repeat'];

    private const array EXPECTED_EXCEPTION_METHODS = ['throws', 'throwsIf', 'throwsUnless'];

    /**
     * @param array<string, array<\PhpParser\Node\ArrayItem|null>> $namedDatasets
     */
    private function __construct(private array $namedDatasets)
    {
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     * @return array<string, array<string, int>>
     */
    public static function of(array $statements): array
    {
        return self::fingerprints(self::tests($statements));
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     */
    public static function analyse(array $statements): FileFingerprints
    {
        $tests = self::tests($statements);

        return new FileFingerprints(self::fingerprints($tests), self::sharedDatasets($tests), self::datasetParameters($tests));
    }

    public static function isExpectedExceptionToken(string $token): bool
    {
        return preg_match('~^call:(\w+)(?:#\w+/|$)~', $token, $matches) === 1
            && in_array($matches[1], self::EXPECTED_EXCEPTION_METHODS, strict: true);
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    public static function expectedExceptionTokens(array $tokens): array
    {
        $expected = array_values(array_filter($tokens, self::isExpectedExceptionToken(...)));
        $arguments = array_filter($expected, static fn (string $token): bool => str_contains($token, '#'));

        return array_values(array_filter(
            $expected,
            static fn (string $token): bool => str_contains($token, '#')
                || array_any($arguments, static fn (string $argument): bool => str_starts_with($argument, $token . '#')),
        ));
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     */
    public static function declaresOnlyFingerprintedTests(array $statements): bool
    {
        $nodeFinder = new NodeFinder();
        $testCalls = $nodeFinder->find(
            $statements,
            static fn (Node $node): bool => $node instanceof FuncCall && $node->args !== [] && self::isCallOf($node, self::TEST_FUNCTIONS),
        );
        $repeatingCalls = $nodeFinder->find($statements, self::repeatsTest(...));

        return $repeatingCalls === [] && count($testCalls) === count(self::of($statements));
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     * @return array<string, Test>
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
     * @return array<string, Test>
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
     * @return array<string, Test>
     */
    private function test(Node $expression, string $prefix): array
    {
        [$call, $datasetRows, $modifierTokens, $dataset, $datasets] = $this->unwrapChain($expression);
        $description = $call === null ? null : self::functionCall($call, self::TEST_FUNCTIONS);

        if ($call === null || $description === null) {
            return [];
        }

        $isIt = $call->name instanceof Name && $call->name->toLowerString() === 'it';
        $test = self::argument($call, 1);
        $body = $test === null ? [] : [$test];
        $parameters = $test instanceof FunctionLike ? $test->getParams() : [];
        $datasetParameters = $datasetRows === [] ? null : DatasetRows::parameters($body, $parameters);

        return array_map(
            static fn (array $tokens): array => [
                'dataset' => $dataset,
                'datasets' => $datasets,
                'parameters' => $datasetParameters,
                'tokens' => TokenCollector::sum($tokens, $modifierTokens),
            ],
            DatasetRows::fingerprints($prefix . ($isIt ? 'it ' : '') . $description, $body, $parameters, $datasetRows),
        );
    }

    /**
     * @return array{
     *     0: \PhpParser\Node\Expr\FuncCall|null,
     *     1: array<\PhpParser\Node\ArrayItem|null>,
     *     2: array<string, int>,
     *     3: string|null,
     *     4: list<string>,
     * }
     */
    private function unwrapChain(Node $expression): array
    {
        $datasetRows = [];
        $modifierTokens = [];
        $dataset = null;
        $datasets = [];

        while ($expression instanceof MethodCall) {
            $method = $expression->name instanceof Identifier ? $expression->name->toString() : null;

            if ($method === 'with') {
                $datasetArgument = self::argument($expression, 0);
                $datasetRows = $this->datasetRows($datasetArgument);
                $dataset = $this->datasetName($datasetArgument);
                $datasets = [...$datasets, ...$this->datasetNames($expression)];
            } elseif ($method !== null) {
                $modifierTokens = TokenCollector::sum($modifierTokens, $this->modifierTokens($method, $expression));
            }

            $expression = $expression->var;
        }

        return [
            $expression instanceof FuncCall ? $expression : null,
            $datasetRows,
            $modifierTokens,
            $dataset,
            array_values(array_unique(array_filter($datasets, static fn (?string $name): bool => $name !== null))),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function modifierTokens(string $method, MethodCall $call): array
    {
        if (!in_array($method, self::EXPECTED_EXCEPTION_METHODS, strict: true)) {
            return TokenCollector::sum(TokenCollector::collect($call->args), ['call:' . $method => 1]);
        }

        $tokens = ['call:' . $method => 1];

        foreach ($call->args as $position => $argument) {
            if (!$argument instanceof Arg) {
                continue;
            }

            foreach (TokenCollector::sum(TokenCollector::collect($argument), $this->sourceToken($argument)) as $token => $count) {
                $tokens['call:' . $method . '#' . ($argument->name?->toString() ?? $position) . '/' . $token] = $count;
            }
        }

        return $tokens;
    }

    /**
     * @return array<string, int>
     */
    private function sourceToken(Arg $argument): array
    {
        return TokenCollector::isData($argument->value) ? [] : ['source:' . OrderTokens::sourceOf($argument->value) => 1];
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

    private function datasetName(?Node $dataset): ?string
    {
        return $dataset instanceof String_ ? $dataset->value : null;
    }

    /**
     * @return list<string|null>
     */
    private function datasetNames(MethodCall $call): array
    {
        return array_map(
            fn (Node $argument): ?string => $argument instanceof Arg ? $this->datasetName($argument->value) : null,
            array_values($call->args),
        );
    }

    /**
     * @param array<string, Test> $tests
     * @return array<string, array<string, int>>
     */
    private static function fingerprints(array $tests): array
    {
        return array_map(static fn (array $test): array => $test['tokens'], $tests);
    }

    /**
     * @param array<string, Test> $tests
     * @return array<string, \Pekral\TestRedundancy\Fingerprint\DatasetParameters>
     */
    private static function datasetParameters(array $tests): array
    {
        return array_filter(array_map(static fn (array $test): ?DatasetParameters => $test['parameters'], $tests));
    }

    /**
     * @param array<string, Test> $tests
     * @return array<string, \Pekral\TestRedundancy\Fingerprint\SharedDataset>
     */
    private static function sharedDatasets(array $tests): array
    {
        $users = self::testsByDataset($tests);
        $shared = [];

        foreach ($tests as $testKey => $test) {
            $dataset = $test['dataset'];
            $name = TestKey::withoutDatasetRow($testKey);
            $otherTests = $dataset === null ? [] : array_values(array_diff($users[$dataset], [$name]));

            if ($dataset !== null && $otherTests !== []) {
                $shared[$name] = new SharedDataset($dataset, $otherTests);
            }
        }

        return $shared;
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     * @return array<string, Test>
     */
    private static function tests(array $statements): array
    {
        $statements = self::withoutNamespaces($statements);

        return new self(self::namedDatasets($statements))->walk($statements, '');
    }

    /**
     * @param array<string, Test> $tests
     * @return array<string, list<string>>
     */
    private static function testsByDataset(array $tests): array
    {
        $datasets = [];

        foreach ($tests as $testKey => $test) {
            foreach ($test['datasets'] as $dataset) {
                $datasets[$dataset] = array_values(array_unique([...$datasets[$dataset] ?? [], TestKey::withoutDatasetRow($testKey)]));
            }
        }

        return $datasets;
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

    private static function repeatsTest(Node $node): bool
    {
        if (!$node instanceof MethodCall
            || !$node->name instanceof Identifier
            || !in_array($node->name->toLowerString(), self::REPEATING_METHODS, strict: true)
        ) {
            return false;
        }

        $root = $node;

        while ($root instanceof MethodCall) {
            $root = $root->var;
        }

        return $root instanceof FuncCall && self::isCallOf($root, [...self::TEST_FUNCTIONS, 'describe']);
    }

    /**
     * @param list<string> $functions
     */
    private static function isCallOf(FuncCall $call, array $functions): bool
    {
        return $call->name instanceof Name && in_array($call->name->toLowerString(), $functions, strict: true);
    }

    /**
     * @param list<string> $functions
     */
    private static function functionCall(FuncCall $call, array $functions): ?string
    {
        $firstArgument = self::argument($call, 0);

        return self::isCallOf($call, $functions) && $firstArgument instanceof String_ ? $firstArgument->value : null;
    }

    private static function argument(FuncCall|MethodCall $call, int $position): ?Node
    {
        $argument = $call->args[$position] ?? null;

        return $argument instanceof Arg ? $argument->value : null;
    }

}
