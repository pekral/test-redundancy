<?php

declare(strict_types = 1);

namespace Pekral\TestRedundancy\Tests\Fixtures\NoTestCaseSupport;

use PHPUnit\Framework\TestCase;

abstract class AbstractNameCase extends TestCase
{

    public function testNamesAStranger(): void
    {
        self::assertSame('stranger', $this->subject());
    }

    abstract protected function subject(): string;

}
