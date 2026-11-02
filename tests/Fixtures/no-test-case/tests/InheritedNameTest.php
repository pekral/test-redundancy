<?php

declare(strict_types = 1);

namespace Fixture\NoTestCase\Tests;

use Pekral\TestRedundancy\Tests\Fixtures\NoTestCaseSupport\AbstractNameCase;

final class InheritedNameTest extends AbstractNameCase
{

    protected function subject(): string
    {
        return 'stranger';
    }

}
