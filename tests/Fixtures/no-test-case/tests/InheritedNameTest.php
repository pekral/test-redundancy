<?php

declare(strict_types = 1);

namespace Fixture\NoTestCase\Tests;

require_once __DIR__ . '/Support/AbstractNameCase.php';

use Fixture\NoTestCase\Tests\Support\AbstractNameCase;

final class InheritedNameTest extends AbstractNameCase
{

    protected function subject(): string
    {
        return 'stranger';
    }

}
