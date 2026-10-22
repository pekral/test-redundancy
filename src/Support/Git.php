<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Support;

use Symfony\Component\Process\Process;

final readonly class Git
{

    public function __construct(private string $projectRoot)
    {
    }

    public function headCommit(): string
    {
        return trim($this->run(['git', 'rev-parse', 'HEAD']));
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    public function changedSince(string $commit, array $paths): array
    {
        return $this->lines($this->run(['git', 'diff', '--name-only', $commit, '--', ...$paths]));
    }

    /**
     * @return list<string>
     */
    public function changedFilesAgainst(string $baseRef): array
    {
        $mergeBase = trim($this->run(['git', 'merge-base', $baseRef, 'HEAD']));

        return array_values(array_unique([
            ...$this->lines($this->run(['git', 'diff', '--name-only', '--diff-filter=ACMR', $mergeBase])),
            ...$this->lines($this->run(['git', 'ls-files', '--others', '--exclude-standard'])),
        ]));
    }

    public function defaultBaseRef(): string
    {
        $originHead = new Process(['git', 'symbolic-ref', '--short', 'refs/remotes/origin/HEAD'], $this->projectRoot);
        $originHead->run();

        return $originHead->isSuccessful() ? trim($originHead->getOutput()) : 'HEAD';
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command): string
    {
        return (new Process($command, $this->projectRoot))->mustRun()->getOutput();
    }

    /**
     * @return list<string>
     */
    private function lines(string $output): array
    {
        return array_values(array_filter(explode("\n", trim($output)), static fn (string $line): bool => $line !== ''));
    }

}
