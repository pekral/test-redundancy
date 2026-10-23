<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Error;
use PhpParser\ParserFactory;

final class ContractFingerprints
{

    /**
     * @return array<string, array<string, int>>
     */
    public static function of(string $testFile): array
    {
        $statements = self::statements($testFile);

        return [...PestFingerprints::of($statements), ...PhpUnitFingerprints::of($statements)];
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

        return count([...PestFingerprints::of($statements), ...PhpUnitFingerprints::of($statements)]) === 1
            && PestFingerprints::declaresOnlyFingerprintedTests($statements)
            && !PhpUnitFingerprints::declaresDataset($statements);
    }

    /**
     * @return array<\PhpParser\Node\Stmt>
     */
    private static function statements(string $testFile): array
    {
        return new ParserFactory()->createForHostVersion()->parse((string) file_get_contents($testFile)) ?? [];
    }

}
