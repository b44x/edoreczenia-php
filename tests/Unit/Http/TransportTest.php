<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\Http;

use B4x\Edoreczenia\Exception\AuthenticationException;
use B4x\Edoreczenia\Exception\BadRequestException;
use B4x\Edoreczenia\Exception\RateLimitException;
use B4x\Edoreczenia\Exception\ServerException;
use B4x\Edoreczenia\Exception\TransportException;
use B4x\Edoreczenia\Exception\UnexpectedResponseException;
use B4x\Edoreczenia\Http\RetryPolicy;
use B4x\Edoreczenia\Internal\Transport;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\Tests\Support\MockHttpClient;
use B4x\Edoreczenia\Tests\Support\NetworkError;
use B4x\Edoreczenia\Tests\Support\RecordingLogger;
use B4x\Edoreczenia\Tests\Support\SpyTokenProvider;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    private const URI = 'https://int-ow.edoreczenia.gov.pl/api/se/v4/search/bae_search';

    private MockHttpClient $http;
    private SpyTokenProvider $tokens;
    private RecordingLogger $logger;
    /** @var list<int> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->tokens = new SpyTokenProvider();
        $this->logger = new RecordingLogger();
        $this->sleeps = [];
    }

    public function testSendsAuthenticatedJsonAndDecodesResponse(): void
    {
        $this->http->queue(Http::json(200, ['ok' => true]));

        $result = $this->transport()->postJson(self::URI, ['a' => 'ż'], 'op', true);

        self::assertSame(['ok' => true], $result);
        $request = $this->http->lastRequest();
        self::assertSame('Bearer token-1', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('{"a":"ż"}', (string) $request->getBody());
    }

    public function testRetriesTransientFailuresOfSafeOperations(): void
    {
        $this->http->queue(
            Http::fixture(504, 'search-engine-v4/error.504.json'),
            new NetworkError(new Request('POST', self::URI)),
            Http::json(200, ['ok' => true]),
        );

        $result = $this->transport(RetryPolicy::exponential(2, 10, 40))->postJson(self::URI, [], 'op', true);

        self::assertSame(['ok' => true], $result);
        self::assertCount(3, $this->http->requests);
        self::assertCount(2, $this->sleeps);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $this->http->queue(Http::json(503, []), Http::json(503, []), Http::json(503, []));

        try {
            $this->transport(RetryPolicy::exponential(2, 10, 40))->postJson(self::URI, [], 'op', true);
            self::fail('Expected exception');
        } catch (ServerException $e) {
            self::assertSame(503, $e->getStatusCode());
        }

        self::assertCount(3, $this->http->requests);
    }

    public function testNeverRetriesOperationsWithSideEffects(): void
    {
        $this->http->queue(Http::json(503, []));

        $this->expectException(ServerException::class);
        try {
            $this->transport(RetryPolicy::exponential(3))->postJson(self::URI, [], 'op', false);
        } finally {
            self::assertCount(1, $this->http->requests);
            self::assertSame([], $this->sleeps);
        }
    }

    public function testNeverRetriesNetworkFailuresOfOperationsWithSideEffects(): void
    {
        $this->http->queue(new NetworkError(new Request('POST', self::URI)));

        $this->expectException(TransportException::class);
        try {
            $this->transport(RetryPolicy::exponential(3))->postJson(self::URI, [], 'op', false);
        } finally {
            self::assertCount(1, $this->http->requests);
        }
    }

    public function testDoesNotRetryClientErrors(): void
    {
        $this->http->queue(Http::fixture(400, 'search-engine-v4/error.400.json'));

        $this->expectException(BadRequestException::class);
        try {
            $this->transport(RetryPolicy::exponential(3))->postJson(self::URI, [], 'op', true);
        } finally {
            self::assertCount(1, $this->http->requests);
        }
    }

    public function testHonoursShortRetryAfter(): void
    {
        $this->http->queue(Http::json(429, [], ['Retry-After' => '1']), Http::json(200, []));

        $this->transport(RetryPolicy::exponential(1, 100, 2000))->postJson(self::URI, [], 'op', true);

        self::assertSame([1000], $this->sleeps);
    }

    public function testDoesNotWaitLongerThanPolicyAllows(): void
    {
        $this->http->queue(Http::json(429, [], ['Retry-After' => '120']));

        $this->expectException(RateLimitException::class);
        $this->transport(RetryPolicy::exponential(3, 100, 2000))->postJson(self::URI, [], 'op', true);
    }

    public function testUnauthorizedInvalidatesTokenAndIsNotRetried(): void
    {
        $this->http->queue(Http::json(401, ['httpCode' => '401', 'id' => 'e1', 'errorCodes' => [['errorCode' => 'SEAPI-00017', 'language' => 'GB', 'message' => 'No authorization']]]));

        try {
            $this->transport(RetryPolicy::exponential(3))->postJson(self::URI, [], 'op', true);
            self::fail('Expected exception');
        } catch (AuthenticationException $e) {
            self::assertTrue($e->hasErrorCode('SEAPI-00017'));
            self::assertSame('e1', $e->getEventId());
        }

        self::assertSame(1, $this->tokens->invalidations);
        self::assertCount(1, $this->http->requests);
    }

    public function testRejectsNonJsonSuccessResponse(): void
    {
        $this->http->queue(new Response(200, ['Content-Type' => 'text/html'], '<html></html>'));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('content type');
        $this->transport()->postJson(self::URI, [], 'op', true);
    }

    public function testRejectsMalformedJson(): void
    {
        $this->http->queue(Http::json(200, '{"broken": '));

        try {
            $this->transport()->postJson(self::URI, [], 'op', true);
            self::fail('Expected exception');
        } catch (UnexpectedResponseException $e) {
            self::assertSame(200, $e->getStatusCode());
            self::assertStringNotContainsString('broken', $e->getMessage());
        }
    }

    public function testRejectsScalarJson(): void
    {
        $this->http->queue(Http::json(200, '"text"'));

        $this->expectException(UnexpectedResponseException::class);
        $this->transport()->postJson(self::URI, [], 'op', true);
    }

    public function testErrorWithoutJsonBodyStillMapsStatus(): void
    {
        $this->http->queue(new Response(502, ['Content-Type' => 'text/html'], '<h1>Bad gateway PESEL 12345678901</h1>'));

        try {
            $this->transport()->postJson(self::URI, [], 'op', true);
            self::fail('Expected exception');
        } catch (ServerException $e) {
            self::assertSame([], $e->getErrors());
            self::assertStringNotContainsString('12345678901', $e->getMessage());
        }
    }

    public function testParsesUaApiErrorListFormat(): void
    {
        $this->http->queue(Http::json(400, [['error' => 'MSG-001', 'error_description' => 'Invalid message', 'messageId' => 'm1']]));

        try {
            $this->transport()->postJson(self::URI, [], 'op', false);
            self::fail('Expected exception');
        } catch (BadRequestException $e) {
            self::assertTrue($e->hasErrorCode('MSG-001'));
        }
    }

    public function testLogsNeitherTokensNorBodiesNorQueryStrings(): void
    {
        $this->http->queue(Http::json(200, ['name' => 'JAN KOWALSKI']), Http::fixture(400, 'search-engine-v4/error.400.json'));
        $transport = $this->transport();

        $transport->postJson(self::URI . '?secret=1', ['surname' => 'KOWALSKI'], 'op', true);
        try {
            $transport->postJson(self::URI, ['surname' => 'NOWAK'], 'op', true);
        } catch (BadRequestException) {
        }

        $logged = $this->logger->dump();
        foreach (['token-', 'Bearer', 'KOWALSKI', 'NOWAK', 'secret=1'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $logged);
        }
        self::assertStringContainsString('SEAPI-00007', $logged);
        self::assertStringContainsString('7770c1c4-e07b-479d-a496-a69eb9488366', $logged);
    }

    public function testRedactUriDropsQueryFragmentAndUserInfo(): void
    {
        self::assertSame('https://host.test:8443/a/b', Transport::redactUri('https://u:p@host.test:8443/a/b?x=1#f'));
    }

    private function transport(?RetryPolicy $policy = null): Transport
    {
        return new Transport(
            $this->http,
            Http::factory(),
            Http::factory(),
            $this->tokens,
            $policy ?? RetryPolicy::none(),
            $this->logger,
            function (int $ms): void {
                $this->sleeps[] = $ms;
            },
        );
    }
}
