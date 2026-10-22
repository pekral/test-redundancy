<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Analysis;

use JsonSerializable;

final readonly class AgentReport implements JsonSerializable
{

    private const INSTRUCTIONS = [
        'Goal: keep 100% line coverage with the fewest and simplest tests.',
        'Every candidate runs only source lines other tests already run. '
            . 'Coverage cannot see what a test asserts, so judge each one.',
        'Apply "action" per candidate: delete, merge-into-dataset, review-dataset-row, or review. '
            . '"differsBy" lists the literals, constants and calls the candidate has and its covering tests lack.',
        'Keep a test that asserts a different result, a different input class (empty, boundary, another tenant, invalid), '
            . 'an ordering, or a rejection. When in doubt, keep it.',
        'Candidates of one file can be removed together without losing a covered line. Re-run the tool after every change.',
        '"testsWithoutSourceLines" run no line under the configured source paths: check what they test, or the "source" setting.',
        'A non-null "summary.staleMap" means source files changed since the coverage map was built: rebuild it before trusting '
            . 'a candidate whose "coveredBy" names another file.',
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
            'instructions' => self::INSTRUCTIONS,
            'summary' => $this->summary(),
            'tool' => 'pekral/test-redundancy',
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
            'seconds' => $this->seconds,
            'staleMap' => $this->staleMap,
            'testCases' => array_sum(array_map(static fn (FileReport $report): int => $report->testCases, $this->reports)),
            'testFiles' => count($this->reports),
            'testsWithoutSourceLines' => array_sum(
                array_map(static fn (FileReport $report): int => count($report->testsWithoutSourceLines), $this->reports),
            ),
        ];
    }

}
