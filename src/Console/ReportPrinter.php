<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Console;

use Pekral\TestRedundancy\Analysis\Action;
use Pekral\TestRedundancy\Analysis\AgentReport;
use Pekral\TestRedundancy\Analysis\Candidate;
use Pekral\TestRedundancy\Analysis\FileReport;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class ReportPrinter
{

    private const string NO_NEW_VALUE_OR_CALL = 'No new value or call (same lines; check how inputs pair with results before deleting)';

    private const string TESTS = 'Tests to review';

    private const string REVIEW_RULE = 'Coverage cannot see what a test asserts. '
        . 'Delete a candidate only when a covering test asserts the same input and result.';

    private const string SKIPPED = 'Skipped: the file declares one test case and no dataset, '
        . 'so no other test case of the file can cover it.';

    private const string UNPARSED = 'unknown (the test or a dataset row could not be parsed)';

    private const string KEEP = 'Tests to keep (they assert a rejection no covering test asserts)';

    private const string DATASET_ROWS = 'Dataset rows to review (same lines, different data — check the input class)';

    public function __construct(private SymfonyStyle $io)
    {
    }

    /**
     * @param list<\Pekral\TestRedundancy\Analysis\FileReport> $reports
     */
    public function printText(array $reports): void
    {
        foreach (AgentReport::bySeconds($reports) as $report) {
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

        if ($report->skipped) {
            $this->io->text(self::SKIPPED);

            return;
        }

        $this->io->definitionList(
            ['Test cases' => (string) $report->testCases],
            ['Covered lines' => (string) $report->coveredLines],
            ['Candidates' => (string) count($report->candidates)],
            ['Tests without source lines' => (string) count($report->testsWithoutSourceLines)],
            ['Failed tests' => (string) count($report->failedTests)],
            ['Candidate seconds' => sprintf('%.3f', $report->candidateSeconds())],
        );

        $this->printFailedTests($report);

        $this->printTests('Tests that failed and passed on a retry (they depend on timing, order or shared state)', $report->passedOnRetry);
        $this->printTests('Tests without source lines (check what they test, or "source")', $report->testsWithoutSourceLines);

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

    /**
     * @param list<string> $tests
     */
    private function printTests(string $title, array $tests): void
    {
        if ($tests !== []) {
            $this->io->writeln(sprintf('<comment>%s</comment>', $title));
            $this->io->listing(array_map(OutputFormatter::escape(...), $tests));
        }
    }

    private function printFailedTests(FileReport $report): void
    {
        if ($report->failedTests === []) {
            return;
        }

        $this->io->writeln('<comment>Failed tests (left out of the analysis; fix them and run analyse again)</comment>');
        $this->io->listing(array_map(
            static fn (string $test, string $message): string => OutputFormatter::escape(
                $test . "\n    " . str_replace("\n", "\n    ", $message),
            ),
            array_keys($report->failedTests),
            $report->failedTests,
        ));
    }

    private function describe(Candidate $candidate): string
    {
        $coveringTokens = $candidate->coveringTokensForReview();

        return OutputFormatter::escape(sprintf(
            "%s\n    action: %s%s\n    covered by:\n      - %s\n    differs by: %s%s%s%s",
            $candidate->testCase(),
            $candidate->action()->value,
            $candidate->seconds === null ? '' : sprintf("\n    seconds: %.3f", $candidate->seconds),
            implode("\n      - ", $candidate->coveredBy),
            $candidate->differsBy === null ? self::UNPARSED : implode(', ', $candidate->differsBy),
            $coveringTokens === [] ? '' : "\n    covering differs by: " . implode(', ', $coveringTokens),
            $candidate->coveringOnly() === [] ? '' : "\n    covering only: " . implode(', ', $candidate->coveringOnly()),
            $candidate->unionOnlyLines === [] ? '' : "\n    union only lines: " . implode(', ', $candidate->unionOnlyLines),
        ));
    }

    /**
     * @return array<string, list<\Pekral\TestRedundancy\Analysis\Candidate>>
     */
    private function groups(FileReport $report): array
    {
        $groups = [self::NO_NEW_VALUE_OR_CALL => [], self::TESTS => [], self::DATASET_ROWS => [], self::KEEP => []];

        foreach ($report->candidatesBySeconds() as $candidate) {
            $group = match (true) {
                $candidate->action() === Action::Keep => self::KEEP,
                $candidate->addsNoValueOrCall() => self::NO_NEW_VALUE_OR_CALL,
                $candidate->isDatasetRow() => self::DATASET_ROWS,
                default => self::TESTS,
            };
            $groups[$group][] = $candidate;
        }

        return $groups;
    }

}
