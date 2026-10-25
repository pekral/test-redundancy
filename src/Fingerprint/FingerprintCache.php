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
            $exceeding = OrderTokens::withoutImplied($this->exceeding($tokens['own'], $covering), $covering);
            $difference = NonLiteralArguments::withoutImplied($exceeding, $exceeding);

            if ($smallest === null || count($difference) < count($smallest)) {
                $smallest = $difference;
            }
        }

        return $smallest ?? array_values(array_filter(
            array_keys($tokens['own']),
            static fn (string $token): bool => !TokenCollector::isReadToken($token),
        ));
    }

    /**
     * @param list<string> $coveringTestKeys
     * @return list<string>|null
     */
    public function coveringDifference(string $testKey, array $coveringTestKeys): ?array
    {
        $tokens = $this->tokens($testKey, $coveringTestKeys);

        if ($tokens === null) {
            return null;
        }

        $covering = TokenCollector::sum(...$tokens['covering']);
        $exceeding = array_values(array_filter(
            $this->exceeding($covering, $tokens['own']),
            static fn (string $token): bool => !OrderTokens::isToken($token),
        ));
        $onlyAssertionsRead = array_filter(
            $exceeding,
            fn (string $token): bool => $this->isOnlyRead($covering, $tokens['own'], $token),
        );

        $arranged = array_values(array_diff($exceeding, $onlyAssertionsRead));

        return array_map(
            static fn (string $token): string => in_array($token, $onlyAssertionsRead, strict: true)
                ? TokenCollector::readToken($token)
                : $token,
            NonLiteralArguments::withoutImplied($this->withoutReadPositions($exceeding, $onlyAssertionsRead), $arranged),
        );
    }

    /**
     * @param list<string> $coveringTestKeys
     * @return list<string>
     */
    public function omittedArguments(string $testKey, array $coveringTestKeys): array
    {
        $tokens = $this->tokens($testKey, $coveringTestKeys);
        $covering = array_keys(TokenCollector::sum(...$tokens['covering'] ?? []));
        $isOmitted = static fn (?string $position): bool => $position !== null
            && NonLiteralArguments::isCallOfPositionMadeBy($position, $covering)
            && !NonLiteralArguments::isPositionFilledBy($position, $covering);

        return array_values(array_filter(
            array_keys($tokens['own'] ?? []),
            static fn (string $token): bool => $isOmitted(NonLiteralArguments::argumentPositionOf($token)),
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
        return array_keys(array_filter(
            $tokens,
            static fn (int $count, string $token): bool => !TokenCollector::isReadToken($token) && $count > ($other[$token] ?? 0),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * @param list<string> $exceeding
     * @param array<int, string> $onlyAssertionsRead
     * @return list<string>
     */
    private function withoutReadPositions(array $exceeding, array $onlyAssertionsRead): array
    {
        return array_values(array_filter(
            $exceeding,
            static fn (string $token): bool => !NonLiteralArguments::isToken($token)
                || !in_array($token, $onlyAssertionsRead, strict: true),
        ));
    }

    /**
     * @param array<string, int> $covering
     * @param array<string, int> $own
     */
    private function isOnlyRead(array $covering, array $own, string $token): bool
    {
        $call = NonLiteralArguments::callOf($token);

        return ($call === null || array_any(
            [$call, 'loop:' . $call],
            static fn (string $made): bool => ($covering[$made] ?? 0) > ($own[$made] ?? 0),
        )) && $this->arranged($covering, $token) <= $this->arranged($own, $token);
    }

    /**
     * @param array<string, int> $counts
     */
    private function arranged(array $counts, string $token): int
    {
        return ($counts[$token] ?? 0) - ($counts[TokenCollector::readToken($token)] ?? 0);
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
