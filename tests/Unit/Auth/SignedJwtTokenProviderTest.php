<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\Auth;

use B4x\Edoreczenia\Auth\PrivateKey;
use B4x\Edoreczenia\Auth\SignedJwtTokenProvider;
use B4x\Edoreczenia\Auth\SystemCredentials;
use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\Exception\AuthenticationException;
use B4x\Edoreczenia\Exception\ServerException;
use B4x\Edoreczenia\Exception\TransportException;
use B4x\Edoreczenia\Exception\UnexpectedResponseException;
use B4x\Edoreczenia\Tests\Support\ArrayCache;
use B4x\Edoreczenia\Tests\Support\FrozenClock;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\Tests\Support\MockHttpClient;
use B4x\Edoreczenia\Tests\Support\NetworkError;
use B4x\Edoreczenia\Tests\Support\RecordingLogger;
use B4x\Edoreczenia\Tests\Support\TestKeys;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class SignedJwtTokenProviderTest extends TestCase
{
    private const ADDRESS = 'AE:PL-12345-67890-ABCDE-12';

    private MockHttpClient $http;
    private FrozenClock $clock;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->clock = new FrozenClock();
        $this->logger = new RecordingLogger();
    }

    public function testRequestsTokenWithDocumentedClientCredentialsFlow(): void
    {
        $this->http->queue(Http::tokenResponse('abc.def.ghi'));

        $token = $this->provider()->getToken();

        self::assertSame('abc.def.ghi', $token->value());
        self::assertSame($this->clock->now()->getTimestamp() + 300, $token->expiresAt->getTimestamp());

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://int-ow.edoreczenia.gov.pl/auth/realms/EDOR/protocol/openid-connect/token?login_hint=ADE.AE%3APL-12345-67890-ABCDE-12',
            (string) $request->getUri(),
        );
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertFalse($request->hasHeader('Authorization'));

        parse_str((string) $request->getBody(), $form);
        self::assertSame('client_credentials', $form['grant_type']);
        self::assertSame('urn:ietf:params:oauth:client-assertion-type:jwt-bearer', $form['client_assertion_type']);
        self::assertIsString($form['client_assertion']);
        self::assertCount(3, explode('.', $form['client_assertion']));
    }

    public function testReusesTokenUntilShortlyBeforeExpiry(): void
    {
        $this->http->queue(Http::tokenResponse('first'), Http::tokenResponse('second'));
        $provider = $this->provider();

        self::assertSame('first', $provider->getToken()->value());
        $this->clock->advance(269);
        self::assertSame('first', $provider->getToken()->value(), 'still more than 30 s left');
        self::assertCount(1, $this->http->requests);

        $this->clock->advance(2);
        self::assertSame('second', $provider->getToken()->value(), 'refreshed within the 30 s safety margin');
        self::assertCount(2, $this->http->requests);
    }

    public function testInvalidateForcesNewToken(): void
    {
        $this->http->queue(Http::tokenResponse('first'), Http::tokenResponse('second'));
        $provider = $this->provider();

        $provider->getToken();
        $provider->invalidate();

        self::assertSame('second', $provider->getToken()->value());
    }

    public function testSharesTokenThroughPsr16Cache(): void
    {
        $cache = new ArrayCache();
        $this->http->queue(Http::tokenResponse('shared'));

        $this->provider($cache)->getToken();
        $secondProcess = $this->provider($cache);

        self::assertSame('shared', $secondProcess->getToken()->value());
        self::assertCount(1, $this->http->requests, 'second provider must use the cached token');
        self::assertSame([300], array_values($cache->ttls));

        $secondProcess->invalidate();
        self::assertSame([], $cache->items);
    }

    public function testIgnoresCorruptCacheEntries(): void
    {
        $cache = new ArrayCache();
        $this->http->queue(Http::tokenResponse('fresh'));
        $this->provider($cache)->getToken();
        foreach (array_keys($cache->items) as $key) {
            $cache->items[$key] = 'garbage';
        }
        $this->http->queue(Http::tokenResponse('fresh2'));

        self::assertSame('fresh2', $this->provider($cache)->getToken()->value());
    }

    public function testOAuthErrorBecomesAuthenticationExceptionWithoutRetry(): void
    {
        $this->http->queue(Http::json(401, ['error' => 'invalid_client', 'error_description' => 'Invalid client or Invalid client credentials']));

        try {
            $this->provider()->getToken();
            self::fail('Expected exception');
        } catch (AuthenticationException $e) {
            self::assertTrue($e->hasErrorCode('invalid_client'));
            self::assertSame(401, $e->getStatusCode());
        }

        self::assertCount(1, $this->http->requests);
        self::assertSame(0, $this->http->pending());
    }

    public function testKeycloak400InvalidRequestIsNotSilentlyIgnored(): void
    {
        $this->http->queue(Http::json(400, ['error' => 'invalid_request', 'error_description' => 'Missing parameter']));

        $this->expectException(\B4x\Edoreczenia\Exception\BadRequestException::class);
        $this->provider()->getToken();
    }

    public function testServerErrorIsReported(): void
    {
        $this->http->queue(new Response(503, ['Content-Type' => 'text/html'], '<html>maintenance</html>'));

        try {
            $this->provider()->getToken();
            self::fail('Expected exception');
        } catch (ServerException $e) {
            self::assertStringNotContainsString('maintenance', $e->getMessage(), 'response bodies are never copied');
        }
    }

    public function testNetworkFailureBecomesTransportException(): void
    {
        $this->http->queue(new NetworkError(new Request('POST', 'https://int-ow.edoreczenia.gov.pl')));

        $this->expectException(TransportException::class);
        $this->provider()->getToken();
    }

    public function testMalformedTokenResponse(): void
    {
        $this->http->queue(Http::json(200, '{"access_token": '));

        $this->expectException(UnexpectedResponseException::class);
        $this->provider()->getToken();
    }

    public function testMissingAccessToken(): void
    {
        $this->http->queue(Http::json(200, ['expires_in' => 300]));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('access_token');
        $this->provider()->getToken();
    }

    public function testRejectsNonBearerTokenType(): void
    {
        $this->http->queue(Http::json(200, ['access_token' => 'x', 'expires_in' => 300, 'token_type' => 'DPoP']));

        $this->expectException(UnexpectedResponseException::class);
        $this->provider()->getToken();
    }

    public function testRejectsMissingExpiry(): void
    {
        $this->http->queue(Http::json(200, ['access_token' => 'x', 'token_type' => 'Bearer']));

        $this->expectException(UnexpectedResponseException::class);
        $this->provider()->getToken();
    }

    public function testSecretsNeverReachLogs(): void
    {
        $this->http->queue(Http::tokenResponse('super-secret-token'));
        $this->provider()->getToken();

        $logged = $this->logger->dump();
        self::assertNotSame('[]', $logged);
        self::assertStringNotContainsString('super-secret-token', $logged);
        self::assertStringNotContainsString('client_assertion', $logged);
        self::assertStringNotContainsString('login_hint', $logged);
        self::assertStringNotContainsString(self::ADDRESS, $logged);
    }

    public function testDumpOfTokenIsRedacted(): void
    {
        $this->http->queue(Http::tokenResponse('super-secret-token'));

        self::assertStringNotContainsString('super-secret-token', print_r($this->provider()->getToken(), true));
    }

    private function provider(?CacheInterface $cache = null): SignedJwtTokenProvider
    {
        $credentials = new SystemCredentials(self::ADDRESS, 'EZD1', PrivateKey::fromPem(TestKeys::rsa()['private']));

        return new SignedJwtTokenProvider(
            $credentials,
            Environment::integration(),
            $this->http,
            Http::factory(),
            Http::factory(),
            $this->clock,
            $cache,
            $this->logger,
        );
    }
}
