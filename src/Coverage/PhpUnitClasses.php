<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

final class PhpUnitClasses
{

    /** @var array<string, array{file: string, methods: array<string, string>}|null> */
    private array $byClass = [];

    /**
     * @param list<string> $testFiles absolute paths of the test files of one run
     */
    public function __construct(private readonly array $testFiles)
    {
    }

    /**
     * @return array{file: string, methods: array<string, string>}|null
     */
    public function of(string $class): ?array
    {
        if (!array_key_exists($class, $this->byClass)) {
            $this->byClass[$class] = $this->find($class);
        }

        return $this->byClass[$class];
    }

    public static function normalized(string $name): string
    {
        return (string) preg_replace('/^test/', '', strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $name)));
    }

    /**
     * @return array{file: string, methods: array<string, string>}|null
     */
    private function find(string $class): ?array
    {
        $shortName = substr((string) strrchr('\\' . $class, '\\'), 1);

        foreach ($this->testFiles as $testFile) {
            $declaration = basename($testFile) === $shortName . '.php' ? $this->declaration($testFile, $class) : null;

            if ($declaration !== null) {
                return ['file' => $testFile, 'methods' => $declaration];
            }
        }

        return null;
    }

    /**
     * @return array<string, string>|null
     */
    private function declaration(string $testFile, string $class): ?array
    {
        $statements = new ParserFactory()->createForHostVersion()->parse((string) file_get_contents($testFile)) ?? [];
        $nodeFinder = new NodeFinder();

        foreach ($nodeFinder->findInstanceOf($statements, Class_::class) as $declared) {
            $namespace = $nodeFinder->findFirst(
                $statements,
                static fn ($node): bool => $node instanceof Namespace_ && in_array($declared, $node->stmts, strict: true),
            );
            $name = ($namespace instanceof Namespace_ && $namespace->name instanceof Name ? $namespace->name->toString() . '\\' : '')
                . $declared->name?->toString();

            if ($name === $class) {
                $methods = array_map(static fn (ClassMethod $method): string => $method->name->toString(), $declared->getMethods());

                return array_combine(array_map(self::normalized(...), $methods), $methods);
            }
        }

        return null;
    }

}
