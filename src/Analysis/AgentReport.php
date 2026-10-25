<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

use JsonSerializable;

final readonly class AgentReport implements JsonSerializable
{

    private const array WORKFLOW = [
        'Goal: 100% coverage with the fewest and simplest tests. Coverage cannot see assertions, so judge every candidate.',
        'For each candidate, follow "steps". Keep a test that asserts another result, input class, ordering or rejection.',
        'All candidates of one file can be removed together without losing a covered line.',
        Action::VERIFY_STEP . ' It lists every source line no test covers any more; restore the test that covered it.',
        'testsWithoutSourceLines run no line under the configured source paths; check what they test.',
        'failedTests failed in the coverage run. The analysis leaves them out: none is a candidate or a covering test. '
        . 'Fix them and run analyse again; the exit code is 1 while a test fails.',
        'A non-null summary.staleMap means the coverage map is out of date: run vendor/bin/test-redundancy map first.',
    ];

    /**
     * @param list<\Pekral\TestRedundancy\Analysis\FileReport> $reports
     */
    public function __construct(private array $reports, private int $seconds, private ?StaleMap $staleMap)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'files' => array_values(array_filter($this->reports, static fn (FileReport $report): bool => $report->hasFindings())),
            'summary' => $this->summary(),
            'tool' => 'pekral/test-redundancy',
            'workflow' => self::WORKFLOW,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        $byAction = array_fill_keys(array_map(static fn (Action $action): string => $action->value, Action::cases()), 0);
        $candidates = [];

        foreach ($this->reports as $report) {
            $candidates = [...$candidates, ...$report->candidates];
        }

        foreach ($candidates as $candidate) {
            $byAction[$candidate->action()->value] += 1;
        }

        return [
            'byAction' => $byAction,
            'candidates' => count($candidates),
            'failedTests' => array_sum(array_map(static fn (FileReport $report): int => count($report->failedTests), $this->reports)),
            'seconds' => $this->seconds,
            'skippedFiles' => count(array_filter($this->reports, static fn (FileReport $report): bool => $report->skipped)),
            'staleMap' => $this->staleMap,
            'testCases' => array_sum(array_map(static fn (FileReport $report): int => $report->testCases, $this->reports)),
            'testFiles' => count($this->reports),
            'testsWithoutSourceLines' => array_sum(
                array_map(static fn (FileReport $report): int => count($report->testsWithoutSourceLines), $this->reports),
            ),
        ];
    }

}
