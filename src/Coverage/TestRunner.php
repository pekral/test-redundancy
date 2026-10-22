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

    private const PARATEST = 'paratest';

    private const CACHE_DIRECTORY = '.test-redundancy/cache';

    private function __construct(private string $projectRoot, private string $binary, private bool $isPest, private bool $hasParatest)
    {
    }

    public static function detect(Configuration $configuration): self
    {
        $binary = $configuration->runner ?? (is_file(Path::join($configuration->projectRoot, self::PEST)) ? self::PEST : self::PHPUNIT);

        if (!is_file(Path::makeAbsolute($binary, $configuration->projectRoot))) {
            throw new RuntimeException(sprintf('No test runner found at %s. Install Pest or PHPUnit, or set "runner".', $binary));
        }

        return new self(
            $configuration->projectRoot,
            $binary,
            str_ends_with($binary, 'pest'),
            is_file(Path::makeAbsolute(self::paratestNextTo($binary), $configuration->projectRoot)),
        );
    }

    public function rootDirectory(): string
    {
        return Path::makeAbsolute(dirname($this->binary, 3), $this->projectRoot);
    }

    public function cacheDirectory(): string
    {
        return $this->projectRoot . '/' . self::CACHE_DIRECTORY;
    }

    public function runsInParallel(bool $parallel, int $processes): bool
    {
        return $parallel && $processes > 1 && ($this->isPest || $this->hasParatest);
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    public function command(array $paths, bool $parallel, int $processes, string $coverageFile, string $junitFile): array
    {
        $runsInParallel = $this->runsInParallel($parallel, $processes);
        $binary = $runsInParallel && !$this->isPest ? self::paratestNextTo($this->binary) : $this->binary;
        $parallelArguments = match (true) {
            !$runsInParallel => [],
            $this->isPest => ['--parallel', '--processes=' . $processes],
            default => ['--processes=' . $processes],
        };

        return [
            PHP_BINARY,
            '-d', 'memory_limit=-1',
            ...CoverageDriver::iniArguments($this->projectRoot),
            $binary,
            ...$paths,
            ...$parallelArguments,
            '--coverage-php=' . $coverageFile,
            '--log-junit=' . $junitFile,
            '--cache-directory=' . $this->cacheDirectory(),
        ];
    }

    private static function paratestNextTo(string $binary): string
    {
        return dirname($binary) . '/' . self::PARATEST;
    }

}
