<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

function command(string $projectRoot, string $name): CommandTester
{
    return new CommandTester((new Application($projectRoot))->find($name));
}

function pestProject(): string
{
    $project = gitRepository();
    (new Filesystem())->mirror(fixturePath('pest'), $project, options: ['override' => true]);
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'map' => 'build/coverage-map.json.gz',
        'processes' => 1,
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/pest',
        'source' => ['src'],
    ]));
    runIn($project, ['git', 'add', '.']);
    runIn($project, ['git', 'commit', '--quiet', '-m', 'fixture']);

    return $project;
}

it('prints the JSON report for an agent by default', function (): void {
    $tester = command(fixturePath('pest'), 'analyse');

    $tester->execute(['paths' => ['tests/CalculatorTest.php']]);
    $report = decodedJson($tester->getDisplay());

    expect($tester->getStatusCode())->toBe(0)
        ->and(valueAt($report, 'summary.candidates'))->toBe(3)
        ->and(valueAt($report, 'files.0.candidates.0.action'))->toBe('merge-into-dataset');
});

it('prints a text report for people', function (): void {
    $tester = command(fixturePath('pest'), 'analyse');

    $tester->execute(['paths' => ['tests'], '--format' => 'text']);

    expect($tester->getDisplay())->toContain('tests/CalculatorTest.php')
        ->toContain('Tests to review')
        ->toContain('Dataset rows to review')
        ->toContain('action: merge-into-dataset')
        ->toContain('Every test case covers a line no other test case covers.');
});

it('reports when no test file changed', function (): void {
    $project = gitRepository();
    mkdir($project . '/vendor/bin', recursive: true);
    touch($project . '/vendor/bin/phpunit');
    $tester = command($project, 'analyse');

    $tester->execute(['--base' => 'master'], ['capture_stderr_separately' => true]);

    expect(valueAt(decodedJson($tester->getDisplay()), 'summary.testFiles'))->toBe(0)
        ->and($tester->getErrorOutput())->toContain('No test file to analyse.');

    removeDirectory($project);
});

it('builds the map and compares across test files', function (): void {
    $project = pestProject();
    $map = command($project, 'map');
    $map->execute([]);
    file_put_contents($project . '/src/Calculator.php', file_get_contents($project . '/src/Calculator.php') . "\n");
    $across = command($project, 'analyse');

    $across->execute(['paths' => ['tests/OtherCalculatorTest.php'], '--across' => true], ['capture_stderr_separately' => true]);

    expect($map->getDisplay())->toContain('coverage run: tests')->toContain('7 test cases mapped')
        ->and($across->getErrorOutput())->toContain('1 source files changed')
        ->and(valueAt(decodedJson($across->getDisplay()), 'summary.candidates'))->toBe(1);

    removeDirectory($project);
});

it('compares across test files without a warning when the map is current', function (): void {
    $project = pestProject();
    command($project, 'map')->execute(['paths' => ['tests']]);
    $across = command($project, 'analyse');

    $across->execute(['paths' => ['tests'], '--across' => true], ['capture_stderr_separately' => true]);

    expect($across->getErrorOutput())->toBe('')
        ->and(valueAt(decodedJson($across->getDisplay()), 'summary.candidates'))->toBe(4);

    removeDirectory($project);
});
