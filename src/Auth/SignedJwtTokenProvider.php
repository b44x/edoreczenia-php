<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Auth;

use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\Exception\TransportException;
use B4x\Edoreczenia\Exception\UnexpectedResponseException;
use B4x\Edoreczenia\Internal\ErrorResponse;
use B4x\Edoreczenia\Internal\Json;
use B4x\Edoreczenia\Internal\Payload;
use B4x\Edoreczenia\Internal\SystemClock;
use B4x\Edoreczenia\Internal\Transport;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as CacheInvalidArgumentException;

/**
 * Obtains access tokens with the documented system-to-system flow:
 * OIDC client_credentials grant authenticated by a signed JWT client assertion (RFC 7523),
 * POSTed to the Keycloak token endpoint with `login_hint=ADE.{address}`.
 *
 * Tokens are reused until shortly before they expire, as the official instruction requires
 * ("do not request a new token while the previous one is still valid", section 4.3/4.12).
 * The documented lifetime is 300 s and no refresh token is issued (refresh_expires_in = 0),
 * so an expired token is replaced by running the same flow again.
 *
 * Pass a PSR-16 cache to share tokens between PHP processes (e.g. PHP-FPM workers). The cache
 * then holds a live bearer credential: use a private store that only this application can read.
 *
 * Token requests are never retried automatically: every attempt needs a fresh assertion (new jti),
 * and failures here are usually configuration errors that a retry cannot fix.
 */
final class SignedJwtTokenProvider implements AccessTokenProvider
{
    private const OPERATION = 'token request';
    private const MAX_LEEWAY_SECONDS = 30;
    private const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    private readonly ClockInterface $clock;
    private readonly LoggerInterface $logger;
    private ?AccessToken $token = null;
    private int $leewaySeconds = self::MAX_LEEWAY_SECONDS;

    public function __construct(
        private readonly SystemCredentials $credentials,
        private readonly Environment $environment,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        ?ClockInterface $clock = null,
        private readonly ?CacheInterface $cache = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->logger = $logger ?? new NullLogger();
    }

    public function getToken(): AccessToken
    {
        $now = $this->clock->now();

        if ($this->token !== null && $this->token->isUsableAt($now, $this->leewaySeconds)) {
            return $this->token;
        }

        $cached = $this->readCache();
        if ($cached !== null && $cached->isUsableAt($now, self::MAX_LEEWAY_SECONDS)) {
            return $this->token = $cached;
        }

        return $this->token = $this->fetchToken($now);
    }

    public function invalidate(): void
    {
        $this->token = null;

        if ($this->cache !== null) {
            try {
                $this->cache->delete($this->cacheKey());
            } catch (CacheInvalidArgumentException) {
                // key is generated internally and always valid
            }
        }
    }

    private function fetchToken(\DateTimeImmutable $now): AccessToken
    {
        $assertion = ClientAssertion::create($this->credentials, $this->environment->assertionAudience, $now);
        $uri = $this->environment->tokenEndpoint
            . (str_contains($this->environment->tokenEndpoint, '?') ? '&' : '?')
            . 'login_hint=' . rawurlencode('ADE.' . $this->credentials->deliveryAddress);

        $form = http_build_query([
            'grant_type' => 'client_credentials',
            'client_assertion_type' => self::ASSERTION_TYPE,
            'client_assertion' => $assertion,
        ], '', '&', \PHP_QUERY_RFC1738);

        $request = $this->requestFactory->createRequest('POST', $uri)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', Transport::USER_AGENT)
            ->withBody($this->streamFactory->createStream($form));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->logger->warning('e-Doręczenia token request failed', [
                'endpoint' => Transport::redactUri($uri),
                'exception' => $e::class,
            ]);

            throw TransportException::fromClientException($e, self::OPERATION);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $exception = ErrorResponse::toException($response, self::OPERATION);
            $this->logger->warning('e-Doręczenia token request rejected', [
                'endpoint' => Transport::redactUri($uri),
                'status' => $status,
                'error_codes' => array_map(static fn($e): string => $e->code, $exception->getErrors()),
            ]);

            throw $exception;
        }

        $payload = Payload::root(Json::decodeResponse($response, self::OPERATION));
        $value = $payload->string('access_token');
        $expiresIn = $payload->optionalInt('expires_in');
        $tokenType = $payload->optionalString('token_type');

        if ($expiresIn === null || $expiresIn <= 0) {
            throw new UnexpectedResponseException('Token response has no positive "expires_in".', $status);
        }
        if ($tokenType !== null && strcasecmp($tokenType, 'Bearer') !== 0) {
            throw new UnexpectedResponseException(\sprintf('Unsupported token type "%s" (Bearer expected).', $tokenType), $status);
        }

        $token = new AccessToken($value, $now->modify(\sprintf('+%d seconds', $expiresIn)));
        $this->leewaySeconds = min(self::MAX_LEEWAY_SECONDS, intdiv($expiresIn, 2));
        $this->writeCache($token, $expiresIn);

        $this->logger->info('e-Doręczenia access token obtained', [
            'environment' => $this->environment->name,
            'expires_in' => $expiresIn,
        ]);

        return $token;
    }

    private function readCache(): ?AccessToken
    {
        if ($this->cache === null) {
            return null;
        }

        try {
            $entry = $this->cache->get($this->cacheKey());
        } catch (CacheInvalidArgumentException) {
            return null;
        }

        if (!\is_array($entry) || !\is_string($entry['token'] ?? null) || $entry['token'] === '' || !\is_int($entry['expires_at'] ?? null)) {
            return null;
        }

        return new AccessToken($entry['token'], (new \DateTimeImmutable())->setTimestamp($entry['expires_at']));
    }

    private function writeCache(AccessToken $token, int $expiresIn): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $this->cache->set(
                $this->cacheKey(),
                ['token' => $token->value(), 'expires_at' => $token->expiresAt->getTimestamp()],
                $expiresIn,
            );
        } catch (CacheInvalidArgumentException) {
            // key is generated internally and always valid
        }
    }

    private function cacheKey(): string
    {
        return 'b4x_edoreczenia_token_' . hash('sha256', $this->environment->tokenEndpoint . '|' . $this->credentials->clientId());
    }
}
