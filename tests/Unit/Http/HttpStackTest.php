<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\Http;

use B4x\Edoreczenia\Exception\EdoreczeniaException;
use B4x\Edoreczenia\Exception\HttpClientNotFoundException;
use B4x\Edoreczenia\Http\HttpStack;
use B4x\Edoreczenia\Tests\Support\MockHttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
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

    public function testResolveKeepsEverythingPassed(): void
    {
        $client = new MockHttpClient();
        $factory = new Psr17Factory();

        $stack = HttpStack::resolve($client, $factory, $factory);

        self::assertSame($client, $stack->client);
        self::assertSame($factory, $stack->requestFactory);
        self::assertSame($factory, $stack->streamFactory);
    }

    public function testResolveDetectsOnlyMissingFactories(): void
    {
        $client = new MockHttpClient();

        $stack = HttpStack::resolve($client);

        self::assertSame($client, $stack->client);
        self::assertInstanceOf(HttpFactory::class, $stack->requestFactory);
        self::assertInstanceOf(HttpFactory::class, $stack->streamFactory);
    }

    public function testFallsBackToNyholmFactories(): void
    {
        $stack = HttpStack::resolve(new MockHttpClient(), classExists: static fn(string $class): bool => $class === Psr17Factory::class);

        self::assertInstanceOf(Psr17Factory::class, $stack->requestFactory);
    }

    public function testThrowsHelpfulErrorWhenNoClientIsInstalled(): void
    {
        try {
            HttpStack::resolve(classExists: static fn(string $class): bool => false);
            self::fail('Expected an exception.');
        } catch (EdoreczeniaException $e) {
            self::assertInstanceOf(HttpClientNotFoundException::class, $e);
            self::assertStringContainsString('composer require guzzlehttp/guzzle', $e->getMessage());
        }
    }

    public function testThrowsWhenClientIsPassedButNoFactoriesAreInstalled(): void
    {
        $this->expectException(HttpClientNotFoundException::class);
        $this->expectExceptionMessage('No PSR-17 factories found.');

        HttpStack::resolve(new MockHttpClient(), classExists: static fn(string $class): bool => false);
    }
}
