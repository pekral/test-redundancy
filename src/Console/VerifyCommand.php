<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Console;

use Pekral\TestRedundancy\TestRedundancy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'verify', description: 'Fail when a source line the last analyse run covered is no longer covered')]
final class VerifyCommand extends Command
{

    public function __construct(private readonly string $projectRoot)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('processes', mode: InputOption::VALUE_REQUIRED, description: 'Parallel processes; overrides "processes"')
            ->addOption('pretty', mode: InputOption::VALUE_NONE, description: 'Indent the JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $processes = $input->getOption('processes');
        $verification = TestRedundancy::forProject($this->projectRoot, is_numeric($processes) ? (int) $processes : null)->verify();

        $output->writeln(Json::encode($verification, pretty: $input->getOption('pretty') === true));

        return $verification->isOk() ? self::SUCCESS : self::FAILURE;
    }

}
