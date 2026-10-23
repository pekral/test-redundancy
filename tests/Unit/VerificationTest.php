<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Analysis\Verification;

it('tells an agent to restore a test only when a covering test changed', function (): void {
    $verification = new Verification(4, ['src/A.php:1'], [], []);

    expect($verification->isOk())->toBeFalse()
        ->and($verification->jsonSerialize())->toMatchArray([
            'instruction' => 'Coverage dropped. Restore the test that covered each line in "lostLines", then run verify again.',
            'lostLines' => ['src/A.php:1'],
            'ok' => false,
            'unstableLines' => [],
        ]);
});

it('does not tell an agent to restore a test for unstable coverage', function (): void {
    $verification = new Verification(4, [], [], ['src/A.php:1']);

    expect($verification->isOk())->toBeTrue()
        ->and($verification->jsonSerialize())->toMatchArray([
            'instruction' => 'No test file that covered the lines in "unstableLines" changed, '
                . 'but their coverage differs from the baseline. Do not restore a test. '
                . 'The coverage of an unchanged test depends on state outside the test run, such as a cache or a database. '
                . 'Make that test independent of the state, or run analyse again for a new baseline.',
            'lostLines' => [],
            'ok' => true,
            'unstableLines' => ['src/A.php:1'],
        ]);
});

it('keeps the restore instruction and names the unstable lines when both kinds exist', function (): void {
    $verification = new Verification(4, ['src/A.php:1'], [], ['src/B.php:2']);

    expect($verification->isOk())->toBeFalse()
        ->and($verification->jsonSerialize())->toMatchArray([
            'instruction' => 'Coverage dropped. Restore the test that covered each line in "lostLines", then run verify again. '
                . 'Do not restore a test for "unstableLines": no test covering those lines changed.',
            'lostLines' => ['src/A.php:1'],
            'unstableLines' => ['src/B.php:2'],
        ]);
});

it('names the changed support file instead of a test to restore when a support file changed', function (): void {
    $verification = new Verification(4, ['src/A.php:1'], [], [], supportChanged: true);

    expect($verification->isOk())->toBeFalse()
        ->and($verification->jsonSerialize())->toMatchArray([
            'instruction' => 'Coverage dropped, and a test support file changed since analyse, '
                . 'so the lines in "lostLines" may be lost because of that change and not because a test was removed. '
                . 'Check the changed support file, then run verify again. Run analyse again when the change is intended.',
            'lostLines' => ['src/A.php:1'],
        ]);
});

it('keeps the restore instruction and names the support change when a test file is missing', function (): void {
    $verification = new Verification(4, ['src/A.php:1'], ['tests/Unit/ATest.php'], [], supportChanged: true);

    expect($verification->jsonSerialize())->toMatchArray([
        'instruction' => 'Coverage dropped. Restore the test that covered each line in "lostLines", then run verify again. '
            . 'A test support file also changed since analyse, so a line in "lostLines" may also be lost because of that change.',
        'missingTestFiles' => ['tests/Unit/ATest.php'],
    ]);
});

it('reports a clean verification when nothing differs', function (): void {
    $verification = new Verification(4, [], [], []);

    expect($verification->isOk())->toBeTrue()
        ->and($verification->jsonSerialize())->toMatchArray([
            'instruction' => 'Every source line the analysed tests covered before is still covered.',
            'unstableLines' => [],
        ]);
});
