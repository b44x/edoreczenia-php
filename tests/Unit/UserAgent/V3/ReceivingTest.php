<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\UserAgent\V3;

use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\Exception\BadRequestException;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\Exception\ServerException;
use B4x\Edoreczenia\Exception\TransportException;
use B4x\Edoreczenia\Exception\UnexpectedResponseException;
use B4x\Edoreczenia\Http\RetryPolicy;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\Tests\Support\MockHttpClient;
use B4x\Edoreczenia\Tests\Support\NetworkError;
use B4x\Edoreczenia\Tests\Support\RecordingLogger;
use B4x\Edoreczenia\Tests\Support\SpyTokenProvider;
use B4x\Edoreczenia\UserAgent\V3\Enum\EvidenceType;
use B4x\Edoreczenia\UserAgent\V3\Enum\MessageStatus;
use B4x\Edoreczenia\UserAgent\V3\Enum\ShippingService;
use B4x\Edoreczenia\UserAgent\V3\Model\MessageAttachment;
use B4x\Edoreczenia\UserAgent\V3\Model\MessagePending;
use B4x\Edoreczenia\UserAgent\V3\Model\OpenedMessage;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class ReceivingTest extends TestCase
{
    private const MAILBOX = 'AE:PL-12345-67890-ABCDE-12';
    private const PREFIX = '/api/v3/' . self::MAILBOX . '/';
    private const MESSAGE_ID = 'D41568F4-7175-42BB-9503-DAA282180D70';

    private MockHttpClient $http;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->logger = new RecordingLogger();
    }

    public function testOpenMessageRequestsFullFormatAndMapsTheMessage(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/message.full.200.json'));

        $message = $this->client()->userAgent()->openMessage(self::MESSAGE_ID);

        $request = $this->http->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::PREFIX . 'messages/' . self::MESSAGE_ID, $request->getUri()->getPath());
        self::assertSame('format=full', $request->getUri()->getQuery());
        self::assertSame('application/json', $request->getHeaderLine('Accept'));

        self::assertInstanceOf(OpenedMessage::class, $message);
        self::assertSame(self::MESSAGE_ID, $message->messageId());
        self::assertSame('Wezwanie', $message->metadata->subject);
        self::assertSame('ZNAK-SPRAWY.1.2026', $message->metadata->caseId);
        self::assertTrue($message->metadata->opened);
        self::assertSame(MessageStatus::Delivered, $message->metadata->status);
        self::assertSame(ShippingService::Electronic, $message->metadata->shippingService);
        self::assertSame('MINISTERSTWO POCZT I TELEGRAFÓW', $message->metadata->from?->displayName());
        self::assertEquals(new \DateTimeImmutable('2017-07-22T08:00:00Z'), $message->metadata->receiptDate);
        self::assertSame(['sygnatura' => 'SYNTETYCZNA/1'], $message->metadata->additionalData);
        self::assertSame('Syntetyczna treść wiadomości. Synthetic message body.', $message->textBody);

        self::assertCount(2, $message->attachments);
        [$pdf, $xml] = $message->attachments;
        self::assertSame('ATT-1', $pdf->attachmentId);
        self::assertSame(1, $pdf->order);
        self::assertSame('F-1', $pdf->fileId);
        self::assertSame('wezwanie.pdf', $pdf->fileName);
        self::assertSame('application/pdf', $pdf->contentType);
        self::assertSame(18, $pdf->size);
        self::assertSame('SHA-3', $pdf->hashAlgorithm);
        self::assertSame('c3ludGhldGljLWhhc2g=', $pdf->hash);
        self::assertSame('Wezwanie', $pdf->description);
        self::assertFalse($pdf->hasContent(), '"full" lists attachments without content');
        self::assertNull($xml->size);
        self::assertSame('dane.xml', $xml->safeFileName());
        self::assertSame($xml, $message->attachment('ATT-2'));
        self::assertNull($message->attachment('ATT-404'));

        self::assertCount(1, $message->evidences);
        self::assertSame(EvidenceType::E1, $message->evidences[0]->type);
    }

    public function testExtendedFormatEmbedsAttachmentContent(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/message.fullExtended.200.json'));

        $message = $this->client()->userAgent()->openMessage(self::MESSAGE_ID, extended: true);

        self::assertSame('format=fullExtended', $this->http->lastRequest()->getUri()->getQuery());
        self::assertInstanceOf(OpenedMessage::class, $message);
        self::assertTrue($message->attachments[0]->hasContent());
        self::assertSame('%PDF-1.4 synthetic', $message->attachments[0]->decodeContent());
        self::assertSame([], $message->evidences);
    }

    public function testHttp202ReturnsPendingResult(): void
    {
        $this->http->queue(Http::fixture(202, 'user-agent-v3/message.202.json'));

        $result = $this->client()->userAgent()->openMessage(self::MESSAGE_ID);

        self::assertInstanceOf(MessagePending::class, $result);
        self::assertSame(self::MESSAGE_ID, $result->messageId);
        self::assertSame('PENDING', $result->status);
        self::assertCount(1, $this->http->requests);
    }

    public function testUnknownEnumValuesArePreserved(): void
    {
        $this->http->queue(Http::json(200, [[
            'messageControlData' => ['status' => 'Nowy status', 'messageType' => 'NewType'],
            'messageMetadata' => ['messageId' => 'M-1', 'shippingService' => 'quantum'],
        ]]));

        $message = $this->client()->userAgent()->openMessage('M-1');

        self::assertInstanceOf(OpenedMessage::class, $message);
        self::assertSame('Nowy status', $message->metadata->status);
        self::assertSame('NewType', $message->metadata->type);
        self::assertSame('quantum', $message->metadata->shippingService);
        self::assertNull($message->textBody);
        self::assertSame([], $message->attachments);
    }

    /**
     * @return iterable<string, array{ResponseInterface}>
     */
    public static function unexpectedOpenResponses(): iterable
    {
        yield 'empty array' => [Http::json(200, [])];
        yield 'two messages' => [Http::json(200, [['messageMetadata' => ['messageId' => 'a']], ['messageMetadata' => ['messageId' => 'b']]])];
        yield 'object instead of array' => [Http::json(200, ['messageMetadata' => ['messageId' => 'a']])];
        yield 'missing message id' => [Http::json(200, [['messageMetadata' => ['subject' => 'x']]])];
        yield 'pending without status' => [Http::json(202, ['state' => 'x'])];
        yield 'undocumented 2xx' => [Http::json(201, [])];
    }

    #[DataProvider('unexpectedOpenResponses')]
    public function testUnexpectedOpenResponsesAreRejected(ResponseInterface $response): void
    {
        $this->http->queue($response);

        $this->expectException(UnexpectedResponseException::class);
        $this->client()->userAgent()->openMessage(self::MESSAGE_ID);
    }

    public function testOpeningIsNeverRetriedOnServerErrors(): void
    {
        $this->http->queue(new Response(503, ['Retry-After' => '0']));

        try {
            $this->retryingClient()->userAgent()->openMessage(self::MESSAGE_ID);
            self::fail('Expected exception');
        } catch (ServerException) {
        }

        self::assertCount(1, $this->http->requests);
    }

    public function testOpeningIsNeverRetriedOnNetworkErrors(): void
    {
        $this->http->queue(new NetworkError(new Request('GET', 'https://x.test')));

        try {
            $this->retryingClient()->userAgent()->openMessage(self::MESSAGE_ID);
            self::fail('Expected exception');
        } catch (TransportException $e) {
            self::assertTrue($e->isNetworkError());
        }

        self::assertCount(1, $this->http->requests);
    }

    public function testOpenErrorsAreMapped(): void
    {
        $this->http->queue(Http::fixture(400, 'user-agent-v3/error.400.json'));

        $this->expectException(BadRequestException::class);
        $this->client()->userAgent()->openMessage(self::MESSAGE_ID);
    }

    public function testIdsArePercentEncodedAsPathSegments(): void
    {
        $this->http->queue(Http::fixture(202, 'user-agent-v3/message.202.json'), Http::fixture(200, 'user-agent-v3/attachment.200.json'));
        $ua = $this->client()->userAgent();

        $ua->openMessage('a/b?c#d');
        self::assertSame(self::PREFIX . 'messages/a%2Fb%3Fc%23d', $this->http->lastRequest()->getUri()->getPath());

        $ua->getAttachment('a/b', '../x');
        self::assertSame(self::PREFIX . 'messages/a%2Fb/attachments/..%2Fx', $this->http->lastRequest()->getUri()->getPath());
    }

    public function testIdsAreValidatedLocally(): void
    {
        $ua = $this->client()->userAgent();
        $calls = [
            fn() => $ua->openMessage(''),
            fn() => $ua->openMessage('a b'),
            fn() => $ua->openMessage('M-1,M-2'),
            fn() => $ua->downloadAttachment('', 'ATT-1'),
            fn() => $ua->downloadAttachment('M-1', ' '),
            fn() => $ua->getAttachment('M-1', ''),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('Expected exception');
            } catch (InvalidArgumentException) {
            }
        }

        self::assertSame([], $this->http->requests);
    }

    public function testDownloadAttachmentPostsIdsAndStreamsTheFile(): void
    {
        $this->http->queue(new Response(200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => '18',
            'Content-Disposition' => 'attachment; filename="../../wezwanie.pdf"',
        ], '%PDF-1.4 synthetic'));

        $download = $this->client()->userAgent()->downloadAttachment(self::MESSAGE_ID, 'ATT-1');

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::PREFIX . 'attachments/download', $request->getUri()->getPath());
        self::assertSame('', $request->getUri()->getQuery());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('*/*', $request->getHeaderLine('Accept'));
        self::assertSame(['messageId' => self::MESSAGE_ID, 'attachmentId' => 'ATT-1'], Http::jsonBody($request));

        self::assertSame('application/octet-stream', $download->contentType);
        self::assertSame('wezwanie.pdf', $download->fileName);
        self::assertSame(18, $download->size);

        $path = tempnam(sys_get_temp_dir(), 'att');
        self::assertIsString($path);
        try {
            self::assertSame(18, $download->saveTo($path));
            self::assertSame('%PDF-1.4 synthetic', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function testDownloadAttachmentIsRetriedOnNetworkErrors(): void
    {
        $this->http->queue(new NetworkError(new Request('POST', 'https://x.test')), new Response(200, ['Content-Type' => 'application/octet-stream'], 'ok'));

        self::assertSame('ok', (string) $this->retryingClient()->userAgent()->downloadAttachment(self::MESSAGE_ID, 'ATT-1')->stream);
        self::assertCount(2, $this->http->requests);
        self::assertSame(['messageId' => self::MESSAGE_ID, 'attachmentId' => 'ATT-1'], Http::jsonBody($this->http->requests[1]), 'body is resent on retry');
    }

    public function testDownloadAttachmentErrorsAreMapped(): void
    {
        $this->http->queue(Http::fixture(400, 'user-agent-v3/error.400.json'));

        $this->expectException(BadRequestException::class);
        $this->client()->userAgent()->downloadAttachment(self::MESSAGE_ID, 'ATT-1');
    }

    public function testGetAttachmentReturnsInlineContent(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/attachment.200.json'));

        $attachment = $this->client()->userAgent()->getAttachment(self::MESSAGE_ID, 'ATT-1');

        self::assertSame(self::PREFIX . 'messages/' . self::MESSAGE_ID . '/attachments/ATT-1', $this->http->lastRequest()->getUri()->getPath());
        self::assertSame('GET', $this->http->lastRequest()->getMethod());
        self::assertSame('ATT-1', $attachment->attachmentId);
        self::assertSame('wezwanie.pdf', $attachment->safeFileName());
        self::assertSame('%PDF-1.4 synthetic', $attachment->decodeContent());
    }

    public function testDecodeContentRejectsInvalidBase64(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        (new MessageAttachment('ATT-1', encodedContent: '***'))->decodeContent();
    }

    public function testDecodeContentWithoutContentIsALogicError(): void
    {
        $this->expectException(\LogicException::class);
        (new MessageAttachment('ATT-1'))->decodeContent();
    }

    public function testLineWrappedBase64IsAccepted(): void
    {
        self::assertSame('%PDF-1.4 synthetic', (new MessageAttachment('ATT-1', encodedContent: "JVBERi0xLjQg\r\nc3ludGhldGlj"))->decodeContent());
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function fileNames(): iterable
    {
        yield 'plain' => ['pismo.pdf', 'pismo.pdf'];
        yield 'traversal' => ['../../etc/passwd', 'passwd'];
        yield 'windows path' => ['C:\\temp\\x.pdf', 'x.pdf'];
        yield 'control characters' => ["a\x00b\x1F.pdf", 'ab.pdf'];
        yield 'dot dot' => ['..', null];
        yield 'absent' => [null, null];
    }

    #[DataProvider('fileNames')]
    public function testSafeFileName(?string $name, ?string $expected): void
    {
        self::assertSame($expected, (new MessageAttachment('ATT-1', fileName: $name))->safeFileName());
    }

    public function testLogsMaskTheMailboxAndNeverContainBodies(): void
    {
        $this->http->queue(
            Http::fixture(200, 'user-agent-v3/message.full.200.json'),
            new Response(200, ['Content-Type' => 'application/octet-stream'], 'secret-bytes'),
        );
        $ua = $this->client()->userAgent();

        $ua->openMessage(self::MESSAGE_ID);
        $ua->downloadAttachment(self::MESSAGE_ID, 'ATT-1')->stream->getContents();

        $dump = $this->logger->dump();
        self::assertStringNotContainsString(self::MAILBOX, $dump);
        self::assertStringNotContainsString('Synthetic message body', $dump);
        self::assertStringNotContainsString('MALINOWSKI', $dump);
        self::assertStringNotContainsString('secret-bytes', $dump);
        self::assertStringNotContainsString('format=', $dump);
        self::assertStringContainsString('{masked}/messages/' . self::MESSAGE_ID, $dump);
        self::assertStringContainsString('{masked}/attachments/download', $dump);
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
