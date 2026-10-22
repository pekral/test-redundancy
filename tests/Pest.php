<?php

declare(strict_types = 1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

function fixturePath(string $fixture): string
{
    return __DIR__ . '/Fixtures/' . $fixture;
}

function temporaryDirectory(): string
{
    $directory = Path::canonicalize(__DIR__ . '/../build/tmp/project' . bin2hex(random_bytes(6)));
    (new Filesystem())->mkdir($directory);

    return $directory;
}

function removeDirectory(string $directory): void
{
    (new Filesystem())->remove($directory);
}

/**
 * @param list<string> $command
 */
function runIn(string $directory, array $command): string
{
    return (new Process($command, $directory))->mustRun()->getOutput();
}

function gitRepository(): string
{
    $directory = temporaryDirectory();
    runIn($directory, ['git', 'init', '--quiet', '--initial-branch=master']);
    runIn($directory, ['git', 'config', 'user.email', 'test@example.com']);
    runIn($directory, ['git', 'config', 'user.name', 'Test']);
    file_put_contents($directory . '/README.md', 'readme');
    runIn($directory, ['git', 'add', '.']);
    runIn($directory, ['git', 'commit', '--quiet', '-m', 'initial']);

    return $directory;
}

function valueAt(mixed $data, string $path): mixed
{
    foreach (explode('.', $path) as $segment) {
        if (!is_array($data) || !array_key_exists($segment, $data)) {
            return null;
        }

        $data = $data[$segment];
    }

    return $data;
}

/**
 * @return array<string, mixed>
 */
function decodedJson(string $json): array
{
    $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
    $result = [];

    foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
        $result[(string) $key] = $value;
    }

    return $result;
}
