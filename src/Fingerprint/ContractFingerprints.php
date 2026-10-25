<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Error;
use PhpParser\ParserFactory;

final class ContractFingerprints
{

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
