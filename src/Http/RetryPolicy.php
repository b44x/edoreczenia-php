<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Http;

use B4x\Edoreczenia\Exception\InvalidArgumentException;

/**
 * Bounded retry with exponential backoff and jitter.
 *
 * Retries are applied only to operations the SDK marks as safe to repeat (currently the
 * read-only Search Engine API queries) and only for network failures and HTTP 429/500/502/503/504.
 * Token requests and any operation with side effects are never retried automatically.
 *
 * The default is {@see none()}: retrying blocks the calling process, so it is opt-in.
 */
final readonly class RetryPolicy
{
    private const RETRYABLE_STATUS = [429, 500, 502, 503, 504];

    public function __construct(
        public int $maxRetries = 0,
        public int $baseDelayMs = 200,
        public int $maxDelayMs = 2000,
    ) {
        if ($maxRetries < 0 || $maxRetries > 5) {
            throw new InvalidArgumentException('maxRetries must be between 0 and 5.');
        }
        if ($baseDelayMs < 0 || $maxDelayMs < $baseDelayMs || $maxDelayMs > 30_000) {
            throw new InvalidArgumentException('Retry delays must satisfy 0 <= baseDelayMs <= maxDelayMs <= 30000.');
        }
    }

    public static function none(): self
    {
        return new self(0);
    }

    public static function exponential(int $maxRetries = 2, int $baseDelayMs = 200, int $maxDelayMs = 2000): self
    {
        return new self($maxRetries, $baseDelayMs, $maxDelayMs);
    }

    /**
     * @internal
     */
    public function isRetryableStatus(int $statusCode): bool
    {
        return \in_array($statusCode, self::RETRYABLE_STATUS, true);
    }

    /**
     * Delay before retry number $retry (1-based), "equal jitter": between 50% and 100% of the capped exponential delay.
     *
     * @internal
     */
    public function delayMs(int $retry): int
    {
        $ceiling = min($this->maxDelayMs, $this->baseDelayMs * (2 ** max(0, $retry - 1)));
        $half = intdiv($ceiling, 2);

        return $half + random_int(0, $ceiling - $half);
    }
}
