<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

use Pekral\TestRedundancy\Fingerprint\NonLiteralArguments;
use Pekral\TestRedundancy\Fingerprint\TokenKind;

final readonly class InputCombination
{

    private const string KEY_PATTERN = '~^((?:in:.*/)?)key:(.+)$~';

    /**
     * @param list<string> $coveringOnly the tokens only the covering test has, as coveringTokensForReview lists them
     * @param list<string> $coveringKeys
     * @param list<string> $coveringArguments
     */
    public function __construct(private array $coveringOnly, private array $coveringKeys, private array $coveringArguments)
    {
    }

    public function isOtherInput(): bool
    {
        return $this->coveringOnly !== []
            && array_diff($this->coveringOnly, $this->coveringKeys, $this->coveringArguments) === []
            && array_all($this->coveringOnly, $this->isLiteral(...));
    }

    /**
     * @param list<string> $differsBy
     */
    public function differsOnlyInKeysOfTheArraysIn(array $differsBy): bool
    {
        $keys = $this->keyPaths();

        return $differsBy !== []
            && $keys !== []
            && array_all($this->coveringOnly, static fn (string $token): bool => array_any(
                $keys,
                static fn (array $key): bool => str_starts_with($token, $key[0] . 'key:')
                    || str_starts_with($token, $key[0] . $key[1] . '/'),
            ))
            && array_all($differsBy, static fn (string $token): bool => array_any(
                $keys,
                static fn (array $key): bool => $key[0] !== '' && str_starts_with($token, $key[0]),
            ));
    }

    /**
     * @return list<array{string, string}>
     */
    private function keyPaths(): array
    {
        $paths = [];

        foreach ($this->coveringKeys as $key) {
            if (preg_match(self::KEY_PATTERN, $key, $matches) === 1) {
                $paths[] = [$matches[1], $matches[2]];
            }
        }

        return $paths;
    }

    private function isLiteral(string $token): bool
    {
        return TokenKind::isData($token) && !NonLiteralArguments::isToken($token) && !str_contains($token, 'cast:');
    }

}
