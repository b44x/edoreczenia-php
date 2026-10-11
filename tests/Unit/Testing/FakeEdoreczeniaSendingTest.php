<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\Testing;

use B4x\Edoreczenia\Exception\AuthenticationException;
use B4x\Edoreczenia\Exception\AuthorizationException;
use B4x\Edoreczenia\Exception\BadRequestException;
use B4x\Edoreczenia\Exception\ServerException;
use B4x\Edoreczenia\Testing\FakeEdoreczenia;
use B4x\Edoreczenia\Testing\SyntheticData;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\UserAgent\V3\Enum\MessageTaskState;
use B4x\Edoreczenia\UserAgent\V3\Request\AttachmentUpload;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingAttachment;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingMessage;
use B4x\Edoreczenia\UserAgent\V3\UserAgentClient;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class FakeEdoreczeniaSendingTest extends TestCase
{
    private const UA = 'https://uaapi-int-ow.poczta-polska.pl/api/v3/';

    private FakeEdoreczenia $fake;
    private UserAgentClient $ua;

    protected function setUp(): void
    {
        $this->fake = new FakeEdoreczenia(Http::factory(), Http::factory());
        $this->ua = $this->fake->createClient()->userAgent();
    }

    public function testSendTaskAndStatusLifecycle(): void
    {
        $message = OutgoingMessage::create('Wniosek')
            ->withRecipients(SyntheticData::ACTIVE_RECIPIENT, SyntheticData::INACTIVE_RECIPIENT, 'AE:PL-00000-00000-UNKNO-00')
            ->withTextBody('Treść');

        $task = $this->ua->sendMessage($message);

        self::assertNotNull($task->messageTaskId);
        $polls = [];
        for ($i = 0; $i < 3; ++$i) {
            $polls[] = $this->ua->messageTaskStatus($task->messageTaskId);
        }
        self::assertSame([MessageTaskState::Pending, MessageTaskState::Finished, MessageTaskState::Finished], $polls);

        $items = $this->ua->messageTask($task->messageTaskId);
        self::assertCount(3, $items);
        self::assertTrue($items[0]->isAccepted());
        self::assertSame(SyntheticData::ACTIVE_RECIPIENT, $items[0]->recipientAddress);
        self::assertFalse($items[1]->isAccepted());
        self::assertSame('INVALID_RECIPIENT', $items[1]->error);
        self::assertSame(SyntheticData::INACTIVE_RECIPIENT, $items[1]->recipientAddress);
        self::assertFalse($items[2]->isAccepted());

        self::assertCount(1, $this->fake->sentMessages());
        self::assertSame($message->toPayload(SyntheticData::MAILBOX), $this->fake->sentMessages()[0]);
    }

    public function testInvalidSendsAreRejectedAndNotRecorded(): void
    {
        foreach ([
            '{}',
            'not json',
            '{"messageMetadata":{"to":[{"eDeliveryAddress":"' . SyntheticData::ACTIVE_RECIPIENT . '"}]}}',
            '{"messageMetadata":{"shippingService":"electronic"}}',
            '{"messageMetadata":{"shippingService":"hybrid","to":[{"eDeliveryAddress":"' . SyntheticData::ACTIVE_RECIPIENT . '"}]}}',
            '{"messageMetadata":{"shippingService":"electronic","from":{"eDeliveryAddress":"AE:PL-OTHER"},"to":[{"eDeliveryAddress":"' . SyntheticData::ACTIVE_RECIPIENT . '"}]}}',
            '{"textBody":"' . str_repeat('a', 5001) . '","messageMetadata":{"shippingService":"electronic","to":[{"eDeliveryAddress":"' . SyntheticData::ACTIVE_RECIPIENT . '"}]}}',
        ] as $body) {
            self::assertSame(400, $this->call('POST', 'messages', $body)->getStatusCode(), $body);
        }
        self::assertSame([], $this->fake->sentMessages());
    }

    public function testUnknownTaskIdsAreBadRequests(): void
    {
        try {
            $this->ua->messageTask('T-404');
            self::fail('Expected exception');
        } catch (BadRequestException $e) {
            self::assertTrue($e->hasErrorCode('INVALID_PARAMETER'));
        }

        $this->expectException(BadRequestException::class);
        $this->ua->messageTaskStatus('T-404');
    }

    public function testDraftLifecycle(): void
    {
        $saved = $this->ua->createDraft(OutgoingMessage::create('Wniosek')->withTextBody('Pierwsza wersja'));
        $id = $saved->messageId;

        self::assertSame([$id], array_map(static fn($m): string => $m->messageId, $this->ua->listDrafts()->messages));
        self::assertSame('Pierwsza wersja', $this->ua->getDraft($id)->textBody);

        $added = $this->ua->addDraftAttachment($id, OutgoingAttachment::fromString('abc', 'a.txt', 'text/plain'));
        $uploaded = $this->ua->uploadDraftAttachment($id, AttachmentUpload::fromStream(Stream::create('large file'), 'b.bin', 'application/octet-stream')->withDescription('Duży plik'));
        self::assertSame('b.bin', $uploaded->file?->fileName);
        self::assertSame(10, $uploaded->file->size);
        self::assertSame('Duży plik', $uploaded->file->description);

        $this->ua->updateDraft($id, OutgoingMessage::create('Wniosek')->withRecipients(SyntheticData::ACTIVE_RECIPIENT)->withTextBody('Druga wersja'));
        $draft = $this->ua->getDraft($id);
        self::assertSame('Druga wersja', $draft->textBody);
        self::assertSame(SyntheticData::ACTIVE_RECIPIENT, $draft->metadata->to[0]->deliveryAddress);
        self::assertSame(['a.txt', 'b.bin'], array_map(static fn($a): ?string => $a->file?->fileName, $draft->attachments), 'attachments survive an update without attachments');

        $content = $this->call('GET', 'drafts/' . $id . '/attachments/' . $added->attachmentId);
        self::assertSame(200, $content->getStatusCode());
        self::assertStringContainsString(base64_encode('abc'), (string) $content->getBody());

        self::assertSame('a.txt', $this->ua->deleteDraftAttachment($id, (string) $added->attachmentId)->fileName);
        self::assertCount(1, $this->ua->getDraft($id)->attachments);
        self::assertSame(['b.bin'], array_map(static fn($f): ?string => $f->fileName, $this->ua->deleteDraftAttachments($id)));
        self::assertSame([], $this->ua->getDraft($id)->attachments);

        $task = $this->ua->sendDraft($id);
        self::assertNotNull($task->messageTaskId);
        self::assertTrue($this->ua->messageTask($task->messageTaskId)[0]->isAccepted());
        self::assertCount(1, $this->fake->sentMessages());
        self::assertSame([], $this->ua->listDrafts()->messages, 'a sent draft is gone');
    }

    public function testDeleteDrafts(): void
    {
        $a = $this->ua->createDraft(OutgoingMessage::create('A'))->messageId;
        $b = $this->ua->createDraft(OutgoingMessage::create('B'))->messageId;

        try {
            $this->ua->deleteDrafts($a, 'D-404');
            self::fail('Expected exception');
        } catch (BadRequestException) {
        }
        self::assertCount(2, $this->ua->listDrafts()->messages, 'nothing deleted when one id is unknown');

        self::assertSame([$a, $b], $this->ua->deleteDrafts($a, $b));
        self::assertSame(0, $this->ua->listDrafts()->total);
    }

    public function testUnknownDraftsAndAttachmentsAreBadRequests(): void
    {
        $calls = [
            fn() => $this->ua->getDraft('D-404'),
            fn() => $this->ua->updateDraft('D-404', OutgoingMessage::create('S')),
            fn() => $this->ua->sendDraft('D-404'),
            fn() => $this->ua->addDraftAttachment('D-404', OutgoingAttachment::fromString('x', 'a.txt', 'text/plain')),
            fn() => $this->ua->uploadDraftAttachment('D-404', AttachmentUpload::fromStream(Stream::create('x'), 'a.txt', 'text/plain')),
            fn() => $this->ua->deleteDraftAttachments('D-404'),
            fn() => $this->ua->deleteDraftAttachment($this->ua->createDraft(OutgoingMessage::create('S'))->messageId, 'A-404'),
        ];
        $rejected = 0;
        foreach ($calls as $i => $call) {
            try {
                $call();
                self::fail('Expected exception for call ' . $i);
            } catch (BadRequestException $e) {
                self::assertTrue($e->hasErrorCode('INVALID_PARAMETER'));
                ++$rejected;
            }
        }
        self::assertSame(\count($calls), $rejected);
    }

    public function testMalformedDraftRequestsAreBadRequests(): void
    {
        $id = $this->ua->createDraft(OutgoingMessage::create('S'))->messageId;

        self::assertSame(400, $this->call('POST', 'drafts', '[]')->getStatusCode());
        self::assertSame(400, $this->call('POST', 'drafts', '{"messageMetadata":{}}')->getStatusCode());
        self::assertSame(400, $this->call('POST', 'drafts/' . $id . '/attachments', '{"file":"x"}')->getStatusCode());
        self::assertSame(400, $this->call('POST', 'drafts/' . $id . '/attachments', '{"fileMetadata":{"fileId":"F"},"file":"***"}')->getStatusCode());
        self::assertSame(400, $this->call('POST', $id . '/attachments/upload', 'x', 'text/plain')->getStatusCode());
        self::assertSame(400, $this->call('POST', $id . '/attachments/upload', "--b\r\nContent-Disposition: form-data; name=\"filename\"\r\n\r\na\r\n--b--\r\n", 'multipart/form-data; boundary=b')->getStatusCode());
        self::assertSame(400, $this->call('GET', 'drafts/' . $id . '/attachments/A-404')->getStatusCode());
        self::assertSame(405, $this->call('PATCH', 'drafts')->getStatusCode());
        self::assertSame(405, $this->call('PATCH', 'drafts/' . $id)->getStatusCode());
        self::assertSame(405, $this->call('GET', 'drafts/' . $id . '/attachments')->getStatusCode());
        self::assertSame(405, $this->call('PUT', 'drafts/' . $id . '/attachments/A')->getStatusCode());
    }

    public function testSendingRoutesCheckTokenAndMailbox(): void
    {
        $other = $this->fake->createClient('AE:PL-12345-67890-ABCDE-10')->userAgent();
        $id = $this->ua->createDraft(OutgoingMessage::create('S')->withRecipients(SyntheticData::ACTIVE_RECIPIENT))->messageId;

        $response = $this->fake->sendRequest(Http::factory()->createRequest('POST', self::UA . SyntheticData::MAILBOX . '/messages'));
        self::assertSame(401, $response->getStatusCode());

        $this->fake->acceptToken('other', 'AE:PL-12345-67890-ABCDE-10');
        $response = $this->fake->sendRequest(Http::factory()->createRequest('GET', self::UA . SyntheticData::MAILBOX . '/drafts')->withHeader('Authorization', 'Bearer other'));
        self::assertSame(403, $response->getStatusCode());

        self::assertSame([], $other->listDrafts()->messages, 'drafts belong to one mailbox');
        self::assertSame($id, $this->ua->listDrafts()->messages[0]->messageId);
        try {
            $other->getDraft($id);
            self::fail('Expected exception');
        } catch (BadRequestException) {
        }

        $task = $this->ua->sendDraft($id)->messageTaskId;
        self::assertNotNull($task);
        $this->expectException(BadRequestException::class);
        $other->messageTask($task);
    }

    public function testFailNextWorksForSending(): void
    {
        $this->fake->failNext('/messages', 503, [['error' => 'SERVICE_UNAVAILABLE', 'error_description' => 'x']]);

        try {
            $this->ua->sendMessage(OutgoingMessage::create('S')->withRecipients(SyntheticData::ACTIVE_RECIPIENT));
            self::fail('Expected exception');
        } catch (ServerException) {
        }
        self::assertSame([], $this->fake->sentMessages());
        self::assertNotNull($this->ua->sendMessage(OutgoingMessage::create('S')->withRecipients(SyntheticData::ACTIVE_RECIPIENT))->messageTaskId);
    }

    public function testExceptionsKeepTheirTypes(): void
    {
        $this->fake->failNext('/drafts/send', 403, [['error' => 'forbidden', 'error_description' => 'x']]);
        try {
            $this->ua->sendDraft('D');
            self::fail('Expected exception');
        } catch (AuthorizationException) {
        }

        $this->fake->failNext('/drafts', 401, [['error' => 'unauthorized', 'error_description' => 'x']]);
        $this->expectException(AuthenticationException::class);
        $this->ua->listDrafts();
    }

    private function call(string $method, string $path, string $body = '', string $contentType = 'application/json'): ResponseInterface
    {
        $this->fake->acceptToken('t', SyntheticData::MAILBOX);

        return $this->fake->sendRequest(
            Http::factory()->createRequest($method, self::UA . SyntheticData::MAILBOX . '/' . $path)
                ->withHeader('Authorization', 'Bearer t')
                ->withHeader('Content-Type', $contentType)
                ->withBody(Http::factory()->createStream($body)),
        );
    }
}
