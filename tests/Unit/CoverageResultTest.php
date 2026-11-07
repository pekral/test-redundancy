<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Coverage\CoverageResult;

it('replaces the results of the retried files with the retry and lists the tests that passed on the retry', function (): void {
    $first = new CoverageResult(
        ['tests/ATest.php :: it a' => ['src/A.php:1' => true], 'tests/BTest.php :: it ok' => ['src/B.php:1' => true]],
        ['tests/BTest.php :: it flakes' => 'failed', 'tests/BTest.php :: it fails' => 'failed', 'tests/CTest.php :: it gone' => 'failed'],
        ['tests/ATest.php :: it a' => 1.0, 'tests/BTest.php :: it ok' => 9.0],
        [],
    );
    $retry = new CoverageResult(
        ['tests/BTest.php :: it ok' => ['src/B.php:1' => true], 'tests/BTest.php :: it flakes' => ['src/B.php:2' => true]],
        ['tests/BTest.php :: it fails' => 'failed again'],
        ['tests/BTest.php :: it ok' => 0.5, 'tests/BTest.php :: it flakes' => 0.25],
        [],
    );

    $result = $first->withRetry($retry, ['tests/BTest.php']);

    expect($result->linesByTest)->toBe([
        'tests/ATest.php :: it a' => ['src/A.php:1' => true],
        'tests/BTest.php :: it ok' => ['src/B.php:1' => true],
        'tests/BTest.php :: it flakes' => ['src/B.php:2' => true],
    ])->and($result->failedTests)->toBe(['tests/CTest.php :: it gone' => 'failed', 'tests/BTest.php :: it fails' => 'failed again'])
        ->and($result->seconds)->toBe(['tests/ATest.php :: it a' => 1.0, 'tests/BTest.php :: it ok' => 0.5, 'tests/BTest.php :: it flakes' => 0.25])
        ->and($result->passedOnRetry)->toBe(['tests/BTest.php :: it flakes']);
});
