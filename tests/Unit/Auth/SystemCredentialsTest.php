<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\Auth;

use B4x\Edoreczenia\Auth\PrivateKey;
use B4x\Edoreczenia\Auth\SystemCredentials;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\Tests\Support\TestKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SystemCredentialsTest extends TestCase
{
    public function testClientIdFollowsDocumentedPattern(): void
    {
        $credentials = new SystemCredentials('AE:PL-97075-47631-STVJH-19', 'RAMER', PrivateKey::fromPem(TestKeys::rsa()['private']));

        self::assertSame('AE:PL-97075-47631-STVJH-19.SYSTEM.RAMER', $credentials->clientId());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'empty address' => ['', 'EZD'];
        yield 'empty system' => ['AE:PL-1', ''];
        yield 'whitespace in address' => ['AE:PL 1', 'EZD'];
        yield 'whitespace in system' => ['AE:PL-1', 'EZD 1'];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValues(string $address, string $system): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SystemCredentials($address, $system, PrivateKey::fromPem(TestKeys::rsa()['private']));
    }
}
