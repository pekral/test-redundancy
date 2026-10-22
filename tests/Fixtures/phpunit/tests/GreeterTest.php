<?php

declare(strict_types = 1);

namespace Fixture\PhpUnit\Tests;

require_once __DIR__ . '/../src/Greeter.php';

use Fixture\PhpUnit\Greeter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GreeterTest extends TestCase
{

    #[DataProvider('names')]
    public function testGreetsByName(string $name, string $greeting): void
    {
        self::assertSame($greeting, (new Greeter())->greet($name));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'jane' => ['Jane', 'Hello, Jane!'];
        yield 'john' => ['John', 'Hello, John!'];
    }

    #[Test]
    public function greetsAStranger(): void
    {
        self::assertSame('Hello, stranger!', (new Greeter())->greet(''));
    }

    public function testGreetsJane(): void
    {
        self::assertSame('Hello, Jane!', (new Greeter())->greet('Jane'));
    }

}
