<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\NodeFinder;

final class KeyOrders
{

    private const string PREFIX = 'keys:';

    private const string KEY_PATTERN = '/^((?:in:.*\/)?)key:(.+)$/';

    private const string PATTERN = '/^(in:.*\/)?' . self::PREFIX . '/';

    public static function isToken(string $token): bool
    {
        return preg_match(self::PATTERN, $token) === 1;
    }

    /**
     * @param list<string> $difference
     * @return list<string>
     */
    public static function withoutImplied(array $difference): array
    {
        $keys = [];

        foreach ($difference as $token) {
            if (preg_match(self::KEY_PATTERN, $token, $matches) === 1) {
                $keys[] = $matches;
            }
        }

        return array_values(array_filter(
            $difference,
            static fn (string $token): bool => !self::isToken($token) || !array_any(
                $keys,
                static fn (array $key): bool => str_starts_with($token, $key[1] . self::PREFIX . $key[2] . '>')
                    || (str_starts_with($token, $key[1] . self::PREFIX) && str_ends_with($token, '>' . $key[2])),
            ),
        ));
    }

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node> $nodes
     * @param array<int, list<string>> $paths
     * @param array<int, string> $readProperties
     * @return array<string, int>
     */
    public static function tokens(NodeFinder $nodeFinder, Node|array $nodes, array $paths, array $readProperties): array
    {
        $orders = [];

        foreach ($nodeFinder->findInstanceOf($nodes, Array_::class) as $array) {
            $keys = array_values(array_filter(array_map(
                static fn (ArrayItem $item): ?array => TokenCollector::keyOf($item, $readProperties),
                $array->items,
            )));

            foreach ($keys as $position => [$node, $keyToken]) {
                foreach (array_slice($keys, 0, $position) as [, $earlierToken]) {
                    $orderToken = NodeContext::pathPrefix($paths, $node) . self::PREFIX . $earlierToken . '>' . $keyToken;
                    $orders[$orderToken] = ($orders[$orderToken] ?? 0) + 1;
                }
            }
        }

        return $orders;
    }

}
