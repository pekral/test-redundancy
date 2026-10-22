<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Console;

use Symfony\Component\Console\Application as ConsoleApplication;

final class Application extends ConsoleApplication
{

    public const NAME = 'test-redundancy';

    public function __construct(string $projectRoot)
    {
        parent::__construct(self::NAME);

        $this->addCommands([new AnalyseCommand($projectRoot), new MapCommand($projectRoot)]);
    }

}
