<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

final readonly class CoverageResult
{

    /**
     * @param array<string, array<string, true>> $linesByTest
     * @param array<string, string> $failedTests
     */
    public function __construct(public array $linesByTest, public array $failedTests)
    {
    }

}
