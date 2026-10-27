<?php

declare(strict_types = 1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkip([__DIR__ . '/tests/Fixtures'])
    ->withSets([__DIR__ . '/vendor/pekral/rector-rules/rector.php'])
    ->withParallel(timeoutSeconds: 180, maxNumberOfProcess: 6, jobSize: 60);
