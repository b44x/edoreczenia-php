<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\Auth;

use B4x\Edoreczenia\Auth\ClientAssertion;
use B4x\Edoreczenia\Auth\PrivateKey;
use B4x\Edoreczenia\Auth\SystemCredentials;
use B4x\Edoreczenia\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;

final class ClientAssertionTest extends TestCase
{
    public function testBuildsDocumentedRs256Assertion(): void
    {
        $keys = TestKeys::rsa();
        $credentials = new SystemCredentials('AE:PL-12345-67890-ABCDE-12', 'EZD1', PrivateKey::fromPem($keys['private']));
        $now = new \DateTimeImmutable('2026-10-01 12:00:00 UTC');

        $jwt = ClientAssertion::create($credentials, 'https://int-ow.edoreczenia.gov.pl/auth/realms/EDOR', $now);

        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);
        [$header, $claims] = [self::decode($parts[0]), self::decode($parts[1])];

        self::assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $header);
        self::assertSame('AE:PL-12345-67890-ABCDE-12.SYSTEM.EZD1', $claims['iss']);
        self::assertSame($claims['iss'], $claims['sub']);
        self::assertSame('https://int-ow.edoreczenia.gov.pl/auth/realms/EDOR', $claims['aud']);
        self::assertSame($now->getTimestamp(), $claims['iat']);
        self::assertSame($now->getTimestamp(), $claims['nbf']);
        self::assertSame($now->getTimestamp() + 300, $claims['exp']);
        self::assertIsString($claims['jti']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $claims['jti']);

        $signature = base64_decode(strtr($parts[2], '-_', '+/'), true);
        self::assertIsString($signature);
        self::assertSame(1, openssl_verify($parts[0] . '.' . $parts[1], $signature, $keys['public'], \OPENSSL_ALGO_SHA256));
        self::assertStringNotContainsString('=', $jwt, 'base64url without padding');
    }

    public function testEveryAssertionHasAUniqueJti(): void
    {
        $credentials = new SystemCredentials('AE:PL-12345-67890-ABCDE-12', 'EZD1', PrivateKey::fromPem(TestKeys::rsa()['private']));
        $now = new \DateTimeImmutable();

        $a = self::decode(explode('.', ClientAssertion::create($credentials, 'https://example.test/realm', $now))[1]);
        $b = self::decode(explode('.', ClientAssertion::create($credentials, 'https://example.test/realm', $now))[1]);

        self::assertNotSame($a['jti'], $b['jti']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $segment): array
    {
        $json = base64_decode(strtr($segment, '-_', '+/'), true);
        self::assertIsString($json);
        $data = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
