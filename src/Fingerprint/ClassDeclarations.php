<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Error;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\ParserFactory;
use Symfony\Component\Filesystem\Path;

final class ClassDeclarations
{

    private const int MAX_FILE_BYTES = 524_288;

    private const string CLASS_SEGMENT = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var array<string, \PhpParser\Node\Stmt\ClassLike|null> */
    private array $byClass = [];

    /**
     * @param array<string, list<string>> $psr4
     */
    private function __construct(private readonly string $projectRoot, private readonly array $psr4)
    {
    }

    public static function forProject(string $projectRoot): self
    {
        $composer = is_file($projectRoot . '/composer.json')
            ? json_decode((string) file_get_contents($projectRoot . '/composer.json'), associative: true)
            : null;

        return new self($projectRoot, self::psr4Of($composer, 'autoload-dev', self::psr4Of($composer, 'autoload', [])));
    }

    /**
     * @return array{value: int|string|null}|null
     */
    public function enumCase(ClassConstFetch $fetch): ?array
    {
        $declaration = $this->declarationOf($fetch);
        $case = $declaration instanceof Enum_
            ? array_find(
                $declaration->stmts,
                static fn (Stmt $statement): bool => $statement instanceof EnumCase && self::names($fetch, $statement->name),
            )
            : null;

        return match (true) {
            !$case instanceof EnumCase => null,
            !$case->expr instanceof Expr => ['value' => null],
            $case->expr instanceof String_, $case->expr instanceof Int_ => ['value' => $case->expr->value],
            default => null,
        };
    }

    public function constant(ClassConstFetch $fetch): null|int|string
    {
        $constants = array_merge(...array_map(
            static fn (Stmt $statement): array => $statement instanceof ClassConst ? $statement->consts : [],
            $this->declarationOf($fetch)->stmts ?? [],
        ));
        $constant = array_find($constants, static fn (Const_ $constant): bool => self::names($fetch, $constant->name));

        return $constant?->value instanceof String_ || $constant?->value instanceof Int_ ? $constant->value->value : null;
    }

    /**
     * The names of the cases an enum declares, in the order of the declaration.
     *
     * @return list<string>|null
     */
    public function enumCaseNames(Name $class): ?array
    {
        $declaration = $this->declarationOfClass(self::resolvedName($class));

        return $declaration instanceof Enum_
            ? array_values(array_map(
                static fn (EnumCase $case): string => $case->name->toString(),
                array_filter($declaration->stmts, static fn (Stmt $statement): bool => $statement instanceof EnumCase),
            ))
            : null;
    }

    public static function className(ClassConstFetch $fetch): ?string
    {
        return $fetch->class instanceof Name ? self::resolvedName($fetch->class) : null;
    }

    public static function resolvedName(Name $class): ?string
    {
        $resolved = $class->getAttribute('resolvedName');

        return $resolved instanceof Name && !$class->isSpecialClassName() ? $resolved->toString() : null;
    }

    private function declaredIn(string $path, string $class): ?ClassLike
    {
        $shortName = substr((string) strrchr('\\' . $class, '\\'), 1);
        $namespace = substr($class, 0, max(0, strlen($class) - strlen($shortName) - 1));
        $declarations = array_merge(...array_map(
            static fn (Stmt $statement): array => match (true) {
                $statement instanceof Namespace_ => ($statement->name?->toString() ?? '') === $namespace ? $statement->stmts : [],
                default => $namespace === '' ? [$statement] : [],
            },
            $this->statementsOf($path),
        ));
        $declaration = array_find(
            $declarations,
            static fn (Stmt $declaration): bool => $declaration instanceof ClassLike && $declaration->name?->toString() === $shortName,
        );

        return $declaration instanceof ClassLike ? $declaration : null;
    }

    /**
     * @return array<\PhpParser\Node\Stmt>
     */
    private function statementsOf(string $path): array
    {
        try {
            return new ParserFactory()->createForHostVersion()->parse((string) file_get_contents($path)) ?? [];
        } catch (Error) {
            return [];
        }
    }

    private function declarationOf(ClassConstFetch $fetch): ?ClassLike
    {
        return $this->declarationOfClass(self::className($fetch));
    }

    private function declarationOfClass(?string $class): ?ClassLike
    {
        if ($class === null) {
            return null;
        }

        if (!array_key_exists($class, $this->byClass)) {
            $path = $this->pathOf($class);
            $this->byClass[$class] = $path === null ? null : $this->declaredIn($path, $class);
        }

        return $this->byClass[$class];
    }

    private function pathOf(string $class): ?string
    {
        if (!array_all(explode('\\', $class), static fn (string $segment): bool => preg_match(self::CLASS_SEGMENT, $segment) === 1)) {
            return null;
        }

        $prefixes = array_filter(array_keys($this->psr4), static fn (string $prefix): bool => str_starts_with($class, $prefix));
        usort($prefixes, static fn (string $first, string $second): int => strlen($second) <=> strlen($first));
        $relativePath = static fn (string $prefix): string => str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        $paths = array_merge(...array_map(
            fn (string $prefix): array => array_map(
                fn (string $directory): ?string => $this->guarded(Path::join($directory, $relativePath($prefix))),
                $this->psr4[$prefix],
            ),
            $prefixes,
        ));

        return array_find($paths, static fn (?string $path): bool => $path !== null);
    }

    private function guarded(string $relativePath): ?string
    {
        $root = realpath($this->projectRoot);
        $path = realpath(Path::join($this->projectRoot, $relativePath));

        return $root !== false
            && $path !== false
            && is_file($path)
            && str_starts_with($path, $root . DIRECTORY_SEPARATOR)
            && !str_starts_with($path, $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)
            && filesize($path) <= self::MAX_FILE_BYTES
            ? $path
            : null;
    }

    private static function names(ClassConstFetch $fetch, Identifier $name): bool
    {
        return $fetch->name instanceof Identifier && $fetch->name->toString() === $name->toString();
    }

    /**
     * @param array<string, list<string>> $psr4
     * @return array<string, list<string>>
     */
    private static function psr4Of(mixed $composer, string $section, array $psr4): array
    {
        $autoload = is_array($composer) && is_array($composer[$section] ?? null) ? $composer[$section] : [];
        $map = is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];

        foreach ($map as $prefix => $directories) {
            $directories = array_values(array_filter((array) $directories, is_string(...)));
            $psr4[(string) $prefix] = [...$psr4[(string) $prefix] ?? [], ...$directories];
        }

        return $psr4;
    }

}
