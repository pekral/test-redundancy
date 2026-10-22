<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Configuration;
use Pekral\TestRedundancy\Coverage\CoverageReader;
use Pekral\TestRedundancy\Coverage\JunitIndex;

it('rejects a file that holds no coverage report', function (): void {
    $file = (string) tempnam(sys_get_temp_dir(), 'coverage');
    file_put_contents($file, '<?php return 1;');

    expect(fn (): array => new CoverageReader(Configuration::load('/project'))->linesByTest($file, JunitIndex::fromFile('/missing', '/project', [])))
        ->toThrow(RuntimeException::class, 'does not hold a php-code-coverage report');

    unlink($file);
});

it('rejects an empty coverage file name', function (): void {
    expect(fn (): array => new CoverageReader(Configuration::load('/project'))->linesByTest('', JunitIndex::fromFile('/missing', '/project', [])))
        ->toThrow(RuntimeException::class, 'No coverage file was given.');
});
