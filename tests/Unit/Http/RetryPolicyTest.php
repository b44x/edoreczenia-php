<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\Http;

use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\Http\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function testDefaultsToNoRetries(): void
    {
        self::assertSame(0, RetryPolicy::none()->maxRetries);
        self::assertSame(0, (new RetryPolicy())->maxRetries);
    }

    public function testDelaysGrowExponentiallyWithinBoundsAndCap(): void
    {
        $policy = RetryPolicy::exponential(3, 100, 300);

        for ($i = 0; $i < 50; ++$i) {
            self::assertThat($policy->delayMs(1), self::logicalAnd(self::greaterThanOrEqual(50), self::lessThanOrEqual(100)));
            self::assertThat($policy->delayMs(2), self::logicalAnd(self::greaterThanOrEqual(100), self::lessThanOrEqual(200)));
            self::assertThat($policy->delayMs(3), self::logicalAnd(self::greaterThanOrEqual(150), self::lessThanOrEqual(300)));
        }
    }

    public function testOnlyTransientStatusesAreRetryable(): void
    {
        $policy = RetryPolicy::exponential();

        foreach ([429, 500, 502, 503, 504] as $status) {
            self::assertTrue($policy->isRetryableStatus($status), (string) $status);
        }
        foreach ([400, 401, 403, 404, 409, 412, 501] as $status) {
            self::assertFalse($policy->isRetryableStatus($status), (string) $status);
        }
    }

    public function testRejectsUnreasonableConfiguration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RetryPolicy(10);
    }
}
