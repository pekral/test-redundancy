<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use Pekral\TestRedundancy\Support\TestKey;

final class FingerprintCache
{

    /** @var array<string, \Pekral\TestRedundancy\Fingerprint\FileFingerprints> */
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
            $difference = ArrayOrders::withoutImplied($this->exceeding($tokens['own'], $covering), $covering);

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

        return $tokens === null ? null : array_values(array_filter(
            $this->exceeding(TokenCollector::sum(...$tokens['covering']), $tokens['own']),
            static fn (string $token): bool => !ArrayOrders::isToken($token),
        ));
    }

    public function sharedDataset(string $testKey): ?SharedDataset
    {
        return $this->fileOf($testKey)?->sharedDatasets[TestKey::withoutDatasetRow(TestKey::testCase($testKey))] ?? null;
    }

    /**
     * @param list<string> $coveringTestKeys
     */
    public function datasetParameters(string $testKey, array $coveringTestKeys): ?DatasetParameters
    {
        $parameters = count($coveringTestKeys) === 1
            ? $this->fileOf($coveringTestKeys[0])?->datasetParameters[TestKey::testCase($coveringTestKeys[0])] ?? null
            : null;

        return $parameters?->forCandidate(array_keys($this->of($testKey) ?? []));
    }

    public function assertsNothing(string $testKey): bool
    {
        return array_filter(array_keys($this->of($testKey) ?? []), TokenCollector::isAssertionToken(...)) === [];
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
        return $this->fileOf($testKey)?->fingerprints[TestKey::testCase($testKey)] ?? null;
    }

    private function fileOf(string $testKey): ?FileFingerprints
    {
        $file = TestKey::file($testKey);
        $path = $this->projectRoot . DIRECTORY_SEPARATOR . $file;

        if (!is_file($path)) {
            return null;
        }

        return $this->byFile[$file] ??= ContractFingerprints::analyse($path);
    }

}
