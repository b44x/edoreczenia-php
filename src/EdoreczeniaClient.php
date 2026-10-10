<?php

declare(strict_types=1);

namespace B4x\Edoreczenia;

use B4x\Edoreczenia\Auth\AccessTokenProvider;
use B4x\Edoreczenia\Auth\SignedJwtTokenProvider;
use B4x\Edoreczenia\Auth\SystemCredentials;
use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\Exception\HttpClientNotFoundException;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\Http\HttpStack;
use B4x\Edoreczenia\Http\RetryPolicy;
use B4x\Edoreczenia\Internal\Transport;
use B4x\Edoreczenia\SearchEngine\V4\SearchEngineClient;
use B4x\Edoreczenia\UserAgent\V3\UserAgentClient;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Entry point of the SDK, bound to one environment and one mailbox (ADE).
 *
 * The HTTP client is injected (PSR-18); {@see withSystemCredentials()} can also detect an installed one. Timeouts, proxies and TLS settings belong to that
 * client; the SDK never alters them and never disables certificate verification.
 */
final class EdoreczeniaClient
{
    private readonly Transport $transport;
    private ?SearchEngineClient $searchEngine = null;
    private ?UserAgentClient $userAgent = null;

    /**
     * Low-level constructor for a custom {@see AccessTokenProvider}.
     * Most applications should use {@see withSystemCredentials()}.
     *
     * @param string $deliveryAddress the mailbox address (ADE) the token was issued for
     */
    public function __construct(
        private readonly Environment $environment,
        private readonly string $deliveryAddress,
        AccessTokenProvider $tokenProvider,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        ?LoggerInterface $logger = null,
        ?RetryPolicy $retryPolicy = null,
    ) {
        if ($deliveryAddress === '' || preg_match('/\s/u', $deliveryAddress) === 1) {
            throw new InvalidArgumentException('Delivery address must be a non-empty string without whitespace.');
        }

        $this->transport = new Transport(
            $httpClient,
            $requestFactory,
            $streamFactory,
            $tokenProvider,
            $retryPolicy ?? RetryPolicy::none(),
            $logger ?? new NullLogger(),
        );
    }

    /**
     * Client authenticating as a system registered in the mailbox (signed JWT, RFC 7523).
     *
     * The HTTP client and factories are optional. When omitted, an installed Guzzle or Symfony
     * HttpClient is used with a 30 s total and 10 s connect timeout. Pass your own client to
     * control timeouts, proxies or TLS.
     *
     * @param CacheInterface|null $tokenCache optional PSR-16 cache to share the access token between processes
     *
     * @throws HttpClientNotFoundException when no client is passed and none is installed
     */
    public static function withSystemCredentials(
        Environment $environment,
        SystemCredentials $credentials,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        ?RetryPolicy $retryPolicy = null,
        ?CacheInterface $tokenCache = null,
        ?ClockInterface $clock = null,
    ): self {
        $http = HttpStack::resolve($httpClient, $requestFactory, $streamFactory);
        $httpClient = $http->client;
        $requestFactory = $http->requestFactory;
        $streamFactory = $http->streamFactory;

        $tokenProvider = new SignedJwtTokenProvider(
            $credentials,
            $environment,
            $httpClient,
            $requestFactory,
            $streamFactory,
            $clock,
            $tokenCache,
            $logger,
        );

        return new self($environment, $credentials->deliveryAddress, $tokenProvider, $httpClient, $requestFactory, $streamFactory, $logger, $retryPolicy);
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    public function deliveryAddress(): string
    {
        return $this->deliveryAddress;
    }

    /**
     * Search Engine API v4.
     */
    public function searchEngine(): SearchEngineClient
    {
        return $this->searchEngine ??= new SearchEngineClient($this->transport, $this->environment->searchEngineV4BaseUri, $this->deliveryAddress);
    }

    /**
     * User Agent API v3 – read-only operations of the configured mailbox.
     *
     * Implemented from the official contract; not yet verified against a live environment.
     */
    public function userAgent(): UserAgentClient
    {
        return $this->userAgent ??= new UserAgentClient($this->transport, $this->environment->userAgentV3BaseUri, $this->deliveryAddress);
    }
}
