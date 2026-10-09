<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\Auth;

use B4x\Edoreczenia\Auth\AccessToken;
use B4x\Edoreczenia\Auth\StaticTokenProvider;
use B4x\Edoreczenia\Exception\AuthenticationException;
use B4x\Edoreczenia\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class StaticTokenProviderTest extends TestCase
{
    public function testServesTokenUntilExpiryOrInvalidation(): void
    {
        $clock = new FrozenClock();
        $provider = new StaticTokenProvider(new AccessToken('t', $clock->now()->modify('+60 seconds')), $clock);

        self::assertSame('t', $provider->getToken()->value());

        $clock->advance(60);
        $this->expectException(AuthenticationException::class);
        $provider->getToken();
    }

    public function testInvalidatedTokenIsNotServed(): void
    {
        $clock = new FrozenClock();
        $provider = new StaticTokenProvider(new AccessToken('t', $clock->now()->modify('+60 seconds')), $clock);
        $provider->invalidate();

        $this->expectException(AuthenticationException::class);
        $provider->getToken();
    }
}
