<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\UnionType;

final readonly class DatasetParameters
{

    private const string SENTINEL = 'test-redundancy:parameter:';

    private const string SENTINEL_TOKEN_PATTERN = '/^(?<position>.*)value:\'' . self::SENTINEL . '(?<parameter>\d+)\'$/s';

    private const array VALUE_KINDS = ['null', 'bool', 'int', 'float', 'string', 'array'];

    private const array KINDS_BY_TYPE = [
        'array' => ['array'],
        'bool' => ['bool'],
        'callable' => ['string', 'array'],
        'false' => ['bool'],
        'float' => ['int', 'float'],
        'int' => ['int'],
        'iterable' => ['array'],
        'mixed' => self::VALUE_KINDS,
        'null' => ['null'],
        'string' => ['string'],
        'true' => ['bool'],
    ];

    private const array SCALAR_KIND_PATTERNS = [
        'bool' => '/^value:(?:true|false)$/',
        'float' => '/^value:-?\d+\.\d+(?:E[+-]\d+)?$/',
        'int' => '/^value:-?\d+$/',
        'null' => '/^value:null$/',
        'string' => '/^value:\'(?:[^\'\\\\]|\\\\.)*\'$/s',
    ];

    private const string ARRAY_VALUE_PATTERN = '/^(?:key:|value:(?:\[\]|list-item)$|value:.+\/)/s';

    /**
     * @param array<string, list<string>> $kindsByPosition
     * @param list<string> $arrayPositions
     */
    private function __construct(private array $kindsByPosition, private array $arrayPositions)
    {
    }

    /**
     * @param list<string> $sentinelRowTokens
     * @param array<\PhpParser\Node\Param> $parameters
     */
    public static function fromSentinelRowTokens(array $sentinelRowTokens, array $parameters): self
    {
        $parameters = array_values($parameters);
        $kindsByPosition = [];

        foreach ($sentinelRowTokens as $token) {
            if (preg_match(self::SENTINEL_TOKEN_PATTERN, $token, $matches) === 1) {
                $kindsByPosition[$matches['position']] = [
                    ...$kindsByPosition[$matches['position']] ?? [],
                    ...self::kinds($parameters[(int) $matches['parameter']]),
                ];
            }
        }

        return new self($kindsByPosition, arrayPositions: []);
    }

    /**
     * @param list<string> $candidateTokens
     */
    public function forCandidate(array $candidateTokens): self
    {
        return new self($this->kindsByPosition, array_values(array_filter(
            array_keys($this->kindsByPosition),
            static fn (string $position): bool => array_any(
                $candidateTokens,
                static fn (string $token): bool => self::kindOf(self::valueAt($position, $token) ?? '') === 'array',
            ),
        )));
    }

    public function takes(string $token): bool
    {
        return array_any($this->kindsByPosition, function (array $kinds, string $position) use ($token): bool {
            $value = self::valueAt($position, $token);

            if ($value === null) {
                return false;
            }

            $kind = in_array($position, $this->arrayPositions, strict: true) ? 'array' : self::kindOf($value);

            return $kind === null || in_array($kind, $kinds, strict: true);
        });
    }

    /**
     * @param array<\PhpParser\Node\Param> $parameters
     */
    public static function sentinelRow(array $parameters): Array_
    {
        return new Array_(array_map(
            static fn (int $parameter): ArrayItem => new ArrayItem(new String_(self::SENTINEL . $parameter)),
            array_keys(array_values($parameters)),
        ));
    }

    /**
     * @return list<string>
     */
    private static function kinds(Param $parameter): array
    {
        return $parameter->type === null ? self::VALUE_KINDS : self::kindsOfType($parameter->type);
    }

    /**
     * @return list<string>
     */
    private static function kindsOfType(Identifier|Name|ComplexType $type): array
    {
        return match (true) {
            $type instanceof NullableType => ['null', ...self::kindsOfType($type->type)],
            $type instanceof UnionType => array_merge(...array_map(self::kindsOfType(...), $type->types)),
            $type instanceof Identifier => self::KINDS_BY_TYPE[$type->toLowerString()] ?? [],
            default => [],
        };
    }

    private static function valueAt(string $position, string $token): ?string
    {
        $value = substr($token, strlen($position));

        return str_starts_with($token, $position) && !str_starts_with($value, 'in:') ? $value : null;
    }

    private static function kindOf(string $value): ?string
    {
        return array_find_key(self::SCALAR_KIND_PATTERNS, static fn (string $pattern): bool => preg_match($pattern, $value) === 1)
            ?? (preg_match(self::ARRAY_VALUE_PATTERN, $value) === 1 ? 'array' : null);
    }

}
