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
    removeDirectory($project . '/build');
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'map' => 'build/coverage-map.json.gz',
        'processes' => 2,
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

it('warns about a test file that covers no source line', function (): void {
    $project = pestProject();
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'source' => ['lib'],
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/pest',
        'processes' => 2,
    ]));
    $tester = command($project, 'analyse');

    $tester->execute(['paths' => ['tests/CalculatorTest.php'], '--format' => 'text'], ['capture_stderr_separately' => true]);

    expect($tester->getErrorOutput())->toContain('tests/CalculatorTest.php covers no line')
        ->and($tester->getDisplay())->toContain('Tests without source lines')->toContain('it adds zero');

    removeDirectory($project);
});

it('runs the analyse command when the first argument is an option', function (): void {
    $application = new Application('/project');

    expect($application->withDefaultCommand(['bin', '--format=text']))->toBe(['bin', 'analyse', '--format=text'])
        ->and($application->withDefaultCommand(['bin', 'tests']))->toBe(['bin', 'analyse', 'tests'])
        ->and($application->withDefaultCommand(['bin']))->toBe(['bin', 'analyse'])
        ->and($application->withDefaultCommand([]))->toBe(['test-redundancy', 'analyse'])
        ->and($application->withDefaultCommand(['bin', 'map', 'tests']))->toBe(['bin', 'map', 'tests'])
        ->and($application->withDefaultCommand(['bin', '--help']))->toBe(['bin', '--help']);
});

it('runs the binary with an option and no path', function (): void {
    $project = pestProject();
    $binary = dirname(__DIR__, 2) . '/bin/test-redundancy';

    $text = runIn($project, [PHP_BINARY, $binary, '--format=text', 'tests/CalculatorTest.php']);
    $noPath = runIn($project, [PHP_BINARY, $binary, '--base=master']);

    expect($text)->toContain('tests/CalculatorTest.php')->toContain('Tests to review')
        ->and(valueAt(decodedJson($noPath), 'summary.testFiles'))->toBe(0);

    removeDirectory($project);
});

it('verifies that no covered source line was lost', function (): void {
    $project = pestProject();
    command($project, 'analyse')->execute(['paths' => ['tests']]);
    $calculatorTest = $project . '/tests/CalculatorTest.php';
    $original = (string) file_get_contents($calculatorTest);

    file_put_contents($calculatorTest, (string) preg_replace("/it\\('adds zero'.*?\\}\\);\n/s", '', $original));
    $kept = command($project, 'verify');
    $kept->execute(['--pretty' => true]);

    file_put_contents($calculatorTest, (string) preg_replace("/it\\('rejects a division by zero'.*?'Division by zero\\.'\\);\n/s", '', $original));
    $lost = command($project, 'verify');
    $lost->execute([]);

    unlink($project . '/tests/OtherCalculatorTest.php');
    $missing = command($project, 'verify');
    $missing->execute(['--processes' => '3']);

    expect($kept->getStatusCode())->toBe(0)
        ->and(valueAt(decodedJson($kept->getDisplay()), 'ok'))->toBeTrue()
        ->and($lost->getStatusCode())->toBe(1)
        ->and(valueAt(decodedJson($lost->getDisplay()), 'lostLines'))->toBe(['src/Calculator.php:18'])
        ->and(valueAt(decodedJson($missing->getDisplay()), 'missingTestFiles'))->toBe(['tests/OtherCalculatorTest.php']);

    removeDirectory($project);
});

it('verifies against the coverage map after a cross-file analysis', function (): void {
    $project = pestProject();
    command($project, 'map')->execute([]);
    command($project, 'analyse')->execute(['paths' => ['tests/CalculatorTest.php'], '--across' => true]);
    $calculatorTest = $project . '/tests/CalculatorTest.php';
    file_put_contents(
        $calculatorTest,
        (string) preg_replace("/it\\('divides two numbers'.*?\\}\\);\n/s", '', (string) file_get_contents($calculatorTest)),
    );
    $verify = command($project, 'verify');

    $verify->execute([]);
    unlink($calculatorTest);
    $deleted = command($project, 'verify');
    $deleted->execute([]);

    expect($verify->getStatusCode())->toBe(0)
        ->and(valueAt(decodedJson($verify->getDisplay()), 'lostLines'))->toBe([])
        ->and($deleted->getStatusCode())->toBe(1)
        ->and(valueAt(decodedJson($deleted->getDisplay()), 'lostLines'))->toContain('src/Calculator.php:18');

    removeDirectory($project);
});

it('fails to verify without a baseline', function (): void {
    $project = pestProject();

    expect(fn (): int => command($project, 'verify')->execute([]))->toThrow(RuntimeException::class, 'No baseline');

    removeDirectory($project);
});
