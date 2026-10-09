<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Support;

use B4x\Edoreczenia\Auth\AccessToken;
use B4x\Edoreczenia\Auth\AccessTokenProvider;

final class SpyTokenProvider implements AccessTokenProvider
{
    public int $issued = 0;
    public int $invalidations = 0;

    public function __construct(private readonly string $prefix = 'token-') {}

    public function getToken(): AccessToken
    {
        return new AccessToken($this->prefix . (++$this->issued), new \DateTimeImmutable('+5 minutes'));
    }

    public function invalidate(): void
    {
        ++$this->invalidations;
    }
}
