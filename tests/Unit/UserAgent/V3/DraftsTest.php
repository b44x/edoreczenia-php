<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\UserAgent\V3;

use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\Exception\ServerException;
use B4x\Edoreczenia\Exception\UnexpectedResponseException;
use B4x\Edoreczenia\Http\RetryPolicy;
use B4x\Edoreczenia\Internal\MultipartStream;
use B4x\Edoreczenia\Tests\Support\ChunkedStream;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\Tests\Support\MockHttpClient;
use B4x\Edoreczenia\Tests\Support\SpyTokenProvider;
use B4x\Edoreczenia\UserAgent\V3\Enum\DraftSortColumn;
use B4x\Edoreczenia\UserAgent\V3\Enum\ShippingService;
use B4x\Edoreczenia\UserAgent\V3\Request\AttachmentUpload;
use B4x\Edoreczenia\UserAgent\V3\Request\DraftQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingAttachment;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingMessage;
use B4x\Edoreczenia\UserAgent\V3\UserAgentClient;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class DraftsTest extends TestCase
{
    private const MAILBOX = 'AE:PL-12345-67890-ABCDE-12';
    private const BASE = 'https://uaapi-int-ow.poczta-polska.pl/api/v3/';
    private const DRAFT = '0A1B2C3D-0000-4000-8000-0000000000D1';

    private MockHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
    }

    public function testListDrafts(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/drafts.200.json'), Http::fixture(200, 'user-agent-v3/drafts.200.json'));

        $list = $this->ua()->listDrafts();
        self::assertSame(self::BASE . self::MAILBOX . '/drafts', (string) $this->http->lastRequest()->getUri());
        self::assertSame(1, $list->total);
        self::assertSame(self::DRAFT, $list->messages[0]->messageId);
        self::assertSame('Wniosek', $list->messages[0]->subject);
        self::assertSame(ShippingService::Electronic, $list->messages[0]->shippingService);

        $this->ua()->listDrafts(DraftQuery::create()->withPagination(0, 5)->sortBy(DraftSortColumn::Subject));
        parse_str($this->http->lastRequest()->getUri()->getQuery(), $query);
        self::assertSame(['offset' => '0', 'limit' => '5', 'sortColumn' => 'subject', 'sortDirection' => 'desc'], $query);
    }

    public function testGetDraft(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/draft.200.json'));

        $draft = $this->ua()->getDraft(self::DRAFT);

        $request = $this->http->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::BASE . self::MAILBOX . '/drafts/' . self::DRAFT, (string) $request->getUri());
        self::assertSame(self::DRAFT, $draft->messageId());
        self::assertSame('Treść wiadomości roboczej', $draft->textBody);
        self::assertSame('ZNAK-SPRAWY.1.2026', $draft->metadata->caseId);
        self::assertSame('AE:PL-98765-43210-SFVYC-19', $draft->metadata->to[0]->deliveryAddress);
        self::assertCount(1, $draft->attachments);
        self::assertSame(1, $draft->attachments[0]->order);
        self::assertSame('D41568F4-7175-42BB-9503-DAA282180D70', $draft->attachments[0]->attachmentId);
        self::assertSame('document.pdf', $draft->attachments[0]->file?->fileName);
        self::assertSame(1024, $draft->attachments[0]->file->size);
    }

    public function testCreateAndUpdateDraft(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/draft-saved.200.json'), Http::json(200, ['messageId' => self::DRAFT, 'warning' => 'Prawie pełna']));
        $message = OutgoingMessage::create('Wniosek');

        $created = $this->ua()->createDraft($message);
        self::assertSame(self::DRAFT, $created->messageId);
        self::assertNull($created->warning);
        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE . self::MAILBOX . '/drafts', (string) $request->getUri());
        self::assertSame($message->toPayload(self::MAILBOX), Http::jsonBody($request), 'a draft may have no recipients yet');

        $updated = $this->ua()->updateDraft(self::DRAFT, $message->withRecipients('AE:PL-98765-43210-SFVYC-19'));
        self::assertSame('Prawie pełna', $updated->warning);
        $request = $this->http->lastRequest();
        self::assertSame('PUT', $request->getMethod());
        self::assertSame(self::BASE . self::MAILBOX . '/drafts/' . self::DRAFT, (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $metadata = Http::jsonBody($request)['messageMetadata'] ?? null;
        self::assertIsArray($metadata);
        self::assertSame([['eDeliveryAddress' => 'AE:PL-98765-43210-SFVYC-19']], $metadata['to'] ?? null);
    }

    public function testSavedDraftRequiresMessageId(): void
    {
        $this->http->queue(Http::json(200, ['warning' => 'x']));

        $this->expectException(UnexpectedResponseException::class);
        $this->ua()->createDraft(OutgoingMessage::create('Wniosek'));
    }

    public function testDeleteDraftsJoinsIdsWithCommas(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/drafts-deleted.200.json'));

        $deleted = $this->ua()->deleteDrafts(self::DRAFT, '0A1B2C3D-0000-4000-8000-0000000000D2');

        $request = $this->http->lastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame(self::BASE . self::MAILBOX . '/drafts/' . self::DRAFT . ',0A1B2C3D-0000-4000-8000-0000000000D2', (string) $request->getUri());
        self::assertSame('', (string) $request->getBody());
        self::assertFalse($request->hasHeader('Content-Type'));
        self::assertSame([self::DRAFT, '0A1B2C3D-0000-4000-8000-0000000000D2'], $deleted);
    }

    public function testDeleteDraftsLimitsAndValidatesIds(): void
    {
        $tooMany = array_map(static fn(int $i): string => 'D-' . $i, range(1, UserAgentClient::MAX_DELETED_DRAFTS + 1));
        foreach ([$tooMany, ['a,b'], [''], ['D-1', 'a b']] as $ids) {
            try {
                $this->ua()->deleteDrafts(...$ids);
                self::fail('Expected exception');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->http->requests);

        $this->http->queue(Http::json(200, array_map(static fn(string $id): array => ['messageId' => $id], \array_slice($tooMany, 0, 50))));
        self::assertCount(50, $this->ua()->deleteDrafts(...\array_slice($tooMany, 0, 50)));
    }

    public function testAddDraftAttachmentSendsFileData(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/draft-attachment.200.json'));
        $attachment = OutgoingAttachment::fromString('%PDF', 'document.pdf', 'application/pdf');

        $uploaded = $this->ua()->addDraftAttachment(self::DRAFT, $attachment);

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE . self::MAILBOX . '/drafts/' . self::DRAFT . '/attachments', (string) $request->getUri());
        self::assertSame($attachment->toFileData(), Http::jsonBody($request));
        self::assertSame('D41568F4-7175-42BB-9503-DAA282180D70', $uploaded->attachmentId);
        self::assertSame('application/pdf', $uploaded->file?->contentType);
        self::assertNull($uploaded->warning);
    }

    public function testDeleteDraftAttachments(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/draft-attachment-deleted.200.json'), Http::json(200, [json_decode(Http::fixtureBody('user-agent-v3/draft-attachment-deleted.200.json'), true)]));

        $file = $this->ua()->deleteDraftAttachment(self::DRAFT, 'A-1');
        self::assertSame('DELETE', $this->http->lastRequest()->getMethod());
        self::assertSame(self::BASE . self::MAILBOX . '/drafts/' . self::DRAFT . '/attachments/A-1', (string) $this->http->lastRequest()->getUri());
        self::assertSame('D41568F4-7175-42BB-9503-DAA282180D70', $file->fileId);
        self::assertSame('SHA-3', $file->alg);
        self::assertSame('Text document', $file->description);
        self::assertNotNull($file->hash);

        $files = $this->ua()->deleteDraftAttachments(self::DRAFT);
        self::assertSame(self::BASE . self::MAILBOX . '/drafts/' . self::DRAFT . '/attachments', (string) $this->http->lastRequest()->getUri());
        self::assertSame('document.pdf', $files[0]->fileName);
    }

    public function testDraftChangesAreNeverRetried(): void
    {
        $ua = (new EdoreczeniaClient(Environment::integration(), self::MAILBOX, new SpyTokenProvider(), $this->http, Http::factory(), Http::factory(), null, RetryPolicy::exponential(3, 0, 0)))->userAgent();
        $calls = [
            static fn() => $ua->createDraft(OutgoingMessage::create('S')),
            static fn() => $ua->updateDraft('D', OutgoingMessage::create('S')),
            static fn() => $ua->deleteDrafts('D'),
            static fn() => $ua->addDraftAttachment('D', OutgoingAttachment::fromString('x', 'a.txt', 'text/plain')),
            static fn() => $ua->uploadDraftAttachment('D', AttachmentUpload::fromStream(Stream::create('x'), 'a.txt', 'text/plain')),
            static fn() => $ua->deleteDraftAttachment('D', 'A'),
            static fn() => $ua->deleteDraftAttachments('D'),
        ];

        foreach ($calls as $i => $call) {
            $this->http->queue(Http::json(503, []));
            try {
                $call();
                self::fail('Expected exception');
            } catch (ServerException) {
            }
            self::assertCount($i + 1, $this->http->requests, 'one request per call');
        }

        // Reading drafts is retried.
        $this->http->queue(Http::json(503, []), Http::fixture(200, 'user-agent-v3/draft.200.json'));
        self::assertSame(self::DRAFT, $ua->getDraft(self::DRAFT)->messageId());
    }

    public function testUploadDraftAttachmentStreamsMultipartBody(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/draft-attachment.200.json'));
        $content = random_bytes(300_001);

        $uploaded = $this->ua()->uploadDraftAttachment(
            self::DRAFT,
            AttachmentUpload::fromStream(Stream::create($content), 'akta "2026".zip', 'application/zip')->withDescription('Akta sprawy'),
        );

        self::assertSame('D41568F4-7175-42BB-9503-DAA282180D70', $uploaded->attachmentId);
        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE . self::MAILBOX . '/' . self::DRAFT . '/attachments/upload', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertInstanceOf(MultipartStream::class, $request->getBody());

        $fields = self::multipartFields($request);
        self::assertSame(['filename', 'contentType', 'size', 'description', 'file'], array_keys($fields));
        self::assertSame('akta "2026".zip', $fields['filename']['value']);
        self::assertSame('application/zip', $fields['contentType']['value']);
        self::assertSame('300001', $fields['size']['value']);
        self::assertSame('Akta sprawy', $fields['description']['value']);
        self::assertSame($content, $fields['file']['value']);
        self::assertStringContainsString('filename="akta %222026%22.zip"', $fields['file']['headers']);
        self::assertStringContainsString('Content-Type: application/zip', $fields['file']['headers']);
    }

    public function testUploadNeverBuffersTheFile(): void
    {
        $content = str_repeat('0123456789', 200_000);
        $stream = new ChunkedStream($content, 8192);
        $client = new class implements \Psr\Http\Client\ClientInterface {
            public int $bytes = 0;
            public string $tail = '';

            public function sendRequest(RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                // Reads the body the way HTTP clients send it: chunk by chunk until eof.
                $body = $request->getBody();
                while (!$body->eof()) {
                    $chunk = $body->read(16384);
                    $this->bytes += \strlen($chunk);
                    $this->tail = substr($this->tail . $chunk, -64);
                }

                return Http::fixture(200, 'user-agent-v3/draft-attachment.200.json');
            }
        };
        $ua = (new EdoreczeniaClient(Environment::integration(), self::MAILBOX, new SpyTokenProvider(), $client, Http::factory(), Http::factory()))->userAgent();

        $upload = AttachmentUpload::fromStream($stream, 'big.bin', 'application/octet-stream');
        self::assertNull($upload->size());
        $ua->uploadDraftAttachment(self::DRAFT, $upload);

        self::assertSame(\strlen($content), $stream->tell(), 'the whole file was sent');
        self::assertLessThanOrEqual(8192, $stream->largestRead);
        self::assertGreaterThan(\strlen($content), $client->bytes);
        self::assertMatchesRegularExpression('/\r\n--b4x-[0-9a-f]{32}--\r\n$/', $client->tail);
    }

    public function testMultipartStreamBehavesAsAReadOnlyStream(): void
    {
        $file = Stream::create('FILE');
        $stream = new MultipartStream(['head-', $file, '-tail']);

        self::assertSame(14, $stream->getSize());
        self::assertTrue($stream->isSeekable());
        self::assertTrue($stream->isReadable());
        self::assertFalse($stream->isWritable());
        self::assertSame('head-', $stream->read(100));
        self::assertSame('FI', $stream->read(2));
        self::assertSame(7, $stream->tell());
        self::assertSame('LE-tail', $stream->getContents());
        self::assertTrue($stream->eof());
        self::assertSame('', $stream->read(10));
        self::assertSame('head-FILE-tail', (string) $stream);
        $stream->rewind();
        self::assertSame(0, $stream->tell());
        self::assertSame('he', $stream->read(2));
        self::assertSame('', $stream->read(0));
        self::assertSame([], $stream->getMetadata());
        self::assertNull($stream->getMetadata('uri'));

        try {
            $stream->seek(3);
            self::fail('Expected exception');
        } catch (\RuntimeException) {
        }
        try {
            $stream->write('x');
            self::fail('Expected exception');
        } catch (\RuntimeException) {
        }

        $chunked = new ChunkedStream('abc', 1);
        $unseekable = new MultipartStream(['<', $chunked, '>']);
        self::assertNull($unseekable->getSize());
        self::assertFalse($unseekable->isSeekable());
        self::assertSame('<abc>', (string) $unseekable, 'read through the part streams, never their getContents()');
        self::assertSame('', (string) $unseekable, 'cannot be rewound');
        self::assertNull($unseekable->detach());
        self::assertTrue($chunked->closed);
        self::assertTrue($unseekable->eof());
    }

    public function testUploadValidation(): void
    {
        foreach ([
            static fn() => AttachmentUpload::fromStream(Stream::create('x'), 'a/b.zip', 'application/zip'),
            static fn() => AttachmentUpload::fromStream(Stream::create('x'), 'a.zip', 'zip'),
            static fn() => AttachmentUpload::fromStream(Stream::create('x'), 'a.zip', 'application/zip')->withDescription(' '),
            fn() => $this->ua()->uploadDraftAttachment('', AttachmentUpload::fromStream(Stream::create('x'), 'a.zip', 'application/zip')),
        ] as $build) {
            try {
                $build();
                self::fail('Expected exception');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->http->requests);

        $upload = AttachmentUpload::fromStream(Stream::create('xyz'), 'a.zip', 'application/zip');
        self::assertSame('a.zip', $upload->fileName());
        self::assertSame('application/zip', $upload->contentType());
        self::assertNull($upload->description());
        self::assertSame(3, $upload->size());
    }

    /**
     * @return array<string, array{headers: string, value: string}>
     */
    private static function multipartFields(RequestInterface $request): array
    {
        self::assertSame(1, preg_match('/^multipart\/form-data; boundary=(b4x-[0-9a-f]{32})$/', $request->getHeaderLine('Content-Type'), $m));
        $body = (string) $request->getBody();
        self::assertStringEndsWith("\r\n--{$m[1]}--\r\n", $body);

        $fields = [];
        foreach (\array_slice(explode('--' . $m[1] . "\r\n", substr($body, 0, -\strlen("\r\n--{$m[1]}--\r\n"))), 1) as $part) {
            [$headers, $value] = explode("\r\n\r\n", $part, 2);
            self::assertSame(1, preg_match('/name="([^"]+)"/', $headers, $name));
            $fields[$name[1]] = ['headers' => $headers, 'value' => str_ends_with($value, "\r\n") && $name[1] !== 'file' ? substr($value, 0, -2) : $value];
        }

        return $fields;
    }

    private function ua(): UserAgentClient
    {
        return (new EdoreczeniaClient(Environment::integration(), self::MAILBOX, new SpyTokenProvider(), $this->http, Http::factory(), Http::factory()))->userAgent();
    }
}
