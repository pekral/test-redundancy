<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Configuration;
use Pekral\TestRedundancy\Coverage\CoverageReader;
use Pekral\TestRedundancy\Coverage\JunitIndex;

/**
 * @param list<string> $searchRoots
 */
function junitIndex(string $xml, string $projectRoot, array $searchRoots = []): JunitIndex
{
    $file = (string) tempnam(sys_get_temp_dir(), 'junit');
    file_put_contents($file, $xml);
    $index = JunitIndex::fromFile($file, $projectRoot, $searchRoots);
    unlink($file);

    return $index;
}

it('maps Pest and PHPUnit test ids to files and descriptions', function (): void {
    $root = temporaryDirectory();
    mkdir($root . '/tests/Unit', recursive: true);
    touch($root . '/tests/Unit/FooTest.php');
    touch($root . '/tests/Unit/BarTest.php');
    $index = junitIndex(<<<XML
        <testsuites>
            <testcase name="it adds 1, 2 with data set &quot;dataset &quot;small&quot;&quot;"
                file="tests/Unit/FooTest.php::it adds 1, 2" class="Tests\Unit\FooTest"/>
            <testcase name="testBar with data set &quot;first&quot;" file="{$root}/tests/Unit/BarTest.php" class="Tests\Unit\BarTest"/>
            <testcase name="testBaz" file="" class="Tests\Unit\BazTest"/>
        </testsuites>
        XML, $root);

    expect(CoverageReader::testKeyOf('P\Tests\Unit\FooTest::__pest_evaluable_it_adds_1__2#dataset "small"', $index))
        ->toBe('tests/Unit/FooTest.php :: it adds 1, 2 › small')
        ->and(CoverageReader::testKeyOf('Tests\Unit\BarTest::testBar with data set "first"', $index))
        ->toBe('tests/Unit/BarTest.php :: testBar › first')
        ->and(CoverageReader::testKeyOf('Tests\Unit\BarTest::testBar#1', $index))
        ->toBe('tests/Unit/BarTest.php :: testBar › 1')
        ->and(CoverageReader::testKeyOf('Tests\Unit\BazTest::testBaz', $index))
        ->toBe('Tests\Unit\BazTest :: testBaz')
        ->and($index->descriptionOf('Tests\Unit\FooTest', '__pest_evaluable_unknown'))->toBe('__pest_evaluable_unknown');

    removeDirectory($root);
});

it('finds a test file relative to the runner root', function (): void {
    $runnerRoot = temporaryDirectory();
    mkdir($runnerRoot . '/project/tests', recursive: true);
    touch($runnerRoot . '/project/tests/FooTest.php');
    $index = junitIndex(
        '<testsuites><testcase name="it works" file="project/tests/FooTest.php::it works" class="P"/>'
        . '<testcase name="it is gone" file="gone/GoneTest.php" class="Gone"/></testsuites>',
        $runnerRoot . '/project',
        [$runnerRoot],
    );

    expect($index->fileOf('P'))->toBe('tests/FooTest.php')
        ->and($index->fileOf('Gone'))->toBe('gone/GoneTest.php');

    removeDirectory($runnerRoot);
});

it('reads a missing or broken JUnit log as empty', function (): void {
    expect(JunitIndex::fromFile('/missing/junit.xml', '/project', [])->fileOf('Foo'))->toBe('Foo')
        ->and(junitIndex('not xml', '/project')->fileOf('Foo'))->toBe('Foo');
});

it('sums the test case time per test file', function (): void {
    $root = temporaryDirectory();
    mkdir($root . '/tests', recursive: true);
    touch($root . '/tests/FooTest.php');
    touch($root . '/tests/BarTest.php');
    $index = junitIndex(<<<XML
        <testsuites>
            <testcase name="it a" file="tests/FooTest.php::it a" class="Foo" time="0.25"/>
            <testcase name="it b with data set &quot;x&quot;" file="tests/FooTest.php::it b" class="Foo" time="1.5"/>
            <testcase name="it c" file="{$root}/tests/BarTest.php" class="Bar" time="2"/>
            <testcase name="it d" file="" class="Baz" time="9"/>
            <testcase name="it e" file="tests/BarTest.php" class="Bar"/>
        </testsuites>
        XML, $root);

    expect($index->secondsByFile())->toBe(['tests/FooTest.php' => 1.75, 'tests/BarTest.php' => 2.0]);

    removeDirectory($root);
});

it('reads the failure message of a failed or errored test case without its title', function (): void {
    $root = temporaryDirectory();
    mkdir($root . '/tests', recursive: true);
    touch($root . '/tests/FooTest.php');
    $index = junitIndex(<<<'XML'
        <testsuites>
            <testcase name="it fails" file="tests/FooTest.php::it fails" class="P">
                <failure type="PHPUnit\Framework\ExpectationFailedException">it failsFailed asserting that 2 is identical to 3.
        at tests/FooTest.php:4</failure>
            </testcase>
            <testcase name="testErrors with data set #1" file="tests/FooTest.php" class="Tests\FooTest">
                <error type="Error">Tests\FooTest::testErrors with data set #1
        List not found!</error>
            </testcase>
            <testcase name="testUntitled" file="tests/FooTest.php" class="Tests\FooTest">
                <failure type="Error">Something else</failure>
            </testcase>
            <testcase name="it passes" file="tests/FooTest.php::it passes" class="P"/>
        </testsuites>
        XML, $root);

    expect($index->failureByTestId())->toBe([
        'P::it fails' => "Failed asserting that 2 is identical to 3.\nat tests/FooTest.php:4",
        'Tests\FooTest::testErrors with data set #1' => 'List not found!',
        'Tests\FooTest::testUntitled' => 'Something else',
    ])->and($index->testsWithoutCoverage([]))->toBe(['tests/FooTest.php :: it passes']);

    removeDirectory($root);
});

it('reports a failed test case by the key of the coverage report', function (): void {
    $root = temporaryDirectory();
    mkdir($root . '/tests', recursive: true);
    touch($root . '/tests/FooTest.php');
    $index = junitIndex(<<<'XML'
        <testsuites>
            <testcase name="it rows with data set &quot;dataset &quot;bad row&quot;&quot;" file="tests/FooTest.php::it rows" class="P">
                <failure type="Error">failed</failure>
            </testcase>
            <testcase name="it rows with data set &quot;(1, 1, 5)&quot;" file="tests/FooTest.php::it rows" class="P">
                <failure type="Error">failed too</failure>
            </testcase>
            <testcase name="it rows with data set &quot;dataset &quot;good row&quot;&quot;" file="tests/FooTest.php::it rows" class="P"/>
        </testsuites>
        XML, $root);

    expect(new CoverageReader(Configuration::load($root))->failedTests($index))->toBe([
        'tests/FooTest.php :: it rows › bad row' => 'failed',
        'tests/FooTest.php :: it rows › (1, 1, 5)' => 'failed too',
    ])->and(CoverageReader::testKeyOf('P::__pest_evaluable_it_rows#dataset "bad row"', $index))
        ->toBe('tests/FooTest.php :: it rows › bad row');

    removeDirectory($root);
});

it('leaves a failed test case out of the lines and does not count it as a test without coverage', function (): void {
    $root = temporaryDirectory();
    mkdir($root . '/tests', recursive: true);
    touch($root . '/tests/FooTest.php');
    $index = junitIndex(<<<'XML'
        <testsuites>
            <testcase name="it fails" file="tests/FooTest.php::it fails" class="P"><failure type="Error">failed</failure></testcase>
            <testcase name="it is fine" file="tests/FooTest.php::it is fine" class="P"/>
        </testsuites>
        XML, $root);
    $coverage = (string) tempnam(sys_get_temp_dir(), 'coverage');
    file_put_contents($coverage, '<?php return new SebastianBergmann\CodeCoverage\CodeCoverage('
        . '(new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage(new SebastianBergmann\CodeCoverage\Filter()), '
        . 'new SebastianBergmann\CodeCoverage\Filter());');

    expect(new CoverageReader(Configuration::load($root))->linesByTest($coverage, $index))
        ->toBe(['tests/FooTest.php :: it is fine' => []]);

    unlink($coverage);
    removeDirectory($root);
});
