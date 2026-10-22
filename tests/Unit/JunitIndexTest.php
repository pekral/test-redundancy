<?php

declare(strict_types = 1);

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
