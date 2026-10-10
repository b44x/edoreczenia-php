<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\Testing;

use B4x\Edoreczenia\Exception\BadRequestException;
use B4x\Edoreczenia\Testing\FakeEdoreczenia;
use B4x\Edoreczenia\Testing\SyntheticData;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\UserAgent\V3\Model\MessagePending;
use B4x\Edoreczenia\UserAgent\V3\Model\MessageSummary;
use B4x\Edoreczenia\UserAgent\V3\Model\OpenedMessage;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class FakeEdoreczeniaReceivingTest extends TestCase
{
    private const MAILBOX = 'AE:PL-12345-67890-ABCDE-12';
    private const UA = 'https://uaapi-int-ow.poczta-polska.pl/api/v3/';

    private Psr17Factory $factory;
    private FakeEdoreczenia $fake;

    protected function setUp(): void
    {
        $this->factory = Http::factory();
        $this->fake = new FakeEdoreczenia($this->factory, $this->factory);
    }

    public function testOpeningMarksTheMessageAsOpened(): void
    {
        $ua = $this->fake->createClient()->userAgent();
        self::assertFalse($this->listed(SyntheticData::MESSAGE_ID)?->opened);

        $message = $ua->openMessage(SyntheticData::MESSAGE_ID);

        self::assertInstanceOf(OpenedMessage::class, $message);
        self::assertTrue($message->metadata->opened);
        self::assertSame('Wezwanie', $message->metadata->subject);
        self::assertSame('Syntetyczna treść wiadomości. Synthetic message body.', $message->textBody);
        self::assertSame(['ATT-1', 'ATT-2'], array_map(static fn($a): ?string => $a->attachmentId, $message->attachments));
        self::assertFalse($message->attachments[0]->hasContent());
        self::assertSame(\strlen(SyntheticData::ATTACHMENT_FILES['ATT-1']), $message->attachments[0]->size);

        self::assertTrue($this->listed(SyntheticData::MESSAGE_ID)?->opened);
        self::assertTrue($ua->getThread(SyntheticData::THREAD_ID)->messages->messages[0]->opened);
    }

    public function testMetadataFormatDoesNotOpen(): void
    {
        $response = $this->fake->acceptToken('t', self::MAILBOX)->sendRequest(
            $this->factory->createRequest('GET', self::UA . self::MAILBOX . '/messages/' . SyntheticData::MESSAGE_ID . '?format=metadata')->withHeader('Authorization', 'Bearer t'),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 64, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data[0]);
        self::assertArrayNotHasKey('textBody', $data[0]);
        self::assertFalse($this->listed(SyntheticData::MESSAGE_ID)?->opened);
    }

    public function testExtendedFormatEmbedsTheContent(): void
    {
        $message = $this->fake->createClient()->userAgent()->openMessage(SyntheticData::MESSAGE_ID, extended: true);

        self::assertInstanceOf(OpenedMessage::class, $message);
        self::assertSame(SyntheticData::ATTACHMENT_FILES['ATT-2'], $message->attachment('ATT-2')?->decodeContent());
    }

    public function testPendingMessageAnswers202Once(): void
    {
        $ua = $this->fake->createClient()->userAgent();

        $first = $ua->openMessage(SyntheticData::PENDING_MESSAGE_ID);
        self::assertInstanceOf(MessagePending::class, $first);
        self::assertSame(SyntheticData::PENDING_MESSAGE_ID, $first->messageId);

        $second = $ua->openMessage(SyntheticData::PENDING_MESSAGE_ID);
        self::assertInstanceOf(OpenedMessage::class, $second);
        self::assertSame('Zawiadomienie', $second->metadata->subject);
        self::assertTrue($second->metadata->opened);
    }

    public function testDownloadAttachmentStreamsSyntheticBytes(): void
    {
        $download = $this->fake->createClient()->userAgent()->downloadAttachment(SyntheticData::MESSAGE_ID, 'ATT-1');

        self::assertSame('application/octet-stream', $download->contentType);
        self::assertSame('wezwanie.pdf', $download->fileName);
        self::assertSame(SyntheticData::ATTACHMENT_FILES['ATT-1'], (string) $download->stream);

        $requests = $this->fake->requests();
        self::assertSame('POST', array_pop($requests)?->getMethod());
    }

    public function testGetAttachmentReturnsBase64Content(): void
    {
        $attachment = $this->fake->createClient()->userAgent()->getAttachment(SyntheticData::MESSAGE_ID, 'ATT-2');

        self::assertSame('dane.xml', $attachment->fileName);
        self::assertSame(SyntheticData::ATTACHMENT_FILES['ATT-2'], $attachment->decodeContent());
    }

    public function testUnknownIdsAreRejected(): void
    {
        $ua = $this->fake->createClient()->userAgent();
        $rejected = 0;
        foreach ([fn() => $ua->openMessage('M-404'), fn() => $ua->downloadAttachment(SyntheticData::MESSAGE_ID, 'ATT-404'), fn() => $ua->downloadAttachment(SyntheticData::SENT_MESSAGE_ID, 'ATT-1'), fn() => $ua->getAttachment('M-404', 'ATT-1')] as $call) {
            try {
                $call();
            } catch (BadRequestException) {
                ++$rejected;
            }
        }

        self::assertSame(4, $rejected);
    }

    public function testReceivingRoutesRequireAKnownToken(): void
    {
        foreach ([
            $this->factory->createRequest('GET', self::UA . self::MAILBOX . '/messages/' . SyntheticData::MESSAGE_ID . '?format=full'),
            $this->factory->createRequest('GET', self::UA . self::MAILBOX . '/messages/' . SyntheticData::MESSAGE_ID . '/attachments/ATT-1')->withHeader('Authorization', 'Bearer unknown'),
            $this->factory->createRequest('POST', self::UA . self::MAILBOX . '/attachments/download'),
        ] as $request) {
            self::assertSame(401, $this->fake->sendRequest($request)->getStatusCode());
        }
        self::assertFalse($this->listed(SyntheticData::MESSAGE_ID)?->opened);
    }

    public function testTokenOfAnotherMailboxIsForbidden(): void
    {
        $this->fake->acceptToken('other', 'AE:PL-98765-43210-SFVYC-19');

        foreach (['GET' => '/messages/' . SyntheticData::MESSAGE_ID . '?format=full', 'POST' => '/attachments/download'] as $method => $path) {
            $response = $this->fake->sendRequest($this->factory->createRequest($method, self::UA . self::MAILBOX . $path)->withHeader('Authorization', 'Bearer other'));
            self::assertSame(403, $response->getStatusCode());
        }
        self::assertFalse($this->listed(SyntheticData::MESSAGE_ID)?->opened);
    }

    public function testOtherWriteRequestsAreStillRejected(): void
    {
        $response = $this->fake->acceptToken('t', self::MAILBOX)->sendRequest(
            $this->factory->createRequest('PATCH', self::UA . self::MAILBOX . '/settings')->withHeader('Authorization', 'Bearer t'),
        );

        self::assertSame(405, $response->getStatusCode());
    }

    private function listed(string $messageId): ?MessageSummary
    {
        foreach ($this->fake->createClient()->userAgent()->listMessages()->messages as $message) {
            if ($message->messageId === $messageId) {
                return $message;
            }
        }

        return null;
    }
}
