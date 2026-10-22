<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

use JsonSerializable;

final readonly class Verification implements JsonSerializable
{

    /**
     * @param list<string> $lostLines
     * @param list<string> $missingTestFiles
     */
    public function __construct(public int $baselineLines, public array $lostLines, public array $missingTestFiles)
    {
    }

    public function isOk(): bool
    {
        return $this->lostLines === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'baselineLines' => $this->baselineLines,
            'instruction' => $this->isOk()
                ? 'Every source line the analysed tests covered before is still covered.'
                : 'Coverage dropped. Restore the test that covered each line in "lostLines", then run verify again.',
            'lostLines' => $this->lostLines,
            'missingTestFiles' => $this->missingTestFiles,
            'ok' => $this->isOk(),
            'tool' => 'pekral/test-redundancy',
        ];
    }

}
