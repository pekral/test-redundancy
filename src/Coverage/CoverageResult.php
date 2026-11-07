<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

use Pekral\TestRedundancy\Support\TestKey;

final readonly class CoverageResult
{

    /**
     * @param array<string, array<string, true>> $linesByTest
     * @param array<string, string> $failedTests
     * @param array<string, float> $seconds
     * @param list<string> $passedOnRetry
     */
    public function __construct(public array $linesByTest, public array $failedTests, public array $seconds, public array $passedOnRetry)
    {
    }

    public static function empty(): self
    {
        return new self([], [], [], []);
    }

    /**
     * @param list<string> $retriedFiles
     */
    public function withRetry(self $retry, array $retriedFiles): self
    {
        return new self(
            [...$this->notRetried($this->linesByTest, $retriedFiles), ...$retry->linesByTest],
            [...$this->notRetried($this->failedTests, $retriedFiles), ...$retry->failedTests],
            [...$this->notRetried($this->seconds, $retriedFiles), ...$retry->seconds],
            array_values(array_filter(
                array_keys($this->failedTests),
                static fn (string $testKey): bool => in_array(TestKey::file($testKey), $retriedFiles, strict: true)
                    && !isset($retry->failedTests[$testKey])
                    && isset($retry->linesByTest[$testKey]),
            )),
        );
    }

    /**
     * @template T
     * @param array<string, T> $values
     * @param list<string> $retriedFiles
     * @return array<string, T>
     */
    private function notRetried(array $values, array $retriedFiles): array
    {
        return array_filter(
            $values,
            static fn (string $testKey): bool => !in_array(TestKey::file($testKey), $retriedFiles, strict: true),
            ARRAY_FILTER_USE_KEY,
        );
    }

}
