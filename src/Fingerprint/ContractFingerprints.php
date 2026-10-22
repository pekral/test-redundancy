<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\ParserFactory;

final class ContractFingerprints
{

    /**
     * @return array<string, array<string, true>>
     */
    public static function of(string $testFile): array
    {
        $statements = (new ParserFactory())->createForHostVersion()->parse((string) file_get_contents($testFile)) ?? [];

        return [...PestFingerprints::of($statements), ...PhpUnitFingerprints::of($statements)];
    }

}
