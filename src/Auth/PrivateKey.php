<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Auth;

use B4x\Edoreczenia\Exception\InvalidArgumentException;

/**
 * RSA private key of the system registered in the mailbox permission module.
 *
 * The official instruction ("Załącznik 1c", section 3.1) generates the key with
 * `openssl genrsa 2048`; the documented client assertion is signed with RS256.
 * Other key types are rejected until their support is confirmed.
 *
 * The key material never appears in var_dump()/print_r() output and the object
 * refuses to be serialized, so it cannot leak into caches, queues or logs by accident.
 */
final class PrivateKey
{
    public const MIN_RSA_BITS = 2048;

    private function __construct(
        private readonly \OpenSSLAsymmetricKey $key,
        private readonly int $bits,
    ) {}

    /**
     * @param string      $pem        PEM-encoded private key (PKCS#1 or PKCS#8)
     * @param string|null $passphrase passphrase of an encrypted PEM key
     */
    public static function fromPem(#[\SensitiveParameter] string $pem, #[\SensitiveParameter] ?string $passphrase = null): self
    {
        $key = openssl_pkey_get_private($pem, $passphrase);
        self::clearOpenSslErrors();

        if ($key === false) {
            throw new InvalidArgumentException('Unable to load the private key: invalid PEM data or wrong passphrase.');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new InvalidArgumentException('Only RSA private keys are supported (the documented assertion algorithm is RS256).');
        }

        $bits = \is_int($details['bits'] ?? null) ? $details['bits'] : 0;
        if ($bits < self::MIN_RSA_BITS) {
            throw new InvalidArgumentException(\sprintf('RSA key is too short: %d bits, at least %d required.', $bits, self::MIN_RSA_BITS));
        }

        return new self($key, $bits);
    }

    public static function fromFile(string $path, #[\SensitiveParameter] ?string $passphrase = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException(\sprintf('Private key file "%s" does not exist or is not readable.', $path));
        }

        $pem = file_get_contents($path);
        if ($pem === false) {
            throw new InvalidArgumentException(\sprintf('Unable to read private key file "%s".', $path));
        }

        return self::fromPem($pem, $passphrase);
    }

    public function bits(): int
    {
        return $this->bits;
    }

    /**
     * RSASSA-PKCS1-v1_5 with SHA-256 (JWS "RS256").
     *
     * @internal
     */
    public function signRs256(string $data): string
    {
        $signature = '';
        $ok = openssl_sign($data, $signature, $this->key, OPENSSL_ALGO_SHA256);
        self::clearOpenSslErrors();

        if (!$ok || !\is_string($signature) || $signature === '') {
            throw new InvalidArgumentException('Signing the client assertion failed.');
        }

        return $signature;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['type' => 'RSA', 'bits' => $this->bits, 'key' => '[redacted]'];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new \LogicException('PrivateKey must not be serialized.');
    }

    /**
     * @param array<mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('PrivateKey must not be unserialized.');
    }

    private static function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // drain the OpenSSL error queue so details do not leak into later calls
        }
    }
}
