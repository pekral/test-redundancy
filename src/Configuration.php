<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy;

use InvalidArgumentException;
use JsonException;
use Pekral\TestRedundancy\Support\ProjectPath;

final readonly class Configuration
{

    public const string FILE_NAME = 'test-redundancy.json';

    private const int DEFAULT_PROCESSES = 2;

    private const int MINIMUM_PROCESSES = 2;

    private const string DEFAULT_MAP_PATH = '.test-redundancy/coverage-map.json.gz';

    private const string BASELINE_FILE_NAME = 'baseline.json';

    /**
     * @param list<string> $sourcePaths
     * @param array<string, string> $environment
     */
    public function __construct(
        public string $projectRoot,
        public array $sourcePaths,
        public ?string $ignoredSourcePattern,
        public array $environment,
        public int $processes,
        public string $mapPath,
        public ?string $runner,
    ) {
    }

    /**
     * @throws \JsonException
     */
    public static function load(string $projectRoot): self
    {
        $file = $projectRoot . DIRECTORY_SEPARATOR . self::FILE_NAME;
        $values = is_file($file) ? json_decode((string) file_get_contents($file), associative: true, flags: JSON_THROW_ON_ERROR) : [];

        if (!is_array($values)) {
            throw new JsonException(sprintf('%s must contain a JSON object.', self::FILE_NAME));
        }

        return new self(
            projectRoot: $projectRoot,
            sourcePaths: array_map(
                static fn (string $path): string => rtrim(ProjectPath::relative($path, $projectRoot), '/') . '/',
                self::stringList($values['source'] ?? ['src', 'app']),
            ),
            ignoredSourcePattern: is_string($values['ignore'] ?? null) ? $values['ignore'] : null,
            environment: self::stringMap($values['env'] ?? []),
            processes: self::processes(is_int($values['processes'] ?? null) ? $values['processes'] : self::DEFAULT_PROCESSES),
            mapPath: is_string($values['map'] ?? null) ? $values['map'] : self::DEFAULT_MAP_PATH,
            runner: is_string($values['runner'] ?? null) ? $values['runner'] : null,
        );
    }

    public function withProcesses(int $processes): self
    {
        return new self(
            $this->projectRoot,
            $this->sourcePaths,
            $this->ignoredSourcePattern,
            $this->environment,
            self::processes($processes),
            $this->mapPath,
            $this->runner,
        );
    }

    public function isSource(string $relativePath): bool
    {
        foreach ($this->sourcePaths as $sourcePath) {
            if (str_starts_with($relativePath, $sourcePath)) {
                return $this->ignoredSourcePattern === null || preg_match($this->ignoredSourcePattern, $relativePath) !== 1;
            }
        }

        return false;
    }

    public function absoluteBaselinePath(): string
    {
        return dirname($this->absoluteMapPath()) . DIRECTORY_SEPARATOR . self::BASELINE_FILE_NAME;
    }

    public function absoluteMapPath(): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . $this->mapPath;
    }

    private static function processes(int $processes): int
    {
        if ($processes < self::MINIMUM_PROCESSES) {
            throw new InvalidArgumentException(
                sprintf('"processes" must be at least %d; the tool runs the tests only in parallel.', self::MINIMUM_PROCESSES),
            );
        }

        return $processes;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (!is_string($key) || !is_scalar($item)) {
                continue;
            }

            $map[$key] = (string) $item;
        }

        return $map;
    }

}
