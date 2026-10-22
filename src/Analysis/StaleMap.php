<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

use JsonSerializable;

final readonly class StaleMap implements JsonSerializable
{

    /**
     * @param list<string> $changedSourceFiles
     */
    public function __construct(public string $commit, public array $changedSourceFiles)
    {
    }

    /**
     * @return array{changedSourceFiles: list<string>, commit: string}
     */
    public function jsonSerialize(): array
    {
        return ['changedSourceFiles' => $this->changedSourceFiles, 'commit' => $this->commit];
    }

}
