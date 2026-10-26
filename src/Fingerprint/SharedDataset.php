<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Fingerprint;

final readonly class SharedDataset
{

    /**
     * @param list<string> $otherTests
     */
    public function __construct(public string $name, public array $otherTests)
    {
    }

}
