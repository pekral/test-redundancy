<?php

declare(strict_types = 1);

namespace Fixture\NoTestCase\Tests;

require_once __DIR__ . '/../src/Greeter.php';

use Fixture\NoTestCase\Greeter;
use PHPUnit\Framework\TestCase;

final class GreeterTest extends TestCase
{

    public function testGreetsAStranger(): void
    {
        self::assertSame('Hello, stranger!', (new Greeter())->greet(''));
    }

    public function testGreetsJane(): void
    {
        self::assertSame('Hello, Jane!', (new Greeter())->greet('Jane'));
    }

}
