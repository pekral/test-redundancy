<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Console;

use Pekral\TestRedundancy\Analysis\AgentReport;
use Pekral\TestRedundancy\Analysis\StaleMap;
use Pekral\TestRedundancy\Configuration;
use Pekral\TestRedundancy\Coverage\CoverageMap;
use Pekral\TestRedundancy\TestRedundancy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'analyse', description: 'List tests whose covered lines other tests already cover, as candidates to review')]
final class AnalyseCommand extends Command
{

    public function __construct(private readonly string $projectRoot)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('paths', InputArgument::IS_ARRAY, 'Test files or directories; default: the test files changed against --base')
            ->addOption('across', mode: InputOption::VALUE_NONE, description: 'Compare with every test in the coverage map')
            ->addOption('base', mode: InputOption::VALUE_REQUIRED, description: 'Git ref for the changed test files; default: origin/HEAD')
            ->addOption('format', mode: InputOption::VALUE_REQUIRED, description: 'Output: json (for AI agents) or text', default: 'json')
            ->addOption('processes', mode: InputOption::VALUE_REQUIRED, description: 'Parallel processes; overrides "processes"')
            ->addOption('pretty', mode: InputOption::VALUE_NONE, description: 'Indent the JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startedAt = hrtime(as_number: true);
        $io = new SymfonyStyle($input, $output);
        $testRedundancy = TestRedundancy::forProject($this->projectRoot, $this->processesOption($input));
        /** @var list<string> $paths */
        $paths = $input->getArgument('paths');
        $base = $input->getOption('base');
        $testFiles = $testRedundancy->testFiles($paths, is_string($base) ? $base : null);
        $map = $input->getOption('across') === true ? $testRedundancy->loadMap() : null;
        $staleMap = $map === null ? null : $this->staleMap($testRedundancy, $map, $io);

        if ($testFiles === []) {
            $io->getErrorStyle()->note('No test file to analyse.');
        }

        $reports = match (true) {
            $testFiles === [] => [],
            $map !== null => $testRedundancy->analyseAcross($testFiles, $map),
            default => $testRedundancy->analyse($testFiles, $paths),
        };
        $this->warnAboutFilesWithoutSourceLines($reports, $io);
        $printer = new ReportPrinter($io);

        $input->getOption('format') === 'text'
            ? $printer->printText($reports)
            : $printer->printJson(
                new AgentReport($reports, (int) round((hrtime(as_number: true) - $startedAt) / 1e9), $staleMap),
                $input->getOption('pretty') === true,
            );

        return self::SUCCESS;
    }

    private function processesOption(InputInterface $input): ?int
    {
        $processes = $input->getOption('processes');

        return is_numeric($processes) ? (int) $processes : null;
    }

    private function staleMap(TestRedundancy $testRedundancy, CoverageMap $map, SymfonyStyle $io): ?StaleMap
    {
        $changedSourceFiles = $testRedundancy->changedSourceFiles($map);

        if ($changedSourceFiles === []) {
            return null;
        }

        $io->getErrorStyle()->warning(sprintf(
            '%d source files changed since the coverage map was built at %s. The analysed files are re-run; '
            . 'other tests come from the stale map. Rebuild it with "test-redundancy map".',
            count($changedSourceFiles),
            substr($map->commit, 0, 10),
        ));

        return new StaleMap($map->commit, $changedSourceFiles);
    }

    /**
     * @param list<\Pekral\TestRedundancy\Analysis\FileReport> $reports
     */
    private function warnAboutFilesWithoutSourceLines(array $reports, SymfonyStyle $io): void
    {
        foreach ($reports as $report) {
            if ($report->testCases > 0 && $report->coveredLines === 0) {
                $io->getErrorStyle()->warning(sprintf(
                    '%s covers no line under the configured source paths. Check "source" in %s.',
                    $report->testFile,
                    Configuration::FILE_NAME,
                ));
            }
        }
    }

}
