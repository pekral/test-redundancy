<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Configuration;
use Pekral\TestRedundancy\Coverage\CoverageReader;
use Pekral\TestRedundancy\Coverage\JunitIndex;
use Pekral\TestRedundancy\Coverage\PhpUnitClasses;

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

it('reports a failed PHPUnit test case of an integer dataset key by the key of the coverage report', function (): void {
    $root = temporaryDirectory();
    mkdir($root . '/tests', recursive: true);
    touch($root . '/tests/FooTest.php');
    $index = junitIndex(<<<'XML'
        <testsuites>
            <testcase name="testErrors with data set #1" file="tests/FooTest.php" class="Tests\FooTest">
                <error type="Error">failed</error>
            </testcase>
        </testsuites>
        XML, $root);

    expect(array_keys(new CoverageReader(Configuration::load($root))->failedTests($index)))
        ->toBe([CoverageReader::testKeyOf('Tests\FooTest::testErrors#1', $index)])
        ->and(CoverageReader::testKeyOf('Tests\FooTest::testErrors with data set #1', $index))
        ->toBe('tests/FooTest.php :: testErrors › 1');

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

it('reports the seconds of each test case by the key of the coverage report', function (): void {
    $root = temporaryDirectory();
    mkdir($root . '/tests', recursive: true);
    touch($root . '/tests/FooTest.php');
    $index = junitIndex(<<<'XML'
        <testsuites>
            <testcase name="it rows with data set &quot;dataset &quot;one&quot;&quot;" file="tests/FooTest.php::it rows" class="P" time="0.5"/>
            <testcase name="it rows with data set &quot;dataset &quot;two&quot;&quot;" file="tests/FooTest.php::it rows" class="P" time="0.25"/>
            <testcase name="it plain" file="tests/FooTest.php::it plain" class="P" time="1"/>
        </testsuites>
        XML, $root);

    expect(new CoverageReader(Configuration::load($root))->secondsByTest($index))->toBe([
        'tests/FooTest.php :: it rows › one' => 0.5,
        'tests/FooTest.php :: it rows › two' => 0.25,
        'tests/FooTest.php :: it plain' => 1.0,
    ]);

    removeDirectory($root);
});

it('maps the JUnit entry Pest writes for a PHPUnit class to the file and the method of the class (#182)', function (): void {
    $root = temporaryDirectory();
    mkdir($root . '/tests/Unit', recursive: true);
    file_put_contents($root . '/tests/Unit/ProbeTest.php', <<<'PHP'
        <?php
        namespace Tests\Unit;

        final class OtherTest {}

        final class ProbeTest extends \PHPUnit\Framework\TestCase
        {
            public function testNormalizesCodes(): void {}

            public function test_counts_rows(): void {}
        }
        PHP);
    file_put_contents($root . '/tests/Unit/OtherTest.php', '<?php final class Unrelated {}');
    mkdir($root . '/tests/Feature');
    file_put_contents($root . '/tests/Feature/ProbeTest.php', '<?php namespace Tests\\Feature; final class ProbeTest {}');
    $classes = new PhpUnitClasses([
        $root . '/tests/Unit/OtherTest.php',
        $root . '/tests/Feature/ProbeTest.php',
        $root . '/tests/Unit/ProbeTest.php',
    ]);
    $file = (string) tempnam(sys_get_temp_dir(), 'junit');
    file_put_contents($file, <<<'XML'
        <testsuites>
            <testcase name="Normalizes codes with data set #0" file="Probe (Tests\Unit\Probe)::Normalizes codes with data set #0"
                class="Tests\Unit\ProbeTest" time="0.5"/>
            <testcase name="Counts rows" file="Probe (Tests\Unit\Probe)::Counts rows" class="Tests\Unit\ProbeTest" time="0.25">
                <failure type="Error">Counts rows
        failed</failure>
            </testcase>
            <testcase name="Unknown" file="Unknown (Tests\Unit\Unknown)::Unknown" class="Tests\Unit\UnknownTest"/>
        </testsuites>
        XML);
    $index = JunitIndex::fromFile($file, $root, [], $classes);
    unlink($file);

    expect(new CoverageReader(Configuration::load($root))->secondsByTest($index))->toBe([
        'tests/Unit/ProbeTest.php :: testNormalizesCodes › 0' => 0.5,
        'tests/Unit/ProbeTest.php :: test_counts_rows' => 0.25,
        'Unknown (Tests\Unit\Unknown) :: Unknown' => 0.0,
    ])->and(new CoverageReader(Configuration::load($root))->failedTests($index))->toBe([
        'tests/Unit/ProbeTest.php :: test_counts_rows' => 'failed',
    ])->and(CoverageReader::testKeyOf('Tests\Unit\ProbeTest::testNormalizesCodes#0', $index))
        ->toBe('tests/Unit/ProbeTest.php :: testNormalizesCodes › 0')
        ->and($classes->of('Tests\Unit\ProbeTest'))->toBe($classes->of('Tests\Unit\ProbeTest'))
        ->and($classes->of('Tests\Unit\MissingTest'))->toBeNull();

    removeDirectory($root);
});
