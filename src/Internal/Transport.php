<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Internal;

use B4x\Edoreczenia\Auth\AccessTokenProvider;
use B4x\Edoreczenia\Exception\ApiException;
use B4x\Edoreczenia\Exception\AuthenticationException;
use B4x\Edoreczenia\Exception\TransportException;
use B4x\Edoreczenia\Http\RetryPolicy;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * Executes authenticated JSON calls against an e-Doręczenia API.
 *
 * Logging policy: only operation name, method, scheme+host+path, status, attempt,
 * duration, error codes and the server event id are logged. Headers, query strings,
 * request and response bodies are never logged.
 *
 * @internal
 */
final class Transport
{
    public const USER_AGENT = 'b44x-edoreczenia-php';

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /**
     * @param (\Closure(int): void)|null $sleep receives milliseconds; injectable for tests
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly AccessTokenProvider $tokenProvider,
        private readonly RetryPolicy $retryPolicy,
        private readonly LoggerInterface $logger,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    /**
     * @param array<mixed> $payload
     * @param bool         $safeToRetry true only for operations without side effects
     * @param list<string> $maskedSegments values replaced by "{masked}" in logged paths (e.g. the mailbox address)
     *
     * @return array<mixed> decoded JSON body of a 2xx response
     */
    public function postJson(string $uri, array $payload, string $operation, bool $safeToRetry, array $maskedSegments = []): array
    {
        return $this->send('POST', $uri, Json::encode($payload), $operation, $safeToRetry, $maskedSegments);
    }

    /**
     * @param array<string, string|int|bool> $query          null-free query parameters; booleans are sent as "true"/"false"
     * @param bool                           $safeToRetry    true only for operations without side effects
     * @param list<string>                   $maskedSegments values replaced by "{masked}" in logged paths
     *
     * @return array<mixed> decoded JSON body of a 2xx response
     */
    public function getJson(string $uri, array $query, string $operation, bool $safeToRetry, array $maskedSegments = []): array
    {
        return $this->send('GET', self::withQuery($uri, $query), null, $operation, $safeToRetry, $maskedSegments);
    }

    /**
     * Binary GET: returns the successful response without reading its body, so large files can be
     * streamed by the caller. Error responses are mapped exactly like JSON calls.
     *
     * @param array<string, string|int|bool> $query
     * @param list<string>                   $maskedSegments
     */
    public function getStream(string $uri, array $query, string $operation, bool $safeToRetry, array $maskedSegments = []): ResponseInterface
    {
        return $this->exchange('GET', self::withQuery($uri, $query), null, $operation, $safeToRetry, $maskedSegments, '*/*');
    }

    /**
     * Like {@see getJson()}, but also returns the 2xx status code, for operations whose
     * 200 and 202 bodies have different shapes.
     *
     * @param array<string, string|int|bool> $query
     * @param list<string>                   $maskedSegments
     *
     * @return array{int, array<mixed>} status code and decoded JSON body
     */
    public function getJsonWithStatus(string $uri, array $query, string $operation, bool $safeToRetry, array $maskedSegments = []): array
    {
        $response = $this->exchange('GET', self::withQuery($uri, $query), null, $operation, $safeToRetry, $maskedSegments, 'application/json');

        return [$response->getStatusCode(), Json::decodeResponse($response, $operation)];
    }

    /**
     * Binary POST: sends a JSON body and returns the successful response without reading its body,
     * like {@see getStream()}.
     *
     * @param array<mixed> $payload
     * @param list<string> $maskedSegments
     */
    public function postForStream(string $uri, array $payload, string $operation, bool $safeToRetry, array $maskedSegments = []): ResponseInterface
    {
        return $this->exchange('POST', $uri, Json::encode($payload), $operation, $safeToRetry, $maskedSegments, '*/*');
    }

    /**
     * @param array<mixed> $payload
     * @param bool         $safeToRetry true only for operations without side effects
     * @param list<string> $maskedSegments
     *
     * @return array<mixed> decoded JSON body of a 2xx response
     */
    public function putJson(string $uri, array $payload, string $operation, bool $safeToRetry, array $maskedSegments = []): array
    {
        return $this->send('PUT', $uri, Json::encode($payload), $operation, $safeToRetry, $maskedSegments);
    }

    /**
     * @param bool         $safeToRetry true only for operations without side effects
     * @param list<string> $maskedSegments
     *
     * @return array<mixed> decoded JSON body of a 2xx response
     */
    public function deleteJson(string $uri, string $operation, bool $safeToRetry, array $maskedSegments = []): array
    {
        return $this->send('DELETE', $uri, null, $operation, $safeToRetry, $maskedSegments);
    }

    /**
     * POST of a streamed body (e.g. multipart/form-data) that is handed to the HTTP client as-is.
     *
     * Never retried: a partially consumed stream cannot be replayed reliably.
     *
     * @param string       $contentType full Content-Type header, including the multipart boundary
     * @param list<string> $maskedSegments
     *
     * @return array<mixed> decoded JSON body of a 2xx response
     */
    public function postStreamBody(string $uri, StreamInterface $body, string $contentType, string $operation, array $maskedSegments = []): array
    {
        $response = $this->exchange('POST', $uri, $body, $operation, false, $maskedSegments, 'application/json', $contentType);

        return Json::decodeResponse($response, $operation);
    }

    /**
     * @param list<string> $maskedSegments
     *
     * @return array<mixed>
     */
    private function send(string $method, string $uri, ?string $body, string $operation, bool $safeToRetry, array $maskedSegments): array
    {
        $response = $this->exchange($method, $uri, $body, $operation, $safeToRetry, $maskedSegments, 'application/json');

        return Json::decodeResponse($response, $operation);
    }

    /**
     * @param array<string, string|int|bool> $query
     */
    private static function withQuery(string $uri, array $query): string
    {
        if ($query === []) {
            return $uri;
        }

        $pairs = array_map(static fn(string|int|bool $value): string => \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, $query);

        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($pairs, '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * Sends the request with authentication, retries and logging; returns a 2xx response or throws.
     *
     * @param list<string> $maskedSegments
     */
    private function exchange(string $method, string $uri, StreamInterface|string|null $body, string $operation, bool $safeToRetry, array $maskedSegments, string $accept, string $contentType = 'application/json'): ResponseInterface
    {
        $maxRetries = $safeToRetry ? $this->retryPolicy->maxRetries : 0;
        $logUri = self::redactUri($uri, $maskedSegments);

        for ($attempt = 1; ; ++$attempt) {
            $token = $this->tokenProvider->getToken();
            $request = $this->requestFactory->createRequest($method, $uri)
                ->withHeader('Authorization', 'Bearer ' . $token->value())
                ->withHeader('Accept', $accept)
                ->withHeader('User-Agent', self::USER_AGENT);
            if ($body !== null) {
                $request = $request
                    ->withHeader('Content-Type', $contentType)
                    ->withBody(\is_string($body) ? $this->streamFactory->createStream($body) : $body);
            }

            $started = hrtime(true);
            try {
                $response = $this->httpClient->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                $this->log('warning', $operation, $method, $logUri, $attempt, $started, null, ['exception' => $e::class]);
                if ($e instanceof NetworkExceptionInterface && $attempt <= $maxRetries) {
                    ($this->sleep)($this->retryPolicy->delayMs($attempt));
                    continue;
                }

                throw TransportException::fromClientException($e, $operation);
            }

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                $this->log('debug', $operation, $method, $logUri, $attempt, $started, $status);

                return $response;
            }

            $exception = ErrorResponse::toException($response, $operation);
            $this->log('warning', $operation, $method, $logUri, $attempt, $started, $status, $this->errorContext($exception));

            if ($exception instanceof AuthenticationException) {
                // The token was rejected; drop it so the next call obtains a fresh one.
                $this->tokenProvider->invalidate();

                throw $exception;
            }

            $delay = $this->retryDelay($response, $attempt, $maxRetries);
            if ($delay === null) {
                throw $exception;
            }

            ($this->sleep)($delay);
        }
    }

    private function retryDelay(ResponseInterface $response, int $attempt, int $maxRetries): ?int
    {
        if ($attempt > $maxRetries || !$this->retryPolicy->isRetryableStatus($response->getStatusCode())) {
            return null;
        }

        $retryAfter = trim($response->getHeaderLine('Retry-After'));
        if ($retryAfter !== '' && ctype_digit($retryAfter)) {
            $milliseconds = (int) $retryAfter * 1000;

            // The server asks for a longer pause than the policy allows: give up instead of hammering it.
            return $milliseconds <= $this->retryPolicy->maxDelayMs ? $milliseconds : null;
        }

        return $this->retryPolicy->delayMs($attempt);
    }

    /**
     * @return array<string, mixed>
     */
    private function errorContext(ApiException $exception): array
    {
        return [
            'error_codes' => array_map(static fn($error): string => $error->code, $exception->getErrors()),
            'event_id' => $exception->getEventId(),
        ];
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function log(string $level, string $operation, string $method, string $endpoint, int $attempt, int|float $started, ?int $status, array $extra = []): void
    {
        $this->logger->log($level, 'e-Doręczenia API call', [
            'operation' => $operation,
            'method' => $method,
            'endpoint' => $endpoint,
            'status' => $status,
            'attempt' => $attempt,
            'duration_ms' => (int) round((hrtime(true) - $started) / 1e6),
        ] + $extra);
    }

    /**
     * Keeps scheme, host and path; drops user info, query and fragment, and replaces
     * each of $maskedSegments (raw or URL-encoded) in the path with "{masked}".
     *
     * @param list<string> $maskedSegments
     */
    public static function redactUri(string $uri, array $maskedSegments = []): string
    {
        $parts = parse_url($uri);
        if (!\is_array($parts) || !isset($parts['host'])) {
            return '[unparseable uri]';
        }

        $path = $parts['path'] ?? '';
        foreach ($maskedSegments as $segment) {
            if ($segment !== '') {
                $path = str_replace([$segment, rawurlencode($segment), PathSegment::encode($segment)], '{masked}', $path);
            }
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path;
    }
}
