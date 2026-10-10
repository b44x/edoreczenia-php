<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Cli;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * PSR-18 client with the PSR-17 factories used by the doctor command.
 *
 * @internal
 */
final readonly class HttpStack
{
    private const GUZZLE_CLIENT = 'GuzzleHttp\Client';
    private const GUZZLE_FACTORY = 'GuzzleHttp\Psr7\HttpFactory';
    private const SYMFONY_PSR18 = 'Symfony\Component\HttpClient\Psr18Client';
    private const SYMFONY_CLIENT = 'Symfony\Component\HttpClient\HttpClient';

    public function __construct(
        public ClientInterface $client,
        public RequestFactoryInterface $requestFactory,
        public StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * Builds a real HTTP client from whatever the application has installed: Guzzle first,
     * then Symfony HttpClient. Neither is a dependency of this package, so both are looked
     * up by name at runtime.
     *
     * @throws \RuntimeException when no supported client is installed
     */
    public static function detect(int $timeoutSeconds): self
    {
        if (class_exists(self::GUZZLE_CLIENT) && class_exists(self::GUZZLE_FACTORY)) {
            $clientClass = self::GUZZLE_CLIENT;
            $factoryClass = self::GUZZLE_FACTORY;
            $client = new $clientClass([
                'timeout' => $timeoutSeconds,
                'connect_timeout' => min(10, $timeoutSeconds),
                'http_errors' => false,
            ]);

            return self::fromObjects($client, new $factoryClass(), new $factoryClass());
        }

        if (class_exists(self::SYMFONY_PSR18) && class_exists(self::SYMFONY_CLIENT)) {
            $psr18Class = self::SYMFONY_PSR18;
            $create = [self::SYMFONY_CLIENT, 'create'];
            if (!\is_callable($create)) {
                throw new \RuntimeException('Unsupported symfony/http-client version.');
            }
            $client = new $psr18Class($create(['timeout' => $timeoutSeconds]));

            return self::fromObjects($client, $client, $client);
        }

        throw new \RuntimeException('No PSR-18 HTTP client found. Install one, e.g.: composer require guzzlehttp/guzzle');
    }

    private static function fromObjects(mixed $client, mixed $requestFactory, mixed $streamFactory): self
    {
        if (!$client instanceof ClientInterface
            || !$requestFactory instanceof RequestFactoryInterface
            || !$streamFactory instanceof StreamFactoryInterface) {
            throw new \RuntimeException('The installed HTTP client does not implement PSR-18/PSR-17.');
        }

        return new self($client, $requestFactory, $streamFactory);
    }
}
