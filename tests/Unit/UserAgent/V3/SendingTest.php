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
use B4x\Edoreczenia\Exception\UnexpectedResponseException;
use B4x\Edoreczenia\Http\RetryPolicy;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\Tests\Support\MockHttpClient;
use B4x\Edoreczenia\Tests\Support\NetworkError;
use B4x\Edoreczenia\Tests\Support\RecordingLogger;
use B4x\Edoreczenia\Tests\Support\SpyTokenProvider;
use B4x\Edoreczenia\UserAgent\V3\Enum\MessageTaskState;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingAttachment;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingMessage;
use PHPUnit\Framework\TestCase;

final class SendingTest extends TestCase
{
    private const MAILBOX = 'AE:PL-12345-67890-ABCDE-12';
    private const RECIPIENT = 'AE:PL-98765-43210-SFVYC-19';
    private const BASE = 'https://uaapi-int-ow.poczta-polska.pl/api/v3/';
    private const TASK = '7C9E6679-7425-40DE-944B-E07FC1F90AE7';

    private MockHttpClient $http;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->logger = new RecordingLogger();
    }

    public function testSendMessagePostsTheMessageAndReturnsTheTask(): void
    {
        $this->http->queue(Http::fixture(202, 'user-agent-v3/send.202.json'));

        $task = $this->client()->userAgent()->sendMessage(
            OutgoingMessage::create('Wniosek')->withRecipients(self::RECIPIENT)->withTextBody('Treść'),
        );

        self::assertSame(self::TASK, $task->messageTaskId);
        self::assertSame('Skrzynka zapełniona w 90%', $task->warning);

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE . self::MAILBOX . '/messages', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('Bearer token-1', $request->getHeaderLine('Authorization'));
        self::assertSame([
            'messageMetadata' => [
                'from' => ['eDeliveryAddress' => self::MAILBOX],
                'to' => [['eDeliveryAddress' => self::RECIPIENT]],
                'subject' => 'Wniosek',
                'shippingService' => 'electronic',
            ],
            'textBody' => 'Treść',
        ], Http::jsonBody($request));
    }

    public function testMessageWithoutRecipientsIsRejectedBeforeAnyRequest(): void
    {
        try {
            $this->client()->userAgent()->sendMessage(OutgoingMessage::create('Wniosek'));
            self::fail('Expected exception');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('at least one recipient', $e->getMessage());
        }
        self::assertSame([], $this->http->requests);
    }

    public function testSendMessageIsNeverRetriedOnServerErrorsEvenWithARetryPolicy(): void
    {
        // A second queued response would be consumed by a retry; it must stay unused.
        $this->http->queue(Http::json(503, []), Http::fixture(202, 'user-agent-v3/send.202.json'));

        try {
            $this->retryingClient()->userAgent()->sendMessage(OutgoingMessage::create('Wniosek')->withRecipients(self::RECIPIENT));
            self::fail('Expected exception');
        } catch (ServerException $e) {
            self::assertSame(503, $e->getStatusCode());
        }
        self::assertCount(1, $this->http->requests, 'exactly one POST');
        self::assertSame(1, $this->http->pending());
    }

    public function testSendMessageIsNeverRetriedOnRateLimitOrNetworkErrors(): void
    {
        $message = OutgoingMessage::create('Wniosek')->withRecipients(self::RECIPIENT);
        $request = Http::factory()->createRequest('POST', self::BASE);

        $this->http->queue(Http::json(429, [], ['Retry-After' => '0']), Http::fixture(202, 'user-agent-v3/send.202.json'));
        try {
            $this->retryingClient()->userAgent()->sendMessage($message);
            self::fail('Expected exception');
        } catch (RateLimitException) {
        }
        self::assertCount(1, $this->http->requests);

        $this->http = new MockHttpClient();
        $this->http->queue(new NetworkError($request), Http::fixture(202, 'user-agent-v3/send.202.json'));
        try {
            $this->retryingClient()->userAgent()->sendMessage($message);
            self::fail('Expected exception');
        } catch (TransportException $e) {
            self::assertTrue($e->isNetworkError());
        }
        self::assertCount(1, $this->http->requests);
    }

    public function testSendDraftIsNeverRetried(): void
    {
        $this->http->queue(Http::json(502, []), Http::fixture(202, 'user-agent-v3/send.202.json'));

        try {
            $this->retryingClient()->userAgent()->sendDraft('D-1');
            self::fail('Expected exception');
        } catch (ServerException) {
        }
        self::assertCount(1, $this->http->requests);
        self::assertSame(self::BASE . self::MAILBOX . '/drafts/send', (string) $this->http->lastRequest()->getUri());
        self::assertSame(['messageId' => 'D-1'], Http::jsonBody($this->http->lastRequest()));
    }

    public function testSendDraft(): void
    {
        $this->http->queue(Http::fixture(202, 'user-agent-v3/send.202.json'));

        self::assertSame(self::TASK, $this->client()->userAgent()->sendDraft('D-1')->messageTaskId);
        self::assertSame('POST', $this->http->lastRequest()->getMethod());
    }

    public function testBadRequestOnSendIsMapped(): void
    {
        $this->http->queue(Http::json(400, [['error' => 'INVALID_RECIPIENT', 'error_description' => 'x']]));

        $this->expectException(BadRequestException::class);
        $this->client()->userAgent()->sendMessage(OutgoingMessage::create('Wniosek')->withRecipients(self::RECIPIENT));
    }

    public function testMissingTaskIdIsNotAnError(): void
    {
        // The message may have been accepted; failing here would invite a duplicate send.
        $this->http->queue(Http::json(202, '{}'));

        $task = $this->client()->userAgent()->sendMessage(OutgoingMessage::create('Wniosek')->withRecipients(self::RECIPIENT));

        self::assertNull($task->messageTaskId);
        self::assertNull($task->warning);
    }

    public function testUnreadableAcceptedResponseCarriesTheSuccessStatus(): void
    {
        $this->http->queue(Http::json(202, 'not json'));

        try {
            $this->client()->userAgent()->sendMessage(OutgoingMessage::create('Wniosek')->withRecipients(self::RECIPIENT));
            self::fail('Expected exception');
        } catch (UnexpectedResponseException $e) {
            self::assertSame(202, $e->getStatusCode());
        }
    }

    public function testMessageTaskMapsPerRecipientResults(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/message-task.200.json'));

        $items = $this->client()->userAgent()->messageTask(self::TASK);

        self::assertSame(self::BASE . self::MAILBOX . '/messages/tasks/' . self::TASK, (string) $this->http->lastRequest()->getUri());
        self::assertSame('GET', $this->http->lastRequest()->getMethod());
        self::assertCount(2, $items);
        self::assertTrue($items[0]->isAccepted());
        self::assertSame('0A1B2C3D-0000-4000-8000-000000000002', $items[0]->messageId);
        self::assertSame(self::RECIPIENT, $items[0]->recipientAddress);
        self::assertSame('MINISTERSTWO POCZT I TELEGRAFÓW', $items[0]->recipient?->displayName());
        self::assertFalse($items[1]->isAccepted());
        self::assertSame('INVALID_RECIPIENT', $items[1]->error);
        self::assertSame('Adres nieaktywny', $items[1]->errorDescription);
        self::assertSame('AE:PL-12345-67890-ABCDE-11', $items[1]->recipientAddress);
        self::assertNull($items[1]->messageId);
    }

    public function testMessageTaskReadsUndeclaredRequiredFieldsWhenPresent(): void
    {
        $this->http->queue(Http::json(200, [['messageId' => 'm', 'addresseeAde' => 'AE:PL-1', 'status' => 'OK']]));

        $item = $this->client()->userAgent()->messageTask(self::TASK)[0];

        self::assertSame('AE:PL-1', $item->recipientAddress);
        self::assertSame('OK', $item->status);
        self::assertNull($item->recipient);
    }

    public function testMessageTaskStatus(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/message-task-status.200.json'), Http::json(200, ['messageTaskStatus' => 'PENDING']), Http::json(200, ['messageTaskStatus' => 'CANCELLED']));
        $ua = $this->client()->userAgent();

        self::assertSame(MessageTaskState::Finished, $ua->messageTaskStatus(self::TASK));
        self::assertSame(self::BASE . self::MAILBOX . '/messages/tasks/' . self::TASK . '/status', (string) $this->http->lastRequest()->getUri());
        self::assertSame(MessageTaskState::Pending, $ua->messageTaskStatus(self::TASK));
        self::assertSame('CANCELLED', $ua->messageTaskStatus(self::TASK), 'unknown states are kept as strings');
    }

    public function testMessageTaskStatusRequiresTheField(): void
    {
        $this->http->queue(Http::json(200, ['status' => 'FINISHED']));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('$.messageTaskStatus');
        $this->client()->userAgent()->messageTaskStatus(self::TASK);
    }

    public function testTaskPollingIsRetriedBecauseItIsReadOnly(): void
    {
        $this->http->queue(Http::json(503, []), Http::fixture(200, 'user-agent-v3/message-task-status.200.json'), Http::json(504, []), Http::fixture(200, 'user-agent-v3/message-task.200.json'));
        $ua = $this->retryingClient()->userAgent();

        self::assertSame(MessageTaskState::Finished, $ua->messageTaskStatus(self::TASK));
        self::assertCount(2, $ua->messageTask(self::TASK));
        self::assertCount(4, $this->http->requests);
    }

    public function testTaskIdsAreValidated(): void
    {
        foreach (['', 'a b'] as $id) {
            try {
                $this->client()->userAgent()->messageTaskStatus($id);
                self::fail('Expected exception');
            } catch (InvalidArgumentException) {
            }
            try {
                $this->client()->userAgent()->messageTask($id);
                self::fail('Expected exception');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->http->requests);
    }

    public function testRecipientsSubjectAndContentNeverReachLogs(): void
    {
        $this->http->queue(Http::fixture(202, 'user-agent-v3/send.202.json'));

        $this->client()->userAgent()->sendMessage(
            OutgoingMessage::create('Wezwanie KOWALSKI')
                ->withRecipients(self::RECIPIENT)
                ->withTextBody('Treść poufna')
                ->withAttachment(OutgoingAttachment::fromString('PESEL 00000000000', 'kowalski.txt', 'text/plain')),
        );

        $logged = $this->logger->dump();
        foreach ([self::MAILBOX, self::RECIPIENT, 'KOWALSKI', 'kowalski', 'poufna', base64_encode('PESEL 00000000000')] as $secret) {
            self::assertStringNotContainsString($secret, $logged);
        }
        self::assertStringContainsString('/api/v3/{masked}/messages', $logged);
        self::assertStringContainsString('"method":"POST"', $logged);
    }

    private function client(): EdoreczeniaClient
    {
        return new EdoreczeniaClient(Environment::integration(), self::MAILBOX, new SpyTokenProvider(), $this->http, Http::factory(), Http::factory(), $this->logger);
    }

    private function retryingClient(): EdoreczeniaClient
    {
        return new EdoreczeniaClient(Environment::integration(), self::MAILBOX, new SpyTokenProvider(), $this->http, Http::factory(), Http::factory(), $this->logger, RetryPolicy::exponential(3, 0, 0));
    }
}
