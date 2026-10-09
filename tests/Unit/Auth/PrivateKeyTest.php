<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\Auth;

use B4x\Edoreczenia\Auth\PrivateKey;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;

final class PrivateKeyTest extends TestCase
{
    public function testLoadsRsaKeyAndSignsVerifiably(): void
    {
        $keys = TestKeys::rsa();
        $key = PrivateKey::fromPem($keys['private']);

        $signature = $key->signRs256('payload');

        self::assertSame(2048, $key->bits());
        self::assertSame(1, openssl_verify('payload', $signature, $keys['public'], \OPENSSL_ALGO_SHA256));
    }

    public function testLoadsEncryptedKeyWithPassphrase(): void
    {
        $keys = TestKeys::rsa(2048, 'secret-pass');

        self::assertSame(2048, PrivateKey::fromPem($keys['private'], 'secret-pass')->bits());
    }

    public function testWrongPassphraseIsRejectedWithoutLeakingKeyMaterial(): void
    {
        $keys = TestKeys::rsa(2048, 'secret-pass');

        try {
            PrivateKey::fromPem($keys['private'], 'wrong');
            self::fail('Expected exception');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
            self::assertStringNotContainsString('secret-pass', $e->getMessage());
        }

        self::assertFalse(openssl_error_string(), 'OpenSSL error queue must be drained');
    }

    public function testRejectsNonRsaKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only RSA');

        PrivateKey::fromPem(TestKeys::ec()['private']);
    }

    public function testRejectsShortRsaKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('too short');

        PrivateKey::fromPem(TestKeys::rsa(1024)['private']);
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PrivateKey::fromPem('not a key');
    }

    public function testMissingFileIsReported(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        PrivateKey::fromFile('/nonexistent/key.pem');
    }

    public function testLoadsFromFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'edor');
        self::assertIsString($path);
        file_put_contents($path, TestKeys::rsa()['private']);

        try {
            self::assertSame(2048, PrivateKey::fromFile($path)->bits());
        } finally {
            unlink($path);
        }
    }

    public function testKeyMaterialIsNotExposedByDumpsOrSerialization(): void
    {
        $keys = TestKeys::rsa();
        $key = PrivateKey::fromPem($keys['private']);

        $dump = print_r($key, true);
        self::assertStringContainsString('[redacted]', $dump);
        self::assertStringNotContainsString('PRIVATE KEY', $dump);

        $this->expectException(\LogicException::class);
        serialize($key);
    }
}
