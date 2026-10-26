<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

use PhpParser\Node\Expr\Cast;
use PhpParser\NodeFinder;

final class CastToken
{

    public static function of(Cast $cast, ?string $operandToken = null): string
    {
        return 'cast:' . str_replace('double', 'float', NodeContext::kindOf($cast))
            . ($operandToken === null ? '' : '/' . $operandToken);
    }

    /**
     * @param array<int, string> $readProperties
     */
    public static function withOperandName(Cast $cast, array $readProperties): string
    {
        return self::of($cast, TokenCollector::nameOf($cast->expr, $readProperties)[1] ?? null);
    }

    /**
     * @return array<int, string>
     */
    public static function segments(NodeFinder $nodeFinder, Cast $cast): array
    {
        return array_fill_keys(NodeContext::nodeIds($nodeFinder, $cast->expr), self::of($cast));
    }

}
