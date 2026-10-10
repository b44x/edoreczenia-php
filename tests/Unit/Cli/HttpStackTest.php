<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\Cli;

use B4x\Edoreczenia\Cli\HttpStack;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;

final class HttpStackTest extends TestCase
{
    public function testPrefersGuzzleWhenInstalled(): void
    {
        $stack = HttpStack::detect(7);

        self::assertInstanceOf(Client::class, $stack->client);
        self::assertInstanceOf(HttpFactory::class, $stack->requestFactory);
        self::assertInstanceOf(HttpFactory::class, $stack->streamFactory);
    }
}
