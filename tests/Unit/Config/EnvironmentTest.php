<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\Config;

use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testOfficialEnvironmentsUseDocumentedHosts(): void
    {
        $prod = Environment::production();
        $int = Environment::integration();

        self::assertSame('https://ow.edoreczenia.gov.pl/auth/realms/EDOR/protocol/openid-connect/token', $prod->tokenEndpoint);
        self::assertSame('https://ow.edoreczenia.gov.pl/auth/realms/EDOR', $prod->assertionAudience);
        self::assertSame('https://ow.edoreczenia.gov.pl/api/se/v4/', $prod->searchEngineV4BaseUri);
        self::assertSame('https://uaapi-ow.poczta-polska.pl/api/v3/', $prod->userAgentV3BaseUri);

        self::assertSame('https://int-ow.edoreczenia.gov.pl/auth/realms/EDOR/protocol/openid-connect/token', $int->tokenEndpoint);
        self::assertSame('https://int-ow.edoreczenia.gov.pl/api/se/v4/', $int->searchEngineV4BaseUri);
        self::assertSame('https://uaapi-int-ow.poczta-polska.pl/api/v3/', $int->userAgentV3BaseUri);
    }

    public function testCustomEnvironmentNormalisesBaseUris(): void
    {
        $env = new Environment('mock', 'http://localhost:8080/token', 'http://localhost:8080/realm', 'http://127.0.0.1:8080/se', 'https://ua.example.test/api/v3');

        self::assertSame('http://127.0.0.1:8080/se/', $env->searchEngineV4BaseUri);
        self::assertSame('https://ua.example.test/api/v3/', $env->userAgentV3BaseUri);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function insecureUrls(): iterable
    {
        yield 'plain http to remote host' => ['http://ow.edoreczenia.gov.pl/token'];
        yield 'relative' => ['/token'];
        yield 'ftp' => ['ftp://example.test/token'];
        yield 'credentials in url' => ['https://user:pass@example.test/token'];
    }

    #[DataProvider('insecureUrls')]
    public function testRejectsInsecureOrInvalidUrls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Environment('x', $url, 'https://example.test/realm', 'https://example.test/se', 'https://example.test/ua');
    }
}
