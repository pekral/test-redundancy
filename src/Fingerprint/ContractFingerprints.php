<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Error;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\ParserFactory;

final class ContractFingerprints
{

    private const array DECLARATIONS = [
        Stmt\Use_::class, Stmt\GroupUse::class, Stmt\Const_::class, Stmt\Function_::class, Stmt\Enum_::class,
        Stmt\Interface_::class, Stmt\Trait_::class, Stmt\Nop::class,
    ];

    public static function analyse(string $testFile): FileFingerprints
    {
        $statements = self::statements($testFile);
        $pest = PestFingerprints::analyse($statements);
        $phpUnit = PhpUnitFingerprints::analyse($statements);

        return new FileFingerprints(
            [...$pest->fingerprints, ...$phpUnit->fingerprints],
            $pest->sharedDatasets,
            [...$pest->datasetParameters, ...$phpUnit->datasetParameters],
        );
    }

    public static function declaresSingleTestCase(string $testFile): bool
    {
        if (!is_file($testFile)) {
            return false;
        }

        try {
            $statements = self::statements($testFile);
        } catch (Error) {
            return false;
        }

        return count(self::fingerprintsOf($statements)) === 1
            && PestFingerprints::declaresOnlyFingerprintedTests($statements)
            && !PhpUnitFingerprints::declaresDataset($statements);
    }

    /**
     * A file that only declares an enum, an interface, a trait, a function or a class that has no test method, neither
     * its own nor an inherited one. The test runner finds no test in it, and PHPUnit stops on it. A file that runs any
     * other statement, such as a Pest `it()` or a call that registers tests, may declare a test case.
     */
    public static function declaresNoTestCase(string $testFile): bool
    {
        if (!is_file($testFile)) {
            return false;
        }

        try {
            $statements = self::statements($testFile);
        } catch (Error) {
            return false;
        }

        return self::fingerprintsOf($statements) === [] && self::onlyDeclares($statements);
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     */
    private static function onlyDeclares(array $statements): bool
    {
        return array_all($statements, static fn (Stmt $statement): bool => match (true) {
            $statement instanceof Namespace_ => self::onlyDeclares($statement->stmts),
            $statement instanceof Declare_ => self::onlyDeclares($statement->stmts ?? []),
            $statement instanceof Class_ => $statement->extends === null && $statement->getTraitUses() === [],
            default => array_any(self::DECLARATIONS, static fn (string $declaration): bool => $statement instanceof $declaration),
        });
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     * @return array<string, array<string, int>>
     */
    private static function fingerprintsOf(array $statements): array
    {
        return [...PestFingerprints::of($statements), ...PhpUnitFingerprints::of($statements)];
    }

    /**
     * @return array<\PhpParser\Node\Stmt>
     */
    private static function statements(string $testFile): array
    {
        return new ParserFactory()->createForHostVersion()->parse((string) file_get_contents($testFile)) ?? [];
    }

}
