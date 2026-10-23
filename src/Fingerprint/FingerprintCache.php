<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use Pekral\TestRedundancy\Support\TestKey;

final class FingerprintCache
{

    /** @var array<string, array<string, array<string, int>>> */
    private array $byFile = [];

    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * @param list<string> $coveringTestKeys
     * @return list<string>|null
     */
    public function difference(string $testKey, array $coveringTestKeys): ?array
    {
        $tokens = $this->tokens($testKey, $coveringTestKeys);

        if ($tokens === null) {
            return null;
        }

        $smallest = null;

        foreach ($tokens['covering'] as $covering) {
            $difference = $this->exceeding($tokens['own'], $covering);

            if ($smallest === null || count($difference) < count($smallest)) {
                $smallest = $difference;
            }
        }

        return $smallest ?? array_keys($tokens['own']);
    }

    /**
     * @param list<string> $coveringTestKeys
     * @return list<string>|null
     */
    public function coveringDifference(string $testKey, array $coveringTestKeys): ?array
    {
        $tokens = $this->tokens($testKey, $coveringTestKeys);

        return $tokens === null ? null : $this->exceeding(TokenCollector::sum(...$tokens['covering']), $tokens['own']);
    }

    /**
     * @param list<string> $coveringTestKeys
     * @return array{own: array<string, int>, covering: list<array<string, int>>}|null
     */
    private function tokens(string $testKey, array $coveringTestKeys): ?array
    {
        $own = $this->of($testKey);

        if ($own === null) {
            return null;
        }

        $coveringTokens = [];

        foreach ($coveringTestKeys as $coveringTestKey) {
            $tokens = $this->of($coveringTestKey);

            if ($tokens === null) {
                return null;
            }

            $coveringTokens[] = $tokens;
        }

        return ['own' => $own, 'covering' => $coveringTokens];
    }

    /**
     * @param array<string, int> $tokens
     * @param array<string, int> $other
     * @return list<string>
     */
    private function exceeding(array $tokens, array $other): array
    {
        return array_keys(
            array_filter($tokens, static fn (int $count, string $token): bool => $count > ($other[$token] ?? 0), ARRAY_FILTER_USE_BOTH),
        );
    }

    /**
     * @return array<string, int>|null
     */
    private function of(string $testKey): ?array
    {
        $file = TestKey::file($testKey);
        $path = $this->projectRoot . DIRECTORY_SEPARATOR . $file;

        if (!is_file($path)) {
            return null;
        }

        $this->byFile[$file] ??= ContractFingerprints::of($path);

        return $this->byFile[$file][TestKey::testCase($testKey)] ?? null;
    }

}
