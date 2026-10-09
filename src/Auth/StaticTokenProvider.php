<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Auth;

use B4x\Edoreczenia\Exception\AuthenticationException;
use B4x\Edoreczenia\Internal\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * Serves a token obtained outside the SDK (useful for tests and external token brokers).
 *
 * Once invalidated, or once the token expires, every call fails with
 * {@see AuthenticationException}; no new token is fetched.
 */
final class StaticTokenProvider implements AccessTokenProvider
{
    private ?AccessToken $token;
    private readonly ClockInterface $clock;

    public function __construct(AccessToken $token, ?ClockInterface $clock = null)
    {
        $this->token = $token;
        $this->clock = $clock ?? new SystemClock();
    }

    public function getToken(): AccessToken
    {
        if ($this->token === null || !$this->token->isUsableAt($this->clock->now())) {
            throw new AuthenticationException('The static access token has expired or was invalidated.', 401);
        }

        return $this->token;
    }

    public function invalidate(): void
    {
        $this->token = null;
    }
}
