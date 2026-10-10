<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Http;

use B4x\Edoreczenia\Exception\HttpClientNotFoundException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * PSR-18 client with the PSR-17 factories the SDK needs.
 *
 * Detection is a convenience fallback for applications that do not pass their own client.
 * No HTTP library is a dependency of this package, so candidates are looked up by class name
 * at runtime, in a fixed order: Guzzle, then Symfony HttpClient; factories from Guzzle PSR-7,
 * then Nyholm PSR-7.
 *
 * @internal
 */
final readonly class HttpStack
{
    public const DEFAULT_TIMEOUT_SECONDS = 30;

    private const GUZZLE_CLIENT = 'GuzzleHttp\Client';
    private const GUZZLE_FACTORY = 'GuzzleHttp\Psr7\HttpFactory';
    private const SYMFONY_PSR18 = 'Symfony\Component\HttpClient\Psr18Client';
    private const SYMFONY_CLIENT = 'Symfony\Component\HttpClient\HttpClient';
    private const NYHOLM_FACTORY = 'Nyholm\Psr7\Factory\Psr17Factory';

    private const INSTALL_HINT = 'Install one, e.g.: composer require guzzlehttp/guzzle, or pass your own PSR-18 client and PSR-17 factories.';

    public function __construct(
        public ClientInterface $client,
        public RequestFactoryInterface $requestFactory,
        public StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * Fills in whatever was not passed. A passed client is never replaced or reconfigured.
     *
     * @param (\Closure(string): bool)|null $classExists for tests only
     *
     * @throws HttpClientNotFoundException
     */
    public static function resolve(
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?\Closure $classExists = null,
    ): self {
        $client ??= self::detect($timeoutSeconds, $classExists)->client;

        // Symfony's Psr18Client is its own PSR-17 factory.
        if ($requestFactory === null && $client instanceof RequestFactoryInterface) {
            $requestFactory = $client;
        }
        if ($streamFactory === null && $client instanceof StreamFactoryInterface) {
            $streamFactory = $client;
        }

        if ($requestFactory === null || $streamFactory === null) {
            $factory = self::detectFactory($classExists);
            $requestFactory ??= $factory;
            $streamFactory ??= $factory;
        }

        return new self($client, $requestFactory, $streamFactory);
    }

    /**
     * Builds a client from whatever the application has installed, with the given total timeout
     * and a connect timeout of at most 10 s.
     *
     * @param (\Closure(string): bool)|null $classExists for tests only
     *
     * @throws HttpClientNotFoundException
     */
    public static function detect(int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS, ?\Closure $classExists = null): self
    {
        $exists = $classExists ?? static fn(string $class): bool => class_exists($class);

        if ($exists(self::GUZZLE_CLIENT) && $exists(self::GUZZLE_FACTORY)) {
            $client = self::instantiate(self::GUZZLE_CLIENT, [
                'timeout' => $timeoutSeconds,
                'connect_timeout' => min(10, $timeoutSeconds),
                'http_errors' => false,
            ]);
            $factory = self::instantiate(self::GUZZLE_FACTORY);

            return self::fromObjects($client, $factory, $factory);
        }

        if ($exists(self::SYMFONY_PSR18) && $exists(self::SYMFONY_CLIENT)) {
            $create = [self::SYMFONY_CLIENT, 'create'];
            if (!\is_callable($create)) {
                throw new HttpClientNotFoundException('Unsupported symfony/http-client version. ' . self::INSTALL_HINT);
            }
            $client = self::instantiate(self::SYMFONY_PSR18, $create(['timeout' => $timeoutSeconds, 'max_duration' => $timeoutSeconds]));

            return self::fromObjects($client, $client, $client);
        }

        throw new HttpClientNotFoundException('No PSR-18 HTTP client found. ' . self::INSTALL_HINT);
    }

    /**
     * @param (\Closure(string): bool)|null $classExists
     *
     * @throws HttpClientNotFoundException
     */
    private static function detectFactory(?\Closure $classExists): RequestFactoryInterface&StreamFactoryInterface
    {
        $exists = $classExists ?? static fn(string $class): bool => class_exists($class);

        foreach ([self::GUZZLE_FACTORY, self::NYHOLM_FACTORY] as $class) {
            if ($exists($class)) {
                $factory = self::instantiate($class);
                if ($factory instanceof RequestFactoryInterface && $factory instanceof StreamFactoryInterface) {
                    return $factory;
                }
            }
        }

        throw new HttpClientNotFoundException('No PSR-17 factories found. ' . self::INSTALL_HINT);
    }

    /**
     * Optional dependencies are instantiated by name so this class loads without them.
     */
    private static function instantiate(string $class, mixed ...$arguments): object
    {
        return new $class(...$arguments);
    }

    private static function fromObjects(mixed $client, mixed $requestFactory, mixed $streamFactory): self
    {
        if (!$client instanceof ClientInterface
            || !$requestFactory instanceof RequestFactoryInterface
            || !$streamFactory instanceof StreamFactoryInterface) {
            throw new HttpClientNotFoundException('The installed HTTP client does not implement PSR-18/PSR-17. ' . self::INSTALL_HINT);
        }

        return new self($client, $requestFactory, $streamFactory);
    }
}
