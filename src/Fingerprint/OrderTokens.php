<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\Cast\Double;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

final class OrderTokens
{

    private const string KEYS_PREFIX = 'keys:';

    private const string ITEMS_PREFIX = 'items:';

    private const string CALLS_PREFIX = 'calls:';

    private const string PATTERN = '~^(?:in:(?:[^\'/]|\'(?:[^\'\\\\]|\\\\.)*\'|/)*/)?('
        . self::KEYS_PREFIX . '|' . self::ITEMS_PREFIX . '|' . self::CALLS_PREFIX . ')~';

    public static function isToken(string $token): bool
    {
        return self::prefixOf($token) !== null;
    }

    /**
     * @param list<string> $difference
     * @param array<string, int> $covering
     * @return list<string>
     */
    public static function withoutImplied(array $difference, array $covering): array
    {
        $keys = [];

        foreach ($difference as $token) {
            if (preg_match(TokenCollector::KEY_TOKEN_PATTERN, $token, $matches) === 1) {
                $keys[] = [($matches[1] ?? '') . self::KEYS_PREFIX, substr($token, strlen($matches[0]))];
            }
        }

        return array_values(array_filter(
            $difference,
            static function (string $token) use ($keys, $covering): bool {
                $prefix = self::prefixOf($token);

                return match (true) {
                    $prefix === null => true,
                    str_ends_with($prefix, self::KEYS_PREFIX) => !array_any(
                        $keys,
                        static fn (array $key): bool => str_starts_with($token, $key[0] . $key[1] . '>')
                            || (str_starts_with($token, $key[0]) && str_ends_with($token, '>' . $key[1])),
                    ),
                    default => self::isReversedIn($prefix, substr($token, strlen($prefix)), $covering),
                };
            },
        ));
    }

    /**
     * @param \PhpParser\Node|array<\PhpParser\Node> $nodes
     * @param array<int, list<string>> $paths
     * @param array<int, string> $readProperties
     * @return array<string, int>
     */
    public static function ofArrays(NodeFinder $nodeFinder, Node|array $nodes, array $paths, array $readProperties): array
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
            $orders = TokenCollector::sum($orders, self::pairs(array_map(
                static fn (array $orderedNode): array => [NodeContext::pathPrefix($paths, $orderedNode[0]) . $prefix, $orderedNode[1]],
                $orderedNodes,
            )));
        }

        return $orders;
    }

    /**
     * @param array<\PhpParser\Node> $statements
     * @return array<string, int>
     */
    public static function ofArrangingCalls(array $statements): array
    {
        return self::pairs(array_map(
            static fn (Expr $call): array => [self::CALLS_PREFIX, self::sourceOf($call)],
            array_values(array_filter(array_map(self::arrangingCallOf(...), $statements))),
        ));
    }

    /**
     * @param list<array{0: string, 1: string}> $orderedNames
     * @return array<string, int>
     */
    private static function pairs(array $orderedNames): array
    {
        $orders = [];

        foreach ($orderedNames as $position => [$prefix, $name]) {
            foreach (array_slice($orderedNames, 0, $position) as [, $earlierName]) {
                $orderToken = $prefix . $earlierName . '>' . $name;
                $orders[$orderToken] = ($orders[$orderToken] ?? 0) + 1;
            }
        }

        return $orders;
    }

    private static function arrangingCallOf(Node $statement): ?Expr
    {
        $expression = $statement instanceof Expression ? $statement->expr : null;
        $call = $expression instanceof Assign ? $expression->expr : $expression;

        return $call instanceof CallLike
            && !NodeContext::isAssertionCall($call)
            && new NodeFinder()->findFirst($call, TokenCollector::isData(...)) !== null
            ? $call
            : null;
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
        if (self::isComposite($item->value)) {
            return [$item->value, self::sourceOf($item->value)];
        }

        $element = TokenCollector::nameOf($item->value, $readProperties);
        $name = NonLiteralArguments::identity($item->value);

        return match (true) {
            $element !== null && TokenCollector::carriesContract($element[0]) => $element,
            $name !== null => [$item->value, $name],
            default => null,
        };
    }

    private static function sourceOf(Expr $element): string
    {
        return new Standard()->prettyPrint(new NodeTraverser(new CloningVisitor(), new class () extends NodeVisitorAbstract {

            public function leaveNode(Node $node): ?Node
            {
                $node->setAttributes($node instanceof Double ? ['kind' => Double::KIND_FLOAT] : []);

                return $node instanceof ConstFetch ? new ConstFetch(new Name($node->name->toLowerString())) : null;
            }

        })->traverse([$element]));
    }

    /**
     * @param array<string, int> $covering
     */
    private static function isReversedIn(string $prefix, string $pair, array $covering): bool
    {
        return array_any(
            array_keys(str_split($pair), '>', strict: true),
            static fn (int $position): bool => isset($covering[$prefix . substr($pair, $position + 1) . '>' . substr($pair, 0, $position)]),
        );
    }

    private static function prefixOf(string $token): ?string
    {
        return preg_match(self::PATTERN, $token, $matches) === 1 ? $matches[0] : null;
    }

    private static function isComposite(Expr $element): bool
    {
        return ($element instanceof Array_ && $element->items !== [])
            || ($element instanceof CallLike && $element->getRawArgs() !== [])
            || $element instanceof Cast;
    }

}
