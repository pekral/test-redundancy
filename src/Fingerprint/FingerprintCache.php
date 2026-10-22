<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use Pekral\TestRedundancy\Support\TestKey;

final class FingerprintCache
{

    /** @var array<string, array<string, array<string, true>>> */
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

            $coveringTokens += $tokens;
        }

        return array_keys(array_diff_key($own, $coveringTokens));
    }

    /**
     * @return array<string, true>|null
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
