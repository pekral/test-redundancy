<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

use Pekral\TestRedundancy\Configuration;
use RuntimeException;
use Symfony\Component\Filesystem\Path;

final readonly class TestRunner
{

    private const PEST = 'vendor/bin/pest';

    private const PHPUNIT = 'vendor/bin/phpunit';

    private const PARATEST = 'vendor/bin/paratest';

    private function __construct(private string $binary, private bool $isPest, private bool $hasParatest)
    {
    }

    public static function detect(Configuration $configuration): self
    {
        $binary = $configuration->runner ?? (is_file(Path::join($configuration->projectRoot, self::PEST)) ? self::PEST : self::PHPUNIT);

        if (!is_file(Path::makeAbsolute($binary, $configuration->projectRoot))) {
            throw new RuntimeException(sprintf('No test runner found at %s. Install Pest or PHPUnit, or set "runner".', $binary));
        }

        return new self($binary, str_ends_with($binary, 'pest'), is_file(Path::join($configuration->projectRoot, self::PARATEST)));
    }

    public function rootDirectory(string $projectRoot): string
    {
        return Path::makeAbsolute(dirname($this->binary, 3), $projectRoot);
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    public function command(array $paths, bool $parallel, int $processes, string $coverageFile, string $junitFile): array
    {
        $runsInParallel = $parallel && $processes > 1;
        $binary = $runsInParallel && !$this->isPest && $this->hasParatest ? self::PARATEST : $this->binary;
        $parallelArguments = match (true) {
            !$runsInParallel => [],
            $this->isPest => ['--parallel', '--processes=' . $processes],
            $this->hasParatest => ['--processes=' . $processes],
            default => [],
        };

        return [
            PHP_BINARY,
            '-d', 'memory_limit=-1',
            ...CoverageDriver::iniArguments(),
            $binary,
            ...$paths,
            ...$parallelArguments,
            '--coverage-php=' . $coverageFile,
            '--log-junit=' . $junitFile,
        ];
    }

}
