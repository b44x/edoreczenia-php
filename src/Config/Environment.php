<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Config;

use B4x\Edoreczenia\Exception\InvalidArgumentException;

/**
 * Endpoints of one e-Doręczenia environment.
 *
 * There is deliberately no default environment: callers must choose production or
 * integration (INT) explicitly, so test code can never silently reach production
 * and vice versa.
 *
 * URLs come from the official integration instructions (INT: "Załącznik 1a" v1.97,
 * PROD: "Instrukcja integracji EZD ze środowiskiem PROD" v1.9).
 */
final readonly class Environment
{
    public string $tokenEndpoint;
    public string $assertionAudience;
    public string $searchEngineV4BaseUri;
    public string $userAgentV3BaseUri;

    /**
     * Use this constructor only for proxies or local mock servers; prefer
     * {@see production()} and {@see integration()}.
     *
     * Every URL must use HTTPS. Plain HTTP is accepted only for loopback hosts
     * (localhost, 127.0.0.1, [::1]) to allow local mock servers.
     *
     * @param string $name               human-readable label used in logs
     * @param string $tokenEndpoint      OIDC token endpoint of the authorization server
     * @param string $assertionAudience  "aud" claim of the signed client assertion (the realm URL)
     */
    public function __construct(
        public string $name,
        string $tokenEndpoint,
        string $assertionAudience,
        string $searchEngineV4BaseUri,
        string $userAgentV3BaseUri,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Environment name must not be empty.');
        }

        $this->tokenEndpoint = self::assertUrl($tokenEndpoint, 'tokenEndpoint');
        $this->assertionAudience = self::assertUrl($assertionAudience, 'assertionAudience');
        $this->searchEngineV4BaseUri = rtrim(self::assertUrl($searchEngineV4BaseUri, 'searchEngineV4BaseUri'), '/') . '/';
        $this->userAgentV3BaseUri = rtrim(self::assertUrl($userAgentV3BaseUri, 'userAgentV3BaseUri'), '/') . '/';
    }

    /**
     * Production (PROD) environment of the designated operator (Poczta Polska) and the central system.
     */
    public static function production(): self
    {
        return new self(
            'production',
            'https://ow.edoreczenia.gov.pl/auth/realms/EDOR/protocol/openid-connect/token',
            'https://ow.edoreczenia.gov.pl/auth/realms/EDOR',
            'https://ow.edoreczenia.gov.pl/api/se/v4/',
            'https://uaapi-ow.poczta-polska.pl/api/v3/',
        );
    }

    /**
     * Integration/test (INT) environment. Access requires a prior application to COI,
     * see https://www.gov.pl/web/e-doreczenia/integracja-uslugi-e-doreczen-z-systemami-klasy-ezd.
     */
    public static function integration(): self
    {
        return new self(
            'integration',
            'https://int-ow.edoreczenia.gov.pl/auth/realms/EDOR/protocol/openid-connect/token',
            'https://int-ow.edoreczenia.gov.pl/auth/realms/EDOR',
            'https://int-ow.edoreczenia.gov.pl/api/se/v4/',
            'https://uaapi-int-ow.poczta-polska.pl/api/v3/',
        );
    }

    private static function assertUrl(string $url, string $field): string
    {
        $parts = parse_url($url);
        $scheme = \is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        $host = \is_array($parts) ? strtolower($parts['host'] ?? '') : '';

        if ($host === '' || !\in_array($scheme, ['https', 'http'], true)) {
            throw new InvalidArgumentException(\sprintf('Environment %s must be an absolute http(s) URL.', $field));
        }

        if ($scheme === 'http' && !\in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            throw new InvalidArgumentException(\sprintf('Environment %s must use HTTPS (plain HTTP is allowed only for loopback hosts).', $field));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(\sprintf('Environment %s must not contain credentials.', $field));
        }

        return $url;
    }
}
