<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Console;

use Pekral\TestRedundancy\TestRedundancy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'map', description: 'Build the per-test coverage map "analyse --across" compares with')]
final class MapCommand extends Command
{

    public function __construct(private readonly string $projectRoot)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('paths', InputArgument::IS_ARRAY, 'Test files or directories to map', ['tests'])
            ->addOption('processes', mode: InputOption::VALUE_REQUIRED, description: 'Parallel processes; overrides "processes"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<string> $paths */
        $paths = $input->getArgument('paths');
        $map = TestRedundancy::forProject($this->projectRoot, $this->processesOption($input))->buildMap(
            $paths,
            static fn (array $chunk) => $io->writeln(sprintf('<comment>coverage run:</comment> %s', implode(' ', $chunk))),
        );

        $io->success(sprintf('%d test cases mapped at %s.', $map->testCount(), substr($map->commit, 0, 10)));

        return self::SUCCESS;
    }

    private function processesOption(InputInterface $input): ?int
    {
        $processes = $input->getOption('processes');

        return is_numeric($processes) ? (int) $processes : null;
    }

}
