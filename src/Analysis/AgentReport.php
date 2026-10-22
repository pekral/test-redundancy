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
    ];

    /**
     * @param list<\Pekral\TestRedundancy\Analysis\FileReport> $reports
     */
    public function __construct(private array $reports, private int $seconds)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $byAction = array_fill_keys(array_map(static fn (Action $action): string => $action->value, Action::cases()), 0);
        $candidates = 0;
        $testCases = 0;

        foreach ($this->reports as $report) {
            $testCases += $report->testCases;

            foreach ($report->candidates as $candidate) {
                $byAction[$candidate->action()->value] += 1;
                $candidates += 1;
            }
        }

        return [
            'files' => array_values(array_filter($this->reports, static fn (FileReport $report): bool => $report->candidates !== [])),
            'instructions' => self::INSTRUCTIONS,
            'summary' => [
                'byAction' => $byAction,
                'candidates' => $candidates,
                'seconds' => $this->seconds,
                'testCases' => $testCases,
                'testFiles' => count($this->reports),
            ],
            'tool' => 'pekral/test-redundancy',
        ];
    }

}
