<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\NodeFinder;

final class ArrayOrders
{

    private const string KEYS_PREFIX = 'keys:';

    private const string ITEMS_PREFIX = 'items:';

    private const string PATTERN = "~^(?:in:(?:[^'/]|'(?:[^'\\\\]|\\\\.)*'|/)*/)?(?:" . self::KEYS_PREFIX . '|' . self::ITEMS_PREFIX . ')~';

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
        $ordered = [];

        foreach ($difference as $token) {
            if (preg_match(TokenCollector::KEY_TOKEN_PATTERN, $token, $matches) === 1) {
                $ordered[] = [($matches[1] ?? '') . self::KEYS_PREFIX, substr($token, strlen($matches[0]))];
            } elseif (preg_match(TokenCollector::DATA_TOKEN_PATTERN, $token, $matches) === 1) {
                $ordered[] = [$matches[1] . self::ITEMS_PREFIX, substr($token, strlen($matches[1]))];
            }
        }

        return array_values(array_filter(
            $difference,
            static fn (string $token): bool => !self::isToken($token) || !array_any(
                $ordered,
                static fn (array $node): bool => str_starts_with($token, $node[0] . $node[1] . '>')
                    || (str_starts_with($token, $node[0]) && str_ends_with($token, '>' . $node[1])),
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
            [$prefix, $orderedNodeOf] = TokenCollector::listLength($array) > 0
                ? [self::ITEMS_PREFIX, self::elementOf(...)]
                : [self::KEYS_PREFIX, self::keyOf(...)];
            $orderedNodes = array_values(array_filter(array_map(
                static fn (ArrayItem $item): ?array => $orderedNodeOf($item, $readProperties),
                $array->items,
            )));

            foreach ($orderedNodes as $position => [$node, $token]) {
                foreach (array_slice($orderedNodes, 0, $position) as [, $earlierToken]) {
                    $orderToken = NodeContext::pathPrefix($paths, $node) . $prefix . $earlierToken . '>' . $token;
                    $orders[$orderToken] = ($orders[$orderToken] ?? 0) + 1;
                }
            }
        }

        return $orders;
    }

    /**
     * @param array<int, string> $readProperties
     * @return array{0: \PhpParser\Node, 1: string}|null
     */
    private static function keyOf(ArrayItem $item, array $readProperties): ?array
    {
        return TokenCollector::nameOf($item->key, $readProperties);
    }

    /**
     * @param array<int, string> $readProperties
     * @return array{0: \PhpParser\Node, 1: string}|null
     */
    private static function elementOf(ArrayItem $item, array $readProperties): ?array
    {
        $element = TokenCollector::nameOf($item->value, $readProperties);

        return $element !== null && TokenCollector::carriesContract($element[0]) ? $element : null;
    }

}
