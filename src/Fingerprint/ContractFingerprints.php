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
        $pestTests = PestFingerprints::tests($statements);

        return new FileFingerprints(
            [...PestFingerprints::fingerprints($pestTests), ...PhpUnitFingerprints::of($statements)],
            PestFingerprints::sharedDatasets($pestTests),
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
