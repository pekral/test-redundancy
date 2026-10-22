<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Console;

use Pekral\TestRedundancy\Analysis\AgentReport;
use Pekral\TestRedundancy\Analysis\Candidate;
use Pekral\TestRedundancy\Analysis\FileReport;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class ReportPrinter
{

    private const NO_NEW_VALUE_OR_CALL = 'No new value or call (same lines; check how inputs pair with results before deleting)';

    private const TESTS = 'Tests to review';

    private const REVIEW_RULE = 'Coverage cannot see what a test asserts. '
        . 'Delete a candidate only when a covering test asserts the same input and result.';

    private const UNPARSED = 'unknown (the test or a dataset row could not be parsed)';

    private const DATASET_ROWS = 'Dataset rows to review (same lines, different data — check the input class)';

    public function __construct(private SymfonyStyle $io)
    {
    }

    /**
     * @param list<\Pekral\TestRedundancy\Analysis\FileReport> $reports
     */
    public function printText(array $reports): void
    {
        foreach ($reports as $report) {
            $this->printReport($report);
        }
    }

    /**
     * @throws \JsonException
     */
    public function printJson(AgentReport $report, bool $pretty): void
    {
        $this->io->writeln(Json::encode($report, $pretty));
    }

    private function printReport(FileReport $report): void
    {
        $this->io->section($report->testFile);
        $this->io->definitionList(
            ['Test cases' => (string) $report->testCases],
            ['Covered lines' => (string) $report->coveredLines],
            ['Candidates' => (string) count($report->candidates)],
            ['Tests without source lines' => (string) count($report->testsWithoutSourceLines)],
        );

        if ($report->testsWithoutSourceLines !== []) {
            $this->io->writeln('<comment>Tests without source lines (check what they test, or "source")</comment>');
            $this->io->listing($report->testsWithoutSourceLines);
        }

        if ($report->candidates === []) {
            $this->io->success('Every test case covers a line no other test case covers.');

            return;
        }

        $this->io->text(self::REVIEW_RULE);

        foreach (array_filter($this->groups($report)) as $title => $candidates) {
            $this->io->writeln(sprintf('<comment>%s</comment>', $title));
            $this->io->listing(array_map($this->describe(...), $candidates));
        }
    }

    private function describe(Candidate $candidate): string
    {
        return sprintf(
            "%s\n    action: %s\n    covered by:\n      - %s\n    differs by: %s",
            $candidate->testCase(),
            $candidate->action()->value,
            implode("\n      - ", $candidate->coveredBy),
            $candidate->differsBy === null ? self::UNPARSED : implode(', ', $candidate->differsBy),
        );
    }

    /**
     * @return array<string, list<\Pekral\TestRedundancy\Analysis\Candidate>>
     */
    private function groups(FileReport $report): array
    {
        $groups = [self::NO_NEW_VALUE_OR_CALL => [], self::TESTS => [], self::DATASET_ROWS => []];

        foreach ($report->candidates as $candidate) {
            $group = match (true) {
                $candidate->addsNoValueOrCall() => self::NO_NEW_VALUE_OR_CALL,
                $candidate->isDatasetRow() => self::DATASET_ROWS,
                default => self::TESTS,
            };
            $groups[$group][] = $candidate;
        }

        return $groups;
    }

}
