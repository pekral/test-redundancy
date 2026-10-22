<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

use JsonSerializable;

final readonly class Verification implements JsonSerializable
{

    private const string RESTORE = 'Coverage dropped. Restore the test that covered each line in "lostLines", then run verify again.';

    private const string UNSTABLE = 'No test changed, but the coverage of "unstableLines" differs from the baseline. '
        . 'Do not restore a test. The coverage of an unchanged test depends on state outside the test run, such as a cache or a database. '
        . 'Make that test independent of the state, or run analyse again for a new baseline.';

    /**
     * @param list<string> $lostLines
     * @param list<string> $missingTestFiles
     * @param list<string> $unstable
     */
    public function __construct(public int $baselineLines, public array $lostLines, public array $missingTestFiles, public array $unstable)
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
            'instruction' => $this->instruction(),
            'lostLines' => $this->lostLines,
            'missingTestFiles' => $this->missingTestFiles,
            'ok' => $this->isOk(),
            'tool' => 'pekral/test-redundancy',
            'unstableLines' => $this->unstable,
        ];
    }

    private function instruction(): string
    {
        return match (true) {
            !$this->isOk() && $this->unstable !== [] => self::RESTORE
                . ' Do not restore a test for "unstableLines": no test covering those lines changed.',
            !$this->isOk() => self::RESTORE,
            $this->unstable !== [] => self::UNSTABLE,
            default => 'Every source line the analysed tests covered before is still covered.',
        };
    }

}
