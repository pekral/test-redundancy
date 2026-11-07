<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Return_;

final readonly class FileDatasets
{

    private const string ENUM_VALUE = 'value';

    private const string ENUM_VALUE_SUFFIX = '->' . self::ENUM_VALUE;

    /** @var array<string, \PhpParser\Node\Stmt\Function_> */
    private array $functions;

    /** @var array<string, string> */
    private array $keyValues;

    /**
     * @param array<string, \PhpParser\Node> $namedDatasets
     * @param array<\PhpParser\Node\Stmt> $statements
     */
    public function __construct(private array $namedDatasets, array $statements, private ?ClassDeclarations $declarations = null)
    {
        $functions = [];
        $keyValues = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Function_) {
                $functions[$statement->name->toLowerString()] = $statement;
            }

            if ($statement instanceof ClassLike && $statement->name instanceof Identifier) {
                $keyValues = [...$keyValues, ...$this->keyValues($statement->name->toLowerString(), $statement->stmts)];
            }
        }

        $this->functions = $functions;
        $this->keyValues = $keyValues;
    }

    /**
     * @param list<\PhpParser\Node\Expr> $datasets
     * @return array<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_|null>
     */
    public function combinedRows(array $datasets): array
    {
        return count($datasets) > 1
            ? DatasetCombination::rows(array_map($this->rows(...), $datasets), $this->rowName(...))
            : $this->rows($datasets[0] ?? null);
    }

    public function rowName(Expr $row): ?string
    {
        return PestRowName::of($row, $this->declarations);
    }

    /**
     * @return array<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_|null>
     */
    public function rows(?Node $dataset): array
    {
        $rows = $this->declaredRows($dataset instanceof String_ ? $this->namedDatasets[$dataset->value] ?? null : $dataset);

        return array_map($this->withStringKey(...), $rows);
    }

    /**
     * @return array<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_|null>
     */
    private function declaredRows(?Node $dataset): array
    {
        return match (true) {
            $dataset instanceof Array_ => $dataset->items,
            $dataset instanceof FuncCall => $this->functionRows($dataset),
            $dataset instanceof Closure, $dataset instanceof ArrowFunction => $this->literalRows($dataset),
            default => [],
        };
    }

    /**
     * @return array<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_|null>
     */
    private function functionRows(FuncCall $call): array
    {
        $function = $call->name instanceof Name && $call->args === [] ? $this->functions[$call->name->toLowerString()] ?? null : null;

        return !$function instanceof Function_ ? [] : $this->literalRows($function);
    }

    private function withStringKey(null|ArrayItem|Yield_ $row): null|ArrayItem|Yield_
    {
        $name = $this->keyValue($row?->key);

        if ($row === null || $name === null || preg_match(PestRowName::CONTROL_CHARACTERS, $name) === 1) {
            return $row;
        }

        $named = clone $row;
        $named->key = new String_($name);

        return $named;
    }

    private function keyValue(?Expr $key): ?string
    {
        $constant = match (true) {
            $key instanceof ClassConstFetch => $this->constantName($key),
            $key instanceof PropertyFetch && $key->var instanceof ClassConstFetch && $key->name instanceof Identifier
                && $key->name->toString() === self::ENUM_VALUE => $this->constantName($key->var) . self::ENUM_VALUE_SUFFIX,
            default => null,
        };

        return $constant === null ? null : $this->keyValues[$constant] ?? $this->declaredKeyValue($key);
    }

    private function declaredKeyValue(?Expr $key): ?string
    {
        $enumCase = $key instanceof PropertyFetch && $key->var instanceof ClassConstFetch
            ? $this->declarations?->enumCase($key->var)
            : null;
        $value = $key instanceof ClassConstFetch ? $this->declarations?->constant($key) : $enumCase['value'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<\PhpParser\Node\ArrayItem|\PhpParser\Node\Expr\Yield_|null>
     */
    private function literalRows(FunctionLike $function): array
    {
        $statements = $function->getStmts() ?? [];
        $returned = count($statements) === 1 && $statements[0] instanceof Return_ ? $statements[0]->expr : null;
        $yields = array_map(
            static fn (Stmt $statement): ?Yield_ => $statement instanceof Expression && $statement->expr instanceof Yield_
                ? $statement->expr
                : null,
            $statements,
        );

        return match (true) {
            $returned instanceof Array_ => $returned->items,
            in_array(needle: null, haystack: $yields, strict: true) => [],
            default => $yields,
        };
    }

    private function constantName(ClassConstFetch $constant): string
    {
        return $constant->class instanceof Name && $constant->name instanceof Identifier
            ? $constant->class->toLowerString() . '::' . $constant->name->toString()
            : '';
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     * @return array<string, string>
     */
    private function keyValues(string $class, array $statements): array
    {
        return array_merge(...array_map(static fn (Stmt $statement): array => self::declaredValues($class, $statement), $statements));
    }

    /**
     * @return array<string, string>
     */
    private static function declaredValues(string $class, Stmt $statement): array
    {
        return match (true) {
            $statement instanceof EnumCase && $statement->expr instanceof String_ => [
                $class . '::' . $statement->name->toString() . self::ENUM_VALUE_SUFFIX => $statement->expr->value,
            ],
            $statement instanceof ClassConst => array_merge(...array_map(
                static fn (Const_ $constant): array => $constant->value instanceof String_
                    ? [$class . '::' . $constant->name->toString() => $constant->value->value]
                    : [],
                $statement->consts,
            )),
            default => [],
        };
    }

}
