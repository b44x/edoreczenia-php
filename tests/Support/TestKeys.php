<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Support;

/**
 * Keys generated at runtime; no key material is committed to the repository.
 */
final class TestKeys
{
    /** @var array<string, array{private: string, public: string}> */
    private static array $cache = [];

    /**
     * @return array{private: string, public: string}
     */
    public static function rsa(int $bits = 2048, ?string $passphrase = null): array
    {
        return self::generate('rsa' . $bits . ($passphrase ?? ''), ['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits], $passphrase);
    }

    /**
     * @return array{private: string, public: string}
     */
    public static function ec(): array
    {
        return self::generate('ec', ['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'], null);
    }

    /**
     * @param array<string, int|string> $options
     *
     * @return array{private: string, public: string}
     */
    private static function generate(string $id, array $options, ?string $passphrase): array
    {
        if (isset(self::$cache[$id])) {
            return self::$cache[$id];
        }

        $key = openssl_pkey_new($options);
        if ($key === false) {
            throw new \RuntimeException('Unable to generate a test key.');
        }

        $private = '';
        openssl_pkey_export($key, $private, $passphrase);
        $details = openssl_pkey_get_details($key);
        if (!\is_string($private) || $details === false || !\is_string($details['key'] ?? null)) {
            throw new \RuntimeException('Unable to export the test key.');
        }

        return self::$cache[$id] = ['private' => $private, 'public' => $details['key']];
    }
}
