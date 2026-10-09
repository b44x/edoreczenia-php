<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Auth;

/**
 * Builds the RFC 7523 client assertion (a JWS signed with RS256) used to obtain
 * an access token with the client_credentials grant.
 *
 * Claims follow the official instruction, section 4.1:
 * iss = sub = "{ADE}.SYSTEM.{system}", aud = realm URL, iat = nbf = now,
 * exp = now + lifetime, jti = random UUIDv4.
 *
 * @internal
 */
final class ClientAssertion
{
    public const DEFAULT_LIFETIME_SECONDS = 300;

    public static function create(
        SystemCredentials $credentials,
        string $audience,
        \DateTimeImmutable $now,
        int $lifetimeSeconds = self::DEFAULT_LIFETIME_SECONDS,
    ): string {
        $issuedAt = $now->getTimestamp();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $credentials->clientId(),
            'sub' => $credentials->clientId(),
            'aud' => $audience,
            'iat' => $issuedAt,
            'nbf' => $issuedAt,
            'exp' => $issuedAt + $lifetimeSeconds,
            'jti' => self::uuidV4(),
        ];

        $signingInput = self::base64Url(self::json($header)) . '.' . self::base64Url(self::json($claims));

        return $signingInput . '.' . self::base64Url($credentials->privateKey->signRs256($signingInput));
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return \sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    /**
     * @param array<string, int|string> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
