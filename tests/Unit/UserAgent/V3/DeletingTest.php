<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\UserAgent\V3;

use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\Exception\BadRequestException;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\Exception\RateLimitException;
use B4x\Edoreczenia\Exception\ServerException;
use B4x\Edoreczenia\Exception\TransportException;
use B4x\Edoreczenia\Http\RetryPolicy;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\Tests\Support\MockHttpClient;
use B4x\Edoreczenia\Tests\Support\NetworkError;
use B4x\Edoreczenia\Tests\Support\RecordingLogger;
use B4x\Edoreczenia\Tests\Support\SpyTokenProvider;
use B4x\Edoreczenia\UserAgent\V3\UserAgentClient;
use PHPUnit\Framework\TestCase;

final class DeletingTest extends TestCase
{
    private const MAILBOX = 'AE:PL-12345-67890-ABCDE-12';
    private const BASE = 'https://uaapi-int-ow.poczta-polska.pl/api/v3/';
    private const MESSAGE = 'D41568F4-7175-42BB-9503-DAA282180D70';
    private const SENT = '0A1B2C3D-0000-4000-8000-000000000001';

    private MockHttpClient $http;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->logger = new RecordingLogger();
    }

    public function testDeleteMessagesJoinsIdsAndReturnsPerMessageResults(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/messages-deleted.200.json'));

        $deleted = $this->ua()->deleteMessages(self::MESSAGE, self::SENT);

        $request = $this->http->lastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame(self::BASE . self::MAILBOX . '/messages/' . self::MESSAGE . ',' . self::SENT, (string) $request->getUri());
        self::assertSame('', (string) $request->getBody());
        self::assertFalse($request->hasHeader('Content-Type'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertCount(2, $deleted);
        self::assertSame(self::MESSAGE, $deleted[0]->messageId);
        self::assertSame(self::SENT, $deleted[1]->messageId);
    }

    public function testEntriesWithoutMessageIdAreKept(): void
    {
        $this->http->queue(Http::json(200, [[], ['messageId' => 'M-2']]));

        $deleted = $this->ua()->deleteMessages('M-1', 'M-2');

        self::assertNull($deleted[0]->messageId);
        self::assertSame('M-2', $deleted[1]->messageId);
    }

    public function testIdsAreEncodedAsPathSegments(): void
    {
        $this->http->queue(Http::json(200, [['messageId' => 'a/b?c']]));

        $this->ua()->deleteMessages('a/b?c');

        self::assertSame(self::BASE . self::MAILBOX . '/messages/a%2Fb%3Fc', (string) $this->http->lastRequest()->getUri());
    }

    public function testLimitAndIdsAreValidatedBeforeAnyRequest(): void
    {
        $tooMany = array_map(static fn(int $i): string => 'M-' . $i, range(1, UserAgentClient::MAX_DELETED_MESSAGES + 1));
        foreach ([$tooMany, ['a,b'], [''], ['M-1', 'a b'], ['M-1', "x\n"]] as $ids) {
            try {
                $this->ua()->deleteMessages(...$ids);
                self::fail('Expected exception');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->http->requests);

        $this->http->queue(Http::json(200, array_map(static fn(string $id): array => ['messageId' => $id], \array_slice($tooMany, 0, 50))));
        self::assertCount(50, $this->ua()->deleteMessages(...\array_slice($tooMany, 0, 50)));
    }

    public function testDeleteMessagesIsNeverRetriedOn503WithAnExponentialRetryPolicy(): void
    {
        // A retry would consume the queued 200; it must stay unused.
        $this->http->queue(Http::json(503, []), Http::fixture(200, 'user-agent-v3/messages-deleted.200.json'));

        try {
            $this->retryingClient()->userAgent()->deleteMessages(self::MESSAGE);
            self::fail('Expected exception');
        } catch (ServerException $e) {
            self::assertSame(503, $e->getStatusCode());
        }
        self::assertCount(1, $this->http->requests, 'exactly one DELETE');
        self::assertSame(1, $this->http->pending());
    }

    public function testDeleteMessagesIsNeverRetriedOnRateLimitOrNetworkErrors(): void
    {
        $this->http->queue(Http::json(429, [], ['Retry-After' => '0']), Http::fixture(200, 'user-agent-v3/messages-deleted.200.json'));
        try {
            $this->retryingClient()->userAgent()->deleteMessages(self::MESSAGE);
            self::fail('Expected exception');
        } catch (RateLimitException) {
        }
        self::assertCount(1, $this->http->requests);

        $this->http = new MockHttpClient();
        $this->http->queue(new NetworkError(Http::factory()->createRequest('DELETE', self::BASE)), Http::fixture(200, 'user-agent-v3/messages-deleted.200.json'));
        try {
            $this->retryingClient()->userAgent()->deleteMessages(self::MESSAGE);
            self::fail('Expected exception');
        } catch (TransportException $e) {
            self::assertTrue($e->isNetworkError());
        }
        self::assertCount(1, $this->http->requests);
    }

    public function testPerMessageErrorsAreMapped(): void
    {
        $this->http->queue(Http::json(400, [['error' => 'INVALID_PARAMETER', 'error_description' => 'Unknown message', 'messageId' => 'M-1']]));

        try {
            $this->ua()->deleteMessages('M-1');
            self::fail('Expected exception');
        } catch (BadRequestException $e) {
            self::assertSame('INVALID_PARAMETER', $e->getErrors()[0]->code);
        }
    }

    public function testMailboxAndIdsNeverReachLogs(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/messages-deleted.200.json'));

        $this->ua()->deleteMessages(self::MESSAGE);

        $logged = $this->logger->dump();
        self::assertStringNotContainsString(self::MAILBOX, $logged);
        self::assertStringContainsString('"method":"DELETE"', $logged);
        self::assertStringContainsString('DELETE messages/{messageId}', $logged);
    }

    private function ua(): UserAgentClient
    {
        return (new EdoreczeniaClient(Environment::integration(), self::MAILBOX, new SpyTokenProvider(), $this->http, Http::factory(), Http::factory(), $this->logger))->userAgent();
    }

    private function retryingClient(): EdoreczeniaClient
    {
        return new EdoreczeniaClient(Environment::integration(), self::MAILBOX, new SpyTokenProvider(), $this->http, Http::factory(), Http::factory(), $this->logger, RetryPolicy::exponential(3, 0, 0));
    }
}
