<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Console;

use Pekral\TestRedundancy\Analysis\AgentReport;
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
            ->addOption('format', mode: InputOption::VALUE_REQUIRED, description: 'Output: json (for AI agents) or text', default: 'json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startedAt = hrtime(as_number: true);
        $io = new SymfonyStyle($input, $output);
        $testRedundancy = TestRedundancy::forProject($this->projectRoot);
        /** @var list<string> $paths */
        $paths = $input->getArgument('paths');
        $base = $input->getOption('base');
        $testFiles = $testRedundancy->testFiles($paths, is_string($base) ? $base : null);

        if ($testFiles === []) {
            $io->getErrorStyle()->note('No test file to analyse.');
        }

        $reports = $testFiles === []
            ? []
            : ($input->getOption('across') === true
            ? $this->analyseAcross($testRedundancy, $testFiles, $io)
            : $testRedundancy->analyse($testFiles, $paths));
        $printer = new ReportPrinter($io);

        $input->getOption('format') === 'text'
            ? $printer->printText($reports)
            : $printer->printJson(new AgentReport($reports, (int) round((hrtime(as_number: true) - $startedAt) / 1e9)));

        return self::SUCCESS;
    }

    /**
     * @param list<string> $testFiles
     * @return list<\Pekral\TestRedundancy\Analysis\FileReport>
     */
    private function analyseAcross(TestRedundancy $testRedundancy, array $testFiles, SymfonyStyle $io): array
    {
        $map = $testRedundancy->loadMap();
        $changedSourceFiles = $testRedundancy->changedSourceFiles($map);

        if ($changedSourceFiles !== []) {
            $io->getErrorStyle()->warning(sprintf(
                '%d source files changed since the coverage map was built at %s. The analysed files are re-run; '
                . 'other tests come from the stale map. Rebuild it with "test-redundancy map".',
                count($changedSourceFiles),
                substr($map->commit, 0, 10),
            ));
        }

        return $testRedundancy->analyseAcross($testFiles, $map);
    }

}
