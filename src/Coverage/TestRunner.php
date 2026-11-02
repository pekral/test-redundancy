<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Coverage;

use Pekral\TestRedundancy\Configuration;
use RuntimeException;
use Symfony\Component\Filesystem\Path;

final readonly class TestRunner
{

    private const string PEST = 'vendor/bin/pest';

    private const string PHPUNIT = 'vendor/bin/phpunit';

    private const string CACHE_DIRECTORY = '.test-redundancy/cache';

    private const array CONFIGURATION_FILES = ['phpunit.xml', 'phpunit.xml.dist'];

    /**
     * @param list<string> $sourcePaths
     */
    private function __construct(private string $projectRoot, private array $sourcePaths, private string $binary)
    {
    }

    public static function detect(Configuration $configuration): self
    {
        $binary = $configuration->runner ?? (is_file(Path::join($configuration->projectRoot, self::PEST)) ? self::PEST : self::PHPUNIT);

        if (!is_file(Path::makeAbsolute($binary, $configuration->projectRoot))) {
            throw new RuntimeException(sprintf('No test runner found at %s. Install Pest or PHPUnit, or set "runner".', $binary));
        }

        return new self($configuration->projectRoot, $configuration->sourcePaths, $binary);
    }

    public function rootDirectory(): string
    {
        return Path::makeAbsolute(dirname($this->binary, 3), $this->projectRoot);
    }

    public function cacheDirectory(): string
    {
        return $this->projectRoot . '/' . self::CACHE_DIRECTORY;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    public function command(array $paths, string $coverageFile, string $junitFile): array
    {
        return [
            PHP_BINARY,
            '-d', 'memory_limit=-1',
            ...CoverageDriver::iniArguments($this->projectRoot, $this->sourcePaths),
            $this->binary,
            ...$paths,
            ...$this->configurationArguments(),
            '--coverage-php=' . $coverageFile,
            '--log-junit=' . $junitFile,
            '--cache-directory=' . $this->cacheDirectory(),
        ];
    }

    /**
     * @return list<string>
     */
    private function configurationArguments(): array
    {
        foreach (self::CONFIGURATION_FILES as $file) {
            if (is_file($this->projectRoot . '/' . $file)) {
                return ['--configuration=' . $this->projectRoot . '/' . $file];
            }
        }

        return [];
    }

}
