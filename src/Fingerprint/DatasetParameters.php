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
        'array' => self::VALUE_KINDS,
        'bool' => ['bool'],
        'callable' => self::VALUE_KINDS,
        'false' => ['bool'],
        'float' => ['int', 'float'],
        'int' => ['int'],
        'iterable' => self::VALUE_KINDS,
        'mixed' => self::VALUE_KINDS,
        'null' => ['null'],
        'string' => ['string'],
        'true' => ['bool'],
    ];

    private const array KIND_PATTERNS = [
        'array' => '/^value:(?:\[\]|list-item)$/',
        'bool' => '/^value:(?:true|false)$/',
        'float' => '/^value:-?\d+\.\d+(?:E[+-]\d+)?$/',
        'int' => '/^value:-?\d+$/',
        'null' => '/^value:null$/',
        'string' => '/^value:\'(?:[^\'\\\\]|\\\\.)*\'$/s',
    ];

    /**
     * @param array<string, list<string>> $kindsByPosition
     */
    private function __construct(private array $kindsByPosition)
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

        return new self($kindsByPosition);
    }

    public function takes(string $token): bool
    {
        return array_any($this->kindsByPosition, static function (array $kinds, string $position) use ($token): bool {
            $value = substr($token, strlen($position));

            return str_starts_with($token, $position) && !str_starts_with($value, 'in:') && self::takesKind($kinds, $value);
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

    /**
     * @param list<string> $kinds
     */
    private static function takesKind(array $kinds, string $value): bool
    {
        $kind = array_find_key(self::KIND_PATTERNS, static fn (string $pattern): bool => preg_match($pattern, $value) === 1);

        return $kind === null || in_array($kind, $kinds, strict: true);
    }

}
