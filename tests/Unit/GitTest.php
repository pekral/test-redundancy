<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Support\Git;

it('reads commits and changed files', function (): void {
    $repository = gitRepository();
    $git = new Git($repository);
    $commit = $git->headCommit();
    mkdir($repository . '/tests');
    file_put_contents($repository . '/README.md', 'changed');
    file_put_contents($repository . '/tests/NewTest.php', '<?php');

    expect($commit)->toMatch('/^[0-9a-f]{40}$/')
        ->and($git->changedSince($commit, ['README.md']))->toBe(['README.md'])
        ->and($git->changedSince($commit, ['tests']))->toBe([])
        ->and($git->changedFilesAgainst('master'))->toBe(['README.md', 'tests/NewTest.php'])
        ->and($git->defaultBaseRef())->toBe('HEAD');

    removeDirectory($repository);
});

it('takes the base branch from origin', function (): void {
    $repository = gitRepository();
    runIn($repository, ['git', 'update-ref', 'refs/remotes/origin/master', 'HEAD']);
    runIn($repository, ['git', 'symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/master']);

    expect((new Git($repository))->defaultBaseRef())->toBe('origin/master');

    removeDirectory($repository);
});
