<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Console;

use Symfony\Component\Console\Application as ConsoleApplication;

final class Application extends ConsoleApplication
{

    public const NAME = 'test-redundancy';

    private const DEFAULT_COMMAND = 'analyse';

    private const APPLICATION_OPTIONS = ['-h', '--help', '-V', '--version'];

    public function __construct(string $projectRoot)
    {
        parent::__construct(self::NAME);

        $this->addCommands([new AnalyseCommand($projectRoot), new MapCommand($projectRoot), new VerifyCommand($projectRoot)]);
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    public function withDefaultCommand(array $arguments): array
    {
        $first = $arguments[1] ?? null;

        if ($first !== null && ($this->has($first) || in_array($first, self::APPLICATION_OPTIONS, strict: true))) {
            return $arguments;
        }

        return [$arguments[0] ?? self::NAME, self::DEFAULT_COMMAND, ...array_slice($arguments, 1)];
    }

}
