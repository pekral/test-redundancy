<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Analysis\Candidate;
use Pekral\TestRedundancy\Analysis\FileReport;
use Pekral\TestRedundancy\Console\Application;
use Pekral\TestRedundancy\Console\ReportPrinter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

function command(string $projectRoot, string $name): CommandTester
{
    return new CommandTester(new Application($projectRoot)->find($name));
}

function pestProject(): string
{
    $project = gitRepository();
    new Filesystem()->mirror(fixturePath('pest'), $project, options: ['override' => true]);
    removeDirectory($project . '/build');
    file_put_contents($project . '/test-redundancy.json', (string) json_encode([
        'map' => 'build/coverage-map.json.gz',
        'runner' => dirname(__DIR__, 2) . '/vendor/bin/pest',
        'source' => ['src'],
    ]));
    runIn($project, ['git', 'add', '.']);
    runIn($project, ['git', 'commit', '--quiet', '-m', 'fixture']);

    return $project;
}

function warmCacheProject(): string
{
    $project = pestProject();
    file_put_contents($project . '/tests/Pest.php', "<?php\n\ndeclare(strict_types = 1);\n");
    file_put_contents($project . '/tests/WarmCacheTest.php', <<<'PHP'
        <?php

        declare(strict_types = 1);

        require_once __DIR__ . '/../src/Calculator.php';

        use Fixture\Pest\Calculator;

        it('reads a warm cache', function (): void {
            $cacheMarker = __DIR__ . '/../warm-cache';

            if (!is_file($cacheMarker)) {
                touch($cacheMarker);
                expect(fn (): float => (new Calculator())->divide(1, 0))->toThrow(InvalidArgumentException::class);
            }

            expect(is_file($cacheMarker))->toBeTrue();
        });

        it('adds beside the warm cache', function (): void {
            expect((new Calculator())->add(1, 1))->toBe(2);
        });
        PHP);

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
        ->toContain('tests/OtherCalculatorTest.php')
        ->toContain('Skipped: the file declares one test case and no dataset');
});

it('reports a file without candidates as covering a line no other test case covers', function (): void {
    $output = new BufferedOutput();

    new ReportPrinter(new SymfonyStyle(new ArrayInput([]), $output))->printText([new FileReport('tests/FooTest.php', 2, 3, [], [])]);

    expect($output->fetch())->toContain('Every test case covers a line no other test case covers.');
});

it('prints the covering-side tokens that made the action review in the text report', function (): void {
    $output = new BufferedOutput();
    $candidates = [
        new Candidate('tests/FooTest.php :: it stores', ['it forgets'], ['value:1'], ['value:2', 'call:forget'], [], assertsNothing: false),
        new Candidate('tests/FooTest.php :: it adds', ['it subtracts'], ['value:1'], ['value:2'], [], assertsNothing: false),
    ];

    new ReportPrinter(new SymfonyStyle(new ArrayInput([]), $output))->printText([new FileReport('tests/FooTest.php', 4, 2, $candidates, [])]);

    $display = $output->fetch();

    expect($display)->toContain("differs by: value:1\n    covering differs by: call:forget")
        ->and(substr_count($display, 'covering differs by'))->toBe(1);
});

it('prints the lines only the union of the covering tests reaches in the text report', function (): void {
    $output = new BufferedOutput();
    $candidate = new Candidate(
        'tests/FooTest.php :: it stores',
        ['it forgets', 'it reads'],
        [],
        [],
        ['src/Foo.php:12', 'src/Foo.php:15'],
        assertsNothing: false,
    );

    new ReportPrinter(new SymfonyStyle(new ArrayInput([]), $output))->printText([
        new FileReport('tests/FooTest.php', 3, 2, [$candidate], []),
    ]);

    $display = $output->fetch();

    expect($display)->toContain("differs by: \n    union only lines: src/Foo.php:12, src/Foo.php:15")
        ->and($display)->not->toContain('No new value or call');
});

it('names a skipped test file in the text report instead of reporting its coverage', function (): void {
    $output = new BufferedOutput();

    new ReportPrinter(new SymfonyStyle(new ArrayInput([]), $output))->printText([FileReport::skipped('tests/OneTest.php')]);

    $display = $output->fetch();

    expect($display)->toContain('tests/OneTest.php')
        ->toContain('Skipped: the file declares one test case and no dataset, so no other test case of the file can cover it.')
        ->and($display)->not->toContain('Covered lines')
        ->and($display)->not->toContain('Every test case covers a line no other test case covers.');
});

it('counts a skipped test file in the summary without a warning about its source lines', function (): void {
    $project = pestProject();
    $tester = command($project, 'analyse');

    $tester->execute(['paths' => ['tests']], ['capture_stderr_separately' => true]);
    $report = decodedJson($tester->getDisplay());

    expect($tester->getErrorOutput())->toBe('')
        ->and(valueAt($report, 'summary.testFiles'))->toBe(2)
        ->and(valueAt($report, 'summary.testCases'))->toBe(7)
        ->and(valueAt($report, 'summary.skippedFiles'))->toBe(1)
        ->and(valueAt($report, 'summary.candidates'))->toBe(3)
        ->and(array_column((array) valueAt($report, 'files'), 'file'))->toBe(['tests/CalculatorTest.php']);

    removeDirectory($project);
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
    file_put_contents($project . '/tests/OtherCalculatorTest.php', <<<'PHP'


        test('divide returns another float', function (): void {
            expect((new Calculator())->divide(9, 3))->toBe(3.0);
        });
        PHP, FILE_APPEND);
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

it('reports unstable coverage instead of lost lines when no covering test file changed', function (): void {
    $project = warmCacheProject();
    command($project, 'analyse')->execute(['paths' => ['tests/WarmCacheTest.php']]);
    $verify = command($project, 'verify');

    $verify->execute([]);
    $report = decodedJson($verify->getDisplay());

    expect($verify->getStatusCode())->toBe(0)
        ->and(valueAt($report, 'ok'))->toBeTrue()
        ->and(valueAt($report, 'lostLines'))->toBe([])
        ->and(valueAt($report, 'unstableLines'))->toBe(['src/Calculator.php:17', 'src/Calculator.php:18'])
        ->and(valueAt($report, 'instruction'))->toBe(
            'No test file that covered the lines in "unstableLines" changed, but their coverage differs from the baseline. Do not restore a test. '
            . 'The coverage of an unchanged test depends on state outside the test run, such as a cache or a database. '
            . 'Make that test independent of the state, or run analyse again for a new baseline.',
        );

    removeDirectory($project);
});

it('keeps reporting a lost line when a covering test file changed', function (): void {
    $project = warmCacheProject();
    command($project, 'analyse')->execute(['paths' => ['tests/WarmCacheTest.php']]);
    file_put_contents($project . '/tests/WarmCacheTest.php', "\nit('is another test', fn () => expect(true)->toBeTrue());\n", FILE_APPEND);
    $verify = command($project, 'verify');

    $verify->execute([]);
    $report = decodedJson($verify->getDisplay());

    expect($verify->getStatusCode())->toBe(1)
        ->and(valueAt($report, 'lostLines'))->toBe(['src/Calculator.php:17', 'src/Calculator.php:18'])
        ->and(valueAt($report, 'unstableLines'))->toBe([])
        ->and(valueAt($report, 'instruction'))->toContain('Restore the test');

    removeDirectory($project);
});

it('keeps reporting a lost line when a changed test file covered it beside an unchanged one', function (): void {
    $project = warmCacheProject();
    command($project, 'analyse')->execute(['paths' => ['tests']]);
    $calculatorTest = $project . '/tests/CalculatorTest.php';
    file_put_contents(
        $calculatorTest,
        (string) preg_replace("/it\\('rejects a division by zero'.*?'Division by zero\\.'\\);\n/s", '', (string) file_get_contents($calculatorTest)),
    );
    $verify = command($project, 'verify');

    $verify->execute([]);
    $report = decodedJson($verify->getDisplay());

    expect($verify->getStatusCode())->toBe(1)
        ->and(valueAt($report, 'lostLines'))->toBe(['src/Calculator.php:18'])
        ->and(valueAt($report, 'unstableLines'))->toBe([]);

    removeDirectory($project);
});

it('keeps reporting a lost line when a covering test file was deleted', function (): void {
    $project = warmCacheProject();
    command($project, 'analyse')->execute(['paths' => ['tests']]);
    unlink($project . '/tests/CalculatorTest.php');
    unlink($project . '/tests/OtherCalculatorTest.php');
    unlink($project . '/tests/WarmCacheTest.php');
    $verify = command($project, 'verify');

    $verify->execute([]);
    $report = decodedJson($verify->getDisplay());

    expect($verify->getStatusCode())->toBe(1)
        ->and(valueAt($report, 'lostLines'))->toContain('src/Calculator.php:18')
        ->and(valueAt($report, 'unstableLines'))->toBe([]);

    removeDirectory($project);
});

it('stores a hash of each covered source file in the baseline', function (): void {
    $project = warmCacheProject();
    command($project, 'analyse')->execute(['paths' => ['tests/WarmCacheTest.php']]);
    $baseline = decodedJson((string) file_get_contents($project . '/build/baseline.json'));

    expect(valueAt($baseline, 'sources'))->toBe(['src/Calculator.php' => hash_file('sha256', $project . '/src/Calculator.php')]);

    removeDirectory($project);
});

it('reports a lost line of a changed source file as lost even when no covering test file changed', function (): void {
    $project = warmCacheProject();
    command($project, 'analyse')->execute(['paths' => ['tests/WarmCacheTest.php']]);
    file_put_contents($project . '/src/Calculator.php', "\n// the source changed after analyse\n", FILE_APPEND);
    $verify = command($project, 'verify');

    $verify->execute([]);
    $report = decodedJson($verify->getDisplay());

    expect($verify->getStatusCode())->toBe(1)
        ->and(valueAt($report, 'lostLines'))->toBe(['src/Calculator.php:17', 'src/Calculator.php:18'])
        ->and(valueAt($report, 'unstableLines'))->toBe([])
        ->and(valueAt($report, 'instruction'))->toContain('Restore the test');

    removeDirectory($project);
});

it('reports every lost line of a baseline written before source file hashes existed', function (): void {
    $project = warmCacheProject();
    command($project, 'analyse')->execute(['paths' => ['tests/WarmCacheTest.php']]);
    $baselinePath = $project . '/build/baseline.json';
    $baseline = decodedJson((string) file_get_contents($baselinePath));
    unset($baseline['sources']);
    file_put_contents($baselinePath, (string) json_encode($baseline));
    $verify = command($project, 'verify');

    $verify->execute([]);
    $report = decodedJson($verify->getDisplay());

    expect($verify->getStatusCode())->toBe(1)
        ->and(valueAt($report, 'lostLines'))->toBe(['src/Calculator.php:17', 'src/Calculator.php:18'])
        ->and(valueAt($report, 'unstableLines'))->toBe([]);

    removeDirectory($project);
});

it('reports a lost line as lost when a test support file changed since analyse', function (Closure $change): void {
    $project = warmCacheProject();
    command($project, 'analyse')->execute(['paths' => ['tests/WarmCacheTest.php']]);
    $change($project);
    $verify = command($project, 'verify');

    $verify->execute([]);
    $report = decodedJson($verify->getDisplay());

    expect($verify->getStatusCode())->toBe(1)
        ->and(valueAt($report, 'lostLines'))->toBe(['src/Calculator.php:17', 'src/Calculator.php:18'])
        ->and(valueAt($report, 'unstableLines'))->toBe([])
        ->and(valueAt($report, 'instruction'))->toBe(
            'Coverage dropped, and a test support file changed since analyse, so the lines in "lostLines" may be lost because of that change '
            . 'and not because a test was removed. Check the changed support file, then run verify again. Run analyse again when the change is intended.',
        );

    removeDirectory($project);
})->with([
    'added' => static fn (string $project): int|bool => file_put_contents($project . '/tests/Datasets.php', "<?php\n"),
    'added dot file' => static fn (string $project): int|bool => file_put_contents($project . '/tests/.env.testing', "APP_ENV=testing\n"),
    'deleted' => static fn (string $project): bool => unlink($project . '/tests/Pest.php'),
    'edited' => static fn (string $project): int|bool => file_put_contents(
        $project . '/tests/Pest.php',
        "\n// the shared setup changed after analyse\n",
        FILE_APPEND,
    ),
]);

it('reports every lost line of a baseline written before test support file hashes existed', function (): void {
    $project = warmCacheProject();
    command($project, 'analyse')->execute(['paths' => ['tests/WarmCacheTest.php']]);
    $baselinePath = $project . '/build/baseline.json';
    $baseline = decodedJson((string) file_get_contents($baselinePath));
    unset($baseline['support']);
    file_put_contents($baselinePath, (string) json_encode($baseline));
    $verify = command($project, 'verify');

    $verify->execute([]);
    $report = decodedJson($verify->getDisplay());

    expect($verify->getStatusCode())->toBe(1)
        ->and(valueAt($report, 'lostLines'))->toBe(['src/Calculator.php:17', 'src/Calculator.php:18'])
        ->and(valueAt($report, 'unstableLines'))->toBe([])
        ->and(valueAt($report, 'instruction'))->toContain('Restore the test');

    removeDirectory($project);
});

it('reports every lost line of a baseline written before test file hashes existed', function (): void {
    $project = warmCacheProject();
    command($project, 'analyse')->execute(['paths' => ['tests/WarmCacheTest.php']]);
    $baselinePath = $project . '/build/baseline.json';
    file_put_contents(
        $baselinePath,
        (string) json_encode(['across' => false, 'lines' => ['src/Calculator.php:18'], 'testFiles' => ['tests/WarmCacheTest.php']]),
    );
    $verify = command($project, 'verify');

    $verify->execute([]);
    $report = decodedJson($verify->getDisplay());

    expect($verify->getStatusCode())->toBe(1)
        ->and(valueAt($report, 'lostLines'))->toBe(['src/Calculator.php:18'])
        ->and(valueAt($report, 'unstableLines'))->toBe([]);

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
