<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\UserAgent\V3;

use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\Exception\BadRequestException;
use B4x\Edoreczenia\Exception\UnexpectedResponseException;
use B4x\Edoreczenia\Http\RetryPolicy;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\Tests\Support\MockHttpClient;
use B4x\Edoreczenia\Tests\Support\RecordingLogger;
use B4x\Edoreczenia\Tests\Support\SpyTokenProvider;
use B4x\Edoreczenia\UserAgent\V3\Enum\DirectoryType;
use B4x\Edoreczenia\UserAgent\V3\Enum\ListFormat;
use B4x\Edoreczenia\UserAgent\V3\Enum\MessageStatus;
use B4x\Edoreczenia\UserAgent\V3\Enum\MessageType;
use B4x\Edoreczenia\UserAgent\V3\Enum\ShippingService;
use B4x\Edoreczenia\UserAgent\V3\Request\MessageQuery;
use PHPUnit\Framework\TestCase;

final class UserAgentClientTest extends TestCase
{
    private const MAILBOX = 'AE:PL-12345-67890-ABCDE-12';
    private const BASE = 'https://uaapi-int-ow.poczta-polska.pl/api/v3/';

    private MockHttpClient $http;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->logger = new RecordingLogger();
    }

    public function testCountriesUsesDictionaryEndpointWithoutMailbox(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/countries.200.json'));

        self::assertSame(['POLSKA', 'NIEMCY', 'CZECHY'], $this->client()->userAgent()->countries());

        $request = $this->http->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::BASE . 'dictionary/countries', (string) $request->getUri());
        self::assertSame('Bearer token-1', $request->getHeaderLine('Authorization'));
        self::assertFalse($request->hasHeader('Content-Type'), 'GET requests carry no body');
        self::assertSame('', (string) $request->getBody());
    }

    public function testMailboxAddressIsAPathSegmentWithColonKept(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/directories.200.json'));

        $directories = $this->client()->userAgent()->directories();

        self::assertSame(self::BASE . 'AE:PL-12345-67890-ABCDE-12/directories', (string) $this->http->lastRequest()->getUri());
        self::assertCount(2, $directories);
        self::assertSame('INBOX', $directories[0]->label);
        self::assertSame(DirectoryType::Predefined, $directories[0]->type);
        self::assertSame(DirectoryType::Defined, $directories[1]->type);
        self::assertNull($directories[0]->totalMessages);
    }

    public function testDirectoriesWithCounts(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/numberMessages.200.json'));

        $directories = $this->client()->userAgent()->directoriesWithCounts();

        self::assertSame(self::BASE . self::MAILBOX . '/numberMessages', (string) $this->http->lastRequest()->getUri());
        self::assertSame(3, $directories[0]->unreadMessages);
        self::assertSame(120, $directories[0]->totalMessages);
    }

    public function testSettings(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/settings.200.json'));

        $settings = $this->client()->userAgent()->settings();

        self::assertSame(self::BASE . self::MAILBOX . '/settings', (string) $this->http->lastRequest()->getUri());
        self::assertSame('skrzynka@example.test', $settings->email);
        self::assertSame(1024.0, $settings->totalCapacityMb());
        self::assertSame(12.5, $settings->usedCapacityMb());
    }

    public function testListMessagesDefaultsToNoQueryAndMapsMetadata(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/messages.200.json'));

        $list = $this->client()->userAgent()->listMessages();

        self::assertSame(self::BASE . self::MAILBOX . '/messages', (string) $this->http->lastRequest()->getUri());
        self::assertSame(2, $list->total);
        self::assertSame(1, $list->pendingElectronic);

        $first = $list->messages[0];
        self::assertSame('D41568F4-7175-42BB-9503-DAA282180D70', $first->messageId);
        self::assertSame(MessageStatus::Delivered, $first->status);
        self::assertSame(MessageType::Message, $first->type);
        self::assertSame(ShippingService::Electronic, $first->shippingService);
        self::assertFalse($first->opened);
        self::assertSame(12, $first->daysToReceive);
        self::assertSame('MINISTERSTWO POCZT I TELEGRAFÓW', $first->from?->displayName());
        self::assertSame('ADAM MALINOWSKI', $first->to[0]->displayName());
        self::assertSame('INBOX', $first->labels[0]->label);
        self::assertSame('2017-07-21T17:32:28+00:00', $first->submissionDate?->format(\DATE_ATOM));
        self::assertFalse($first->isIncomingNotification());

        $second = $list->messages[1];
        self::assertSame(MessageStatus::Rejected, $second->status);
        self::assertSame(['Nieprawidłowy format pliku', 'Invalid file format'], $second->statusReasons());
        self::assertSame('MOJ_NOWY_FOLDER', $second->labels[0]->location);
        self::assertNull($second->from);
    }

    public function testListMessagesSendsFiltersAsQueryParameters(): void
    {
        $this->http->queue(Http::json(200, ['messages' => [], 'total' => 0]));

        $this->client()->userAgent()->listMessages(
            MessageQuery::create()
                ->withFormat(ListFormat::Metadata)
                ->inFolder('INBOX')
                ->onlyOpened(false)
                ->withPagination(40, 20)
                ->submittedBetween(new \DateTimeImmutable('2026-01-01 00:00:00+01:00'), null),
        );

        $uri = $this->http->lastRequest()->getUri();
        self::assertSame('/api/v3/' . self::MAILBOX . '/messages', $uri->getPath());
        parse_str($uri->getQuery(), $query);
        self::assertSame([
            'format' => 'metadata',
            'label' => 'INBOX',
            'opened' => 'false',
            'offset' => '40',
            'limit' => '20',
            'submissionDateFrom' => '2025-12-31T23:00:00Z',
        ], $query);
    }

    public function testUaErrorListIsParsed(): void
    {
        $this->http->queue(Http::fixture(400, 'user-agent-v3/error.400.json'));

        try {
            $this->client()->userAgent()->listMessages();
            self::fail('Expected exception');
        } catch (BadRequestException $e) {
            self::assertTrue($e->hasErrorCode('INVALID_PARAMETER'));
        }
    }

    public function testReadOperationsAreRetried(): void
    {
        $this->http->queue(Http::json(503, []), Http::fixture(200, 'user-agent-v3/countries.200.json'));

        $client = new EdoreczeniaClient(Environment::integration(), self::MAILBOX, new SpyTokenProvider(), $this->http, Http::factory(), Http::factory(), null, RetryPolicy::exponential(1, 0, 0));

        self::assertCount(3, $client->userAgent()->countries());
        self::assertCount(2, $this->http->requests);
    }

    public function testMessageWithoutIdIsRejected(): void
    {
        $this->http->queue(Http::json(200, ['messages' => [['messageMetadata' => ['subject' => 'x']]]]));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('$.messages[0].messageMetadata.messageId');
        $this->client()->userAgent()->listMessages();
    }

    public function testInvalidDateIsRejectedWithoutEchoingIt(): void
    {
        $this->http->queue(Http::json(200, ['messages' => [['messageMetadata' => ['messageId' => 'm', 'timestamp' => 'JAN KOWALSKI']]]]));

        try {
            $this->client()->userAgent()->listMessages();
            self::fail('Expected exception');
        } catch (UnexpectedResponseException $e) {
            self::assertStringNotContainsString('KOWALSKI', $e->getMessage());
        }
    }

    public function testUnknownStatusIsPreservedAsString(): void
    {
        $this->http->queue(Http::json(200, ['messages' => [[
            'messageControlData' => ['status' => 'Nowy status', 'messageType' => 'Stub'],
            'messageMetadata' => ['messageId' => 'm'],
        ]]]));

        $message = $this->client()->userAgent()->listMessages()->messages[0];

        self::assertSame('Nowy status', $message->status);
        self::assertTrue($message->isIncomingNotification());
    }

    public function testMailboxAddressAndQueryNeverReachLogs(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/messages.200.json'));

        $this->client()->userAgent()->listMessages(MessageQuery::create()->fromSender('JAN KOWALSKI'));

        $logged = $this->logger->dump();
        self::assertStringNotContainsString(self::MAILBOX, $logged);
        self::assertStringNotContainsString('KOWALSKI', $logged);
        self::assertStringNotContainsString('MALINOWSKI', $logged);
        self::assertStringContainsString('/api/v3/{masked}/messages', $logged);
        self::assertStringContainsString('"method":"GET"', $logged);
    }

    private function client(): EdoreczeniaClient
    {
        return new EdoreczeniaClient(Environment::integration(), self::MAILBOX, new SpyTokenProvider(), $this->http, Http::factory(), Http::factory(), $this->logger);
    }
}
