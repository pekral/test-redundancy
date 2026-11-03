<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use Closure;
use PhpParser\Error;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Const_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\ParserFactory;

final class ContractFingerprints
{

    private const array DECLARATIONS = [
        Use_::class, GroupUse::class, Const_::class, Function_::class, Enum_::class,
        Interface_::class, Trait_::class, Nop::class,
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
        $statements = self::parsedStatements($testFile);

        return $statements !== null
            && count(self::fingerprintsOf($statements)) === 1
            && PestFingerprints::declaresOnlyFingerprintedTests($statements)
            && !PhpUnitFingerprints::declaresDataset($statements);
    }

    /**
     * @param \Closure(string): bool|null $extendsNoTestCase
     */
    public static function declaresNoTestCase(string $testFile, ?Closure $extendsNoTestCase = null): bool
    {
        $statements = self::parsedStatements($testFile);

        return $statements !== null
            && self::fingerprintsOf($statements) === []
            && (self::onlyDeclares($statements, parentsAllowed: false)
                || ($extendsNoTestCase instanceof Closure
                    && self::onlyDeclares($statements, parentsAllowed: true)
                    && $extendsNoTestCase($testFile)));
    }

    /**
     * @return array<\PhpParser\Node\Stmt>|null
     */
    private static function parsedStatements(string $testFile): ?array
    {
        if (!is_file($testFile)) {
            return null;
        }

        try {
            return self::statements($testFile);
        } catch (Error) {
            return null;
        }
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $statements
     */
    private static function onlyDeclares(array $statements, bool $parentsAllowed): bool
    {
        return array_all($statements, static fn (Stmt $statement): bool => match (true) {
            $statement instanceof Namespace_ => self::onlyDeclares($statement->stmts, $parentsAllowed),
            $statement instanceof Declare_ => self::onlyDeclares($statement->stmts ?? [], $parentsAllowed),
            $statement instanceof Class_ => ($parentsAllowed || !$statement->extends instanceof Name) && $statement->getTraitUses() === [],
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
