<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\UserAgent\V3;

use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\Tests\Support\ChunkedStream;
use B4x\Edoreczenia\UserAgent\V3\Enum\DraftSortColumn;
use B4x\Edoreczenia\UserAgent\V3\Enum\ShippingService;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortDirection;
use B4x\Edoreczenia\UserAgent\V3\Request\DraftQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingAttachment;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingMessage;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutgoingMessageTest extends TestCase
{
    private const SENDER = 'AE:PL-12345-67890-ABCDE-12';
    private const RECIPIENT = 'AE:PL-98765-43210-SFVYC-19';

    public function testMinimalPayload(): void
    {
        $payload = OutgoingMessage::create('Wniosek')->withRecipients(self::RECIPIENT)->toPayload(self::SENDER);

        self::assertSame([
            'messageMetadata' => [
                'from' => ['eDeliveryAddress' => self::SENDER],
                'to' => [['eDeliveryAddress' => self::RECIPIENT]],
                'subject' => 'Wniosek',
                'shippingService' => 'electronic',
            ],
        ], $payload);
    }

    public function testFullPayload(): void
    {
        $attachment = OutgoingAttachment::fromString('%PDF-1.4 synthetic', 'pismo.pdf', 'application/pdf')
            ->withDescription('Pismo przewodnie')
            ->withFileId('F-1');
        $message = OutgoingMessage::create('Odpowiedź na wezwanie')
            ->withRecipients(self::RECIPIENT, 'AE:PL-12345-67890-ABCDE-10')
            ->withTextBody('Treść')
            ->inThread('T-1')
            ->referringTo('D41568F4-7175-42BB-9503-DAA282180D70')
            ->withCaseId('ZNAK.1.2026')
            ->withAdditionalData('ref', 'abc')
            ->withAttachment($attachment)
            ->withAttachment(OutgoingAttachment::fromString('a,b', 'dane.csv', 'text/csv')->withFileId('F-2'));

        self::assertSame([
            'messageMetadata' => [
                'from' => ['eDeliveryAddress' => self::SENDER],
                'to' => [['eDeliveryAddress' => self::RECIPIENT], ['eDeliveryAddress' => 'AE:PL-12345-67890-ABCDE-10']],
                'subject' => 'Odpowiedź na wezwanie',
                'threadId' => 'T-1',
                'refToMessageId' => 'D41568F4-7175-42BB-9503-DAA282180D70',
                'caseId' => 'ZNAK.1.2026',
                'shippingService' => 'electronic',
                'additionalData' => ['ref' => 'abc'],
            ],
            'textBody' => 'Treść',
            'attachments' => [
                ['order' => 1, 'file' => [
                    'fileMetadata' => ['fileId' => 'F-1', 'filename' => 'pismo.pdf', 'contentType' => 'application/pdf', 'size' => 18, 'description' => 'Pismo przewodnie'],
                    'file' => base64_encode('%PDF-1.4 synthetic'),
                ]],
                ['order' => 2, 'file' => [
                    'fileMetadata' => ['fileId' => 'F-2', 'filename' => 'dane.csv', 'contentType' => 'text/csv', 'size' => 3],
                    'file' => base64_encode('a,b'),
                ]],
            ],
        ], $message->toPayload(self::SENDER));
        self::assertSame([self::RECIPIENT, 'AE:PL-12345-67890-ABCDE-10'], $message->recipients());
        self::assertSame(['ref' => 'abc'], $message->additionalData());
        self::assertSame('T-1', $message->threadId());
        self::assertSame('ZNAK.1.2026', $message->caseId());
        self::assertSame('Treść', $message->textBody());
        self::assertSame('D41568F4-7175-42BB-9503-DAA282180D70', $message->refToMessageId());
        self::assertCount(2, $message->attachments());
    }

    public function testBuilderIsImmutable(): void
    {
        $base = OutgoingMessage::create('Wniosek');
        $withRecipient = $base->withRecipients(self::RECIPIENT);
        $withText = $withRecipient->withTextBody('x');
        $withAttachment = $withText->withAttachment(OutgoingAttachment::fromString('x', 'a.txt', 'text/plain'));

        self::assertSame([], $base->recipients());
        self::assertNull($withRecipient->textBody());
        self::assertSame([], $withText->attachments());
        self::assertCount(1, $withAttachment->attachments());
        self::assertSame('Wniosek', $withAttachment->subject());
    }

    public function testTextBodyLimitCountsCharactersNotBytes(): void
    {
        $max = str_repeat('ż', OutgoingMessage::MAX_TEXT_BODY_LENGTH);
        self::assertSame($max, OutgoingMessage::create('S')->withTextBody($max)->textBody());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 5000 characters');
        OutgoingMessage::create('S')->withTextBody($max . 'a');
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function invalidMessages(): iterable
    {
        yield 'blank subject' => [static fn(): OutgoingMessage => OutgoingMessage::create('  '), 'subject must not be empty'];
        yield 'subject not UTF-8' => [static fn(): OutgoingMessage => OutgoingMessage::create("\xC3\x28"), 'subject must be valid UTF-8'];
        yield 'blank body' => [static fn(): OutgoingMessage => OutgoingMessage::create('S')->withTextBody(''), 'textBody must not be empty'];
        yield 'recipient with space' => [static fn(): OutgoingMessage => OutgoingMessage::create('S')->withRecipients('AE:PL 1'), 'Recipient address'];
        yield 'empty recipient' => [static fn(): OutgoingMessage => OutgoingMessage::create('S')->withRecipients(''), 'Recipient address'];
        yield 'duplicate in one call' => [static fn(): OutgoingMessage => OutgoingMessage::create('S')->withRecipients(self::RECIPIENT, self::RECIPIENT), 'only once'];
        yield 'duplicate across calls' => [static fn(): OutgoingMessage => OutgoingMessage::create('S')->withRecipients(self::RECIPIENT)->withRecipients(self::RECIPIENT), 'only once'];
        yield 'thread id' => [static fn(): OutgoingMessage => OutgoingMessage::create('S')->inThread(''), 'threadId'];
        yield 'reference' => [static fn(): OutgoingMessage => OutgoingMessage::create('S')->referringTo('a b'), 'refToMessageId'];
        yield 'case id' => [static fn(): OutgoingMessage => OutgoingMessage::create('S')->withCaseId(' '), 'caseId'];
        yield 'additional data key' => [static fn(): OutgoingMessage => OutgoingMessage::create('S')->withAdditionalData('', 'v'), 'additionalData key'];
        yield 'additional data value' => [static fn(): OutgoingMessage => OutgoingMessage::create('S')->withAdditionalData('k', ''), 'additionalData value'];
        yield 'empty attachment' => [static fn(): OutgoingAttachment => OutgoingAttachment::fromString('', 'a.txt', 'text/plain'), 'must not be empty'];
        yield 'file name with directory' => [static fn(): OutgoingAttachment => OutgoingAttachment::fromString('x', '../a.txt', 'text/plain'), 'plain file name'];
        yield 'file name with backslash' => [static fn(): OutgoingAttachment => OutgoingAttachment::fromString('x', 'C:\a.txt', 'text/plain'), 'plain file name'];
        yield 'file name with newline' => [static fn(): OutgoingAttachment => OutgoingAttachment::fromString('x', "a\n.txt", 'text/plain'), 'plain file name'];
        yield 'file name dot dot' => [static fn(): OutgoingAttachment => OutgoingAttachment::fromString('x', '..', 'text/plain'), 'plain file name'];
        yield 'blank file name' => [static fn(): OutgoingAttachment => OutgoingAttachment::fromString('x', ' ', 'text/plain'), 'fileName must not be empty'];
        yield 'content type' => [static fn(): OutgoingAttachment => OutgoingAttachment::fromString('x', 'a.txt', 'text'), 'contentType'];
        yield 'content type with parameter' => [static fn(): OutgoingAttachment => OutgoingAttachment::fromString('x', 'a.txt', 'text/plain; charset=UTF-8'), 'contentType'];
        yield 'description' => [static fn(): OutgoingAttachment => OutgoingAttachment::fromString('x', 'a.txt', 'text/plain')->withDescription(''), 'description'];
        yield 'file id' => [static fn(): OutgoingAttachment => OutgoingAttachment::fromString('x', 'a.txt', 'text/plain')->withFileId(' '), 'fileId'];
        yield 'stream file name' => [static fn(): OutgoingAttachment => OutgoingAttachment::fromStream(Stream::create('x'), 'a/b', 'text/plain'), 'plain file name'];
        yield 'stream content type' => [static fn(): OutgoingAttachment => OutgoingAttachment::fromStream(Stream::create('x'), 'a.txt', '*/*'), 'contentType'];
        yield 'empty stream' => [static fn(): OutgoingAttachment => OutgoingAttachment::fromStream(Stream::create(''), 'a.txt', 'text/plain'), 'must not be empty'];
    }

    /**
     * @param \Closure(): mixed $build
     */
    #[DataProvider('invalidMessages')]
    public function testInvalidInputIsRejectedLocally(\Closure $build, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $build();
    }

    public function testAttachmentFromStreamEqualsAttachmentFromString(): void
    {
        $content = random_bytes(500_000);
        $stream = Stream::create($content);
        $stream->seek(1000); // a seekable stream is read from its start

        $fromStream = OutgoingAttachment::fromStream($stream, 'dane.bin', 'application/octet-stream')->withFileId('F');
        $fromString = OutgoingAttachment::fromString($content, 'dane.bin', 'application/octet-stream')->withFileId('F');

        self::assertSame($fromString->toFileData(), $fromStream->toFileData());
        self::assertSame(500_000, $fromStream->size());
        self::assertSame($content, base64_decode($fromStream->toFileData()['file'], true));
    }

    public function testAttachmentFromNonSeekableStreamWithShortReads(): void
    {
        $content = random_bytes(200_003);
        $stream = new ChunkedStream($content, 1000);

        $attachment = OutgoingAttachment::fromStream($stream, 'dane.bin', 'application/octet-stream');

        self::assertSame(base64_encode($content), $attachment->toFileData()['file']);
        self::assertSame(200_003, $attachment->size());
    }

    public function testAttachmentSizeLimit(): void
    {
        $max = str_repeat('a', OutgoingAttachment::MAX_INLINE_BYTES);
        self::assertSame(OutgoingAttachment::MAX_INLINE_BYTES, OutgoingAttachment::fromString($max, 'a.txt', 'text/plain')->size());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('AttachmentUpload');
        OutgoingAttachment::fromString($max . 'a', 'a.txt', 'text/plain');
    }

    public function testOversizedStreamWithKnownSizeIsRejectedBeforeReading(): void
    {
        $stream = Stream::create(str_repeat('a', OutgoingAttachment::MAX_INLINE_BYTES + 1));
        $stream->seek(10);

        try {
            OutgoingAttachment::fromStream($stream, 'a.txt', 'text/plain');
            self::fail('Expected exception');
        } catch (InvalidArgumentException) {
            self::assertSame(10, $stream->tell(), 'the stream was not read');
        }
    }

    public function testOversizedStreamOfUnknownSizeStopsReadingAtTheLimit(): void
    {
        $stream = new ChunkedStream(str_repeat('a', OutgoingAttachment::MAX_INLINE_BYTES + 300_000), 65536);

        try {
            OutgoingAttachment::fromStream($stream, 'a.txt', 'text/plain');
            self::fail('Expected exception');
        } catch (InvalidArgumentException) {
            self::assertLessThan(OutgoingAttachment::MAX_INLINE_BYTES + 200_000, $stream->tell(), 'reading stopped right after the limit');
        }
    }

    public function testGeneratedFileIdsAreUniqueUuids(): void
    {
        $a = OutgoingAttachment::fromString('x', 'a.txt', 'text/plain');
        $b = OutgoingAttachment::fromString('x', 'a.txt', 'text/plain');

        self::assertMatchesRegularExpression('/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/', $a->fileId());
        self::assertNotSame($a->fileId(), $b->fileId());
        self::assertSame('a.txt', $a->fileName());
        self::assertSame('text/plain', $a->contentType());
        self::assertNull($a->description());
        self::assertSame('opis', $a->withDescription('opis')->description());
    }

    public function testDraftQuery(): void
    {
        $query = DraftQuery::create()
            ->withPagination(20, DraftQuery::MAX_LIMIT)
            ->toRecipient('AE:PL-1')
            ->withSubject('Wniosek')
            ->withAttachments()
            ->withLabel('DRAFTS')
            ->withShippingService(ShippingService::Electronic)
            ->sortBy(DraftSortColumn::Timestamp, SortDirection::Ascending);

        self::assertSame([
            'offset' => 20,
            'limit' => 100,
            'recipient' => 'AE:PL-1',
            'subject' => 'Wniosek',
            'attachments' => true,
            'label' => 'DRAFTS',
            'shippingService' => 'electronic',
            'sortColumn' => 'timestamp',
            'sortDirection' => 'asc',
        ], $query->toQuery());
        self::assertSame([], DraftQuery::create()->toQuery());
    }

    /**
     * @return iterable<string, array{\Closure(): DraftQuery}>
     */
    public static function invalidDraftQueries(): iterable
    {
        yield 'negative offset' => [static fn(): DraftQuery => DraftQuery::create()->withPagination(-1)];
        yield 'limit 0' => [static fn(): DraftQuery => DraftQuery::create()->withPagination(0, 0)];
        yield 'limit 101' => [static fn(): DraftQuery => DraftQuery::create()->withPagination(0, 101)];
        yield 'blank recipient' => [static fn(): DraftQuery => DraftQuery::create()->toRecipient(' ')];
        yield 'blank subject' => [static fn(): DraftQuery => DraftQuery::create()->withSubject('')];
        yield 'blank label' => [static fn(): DraftQuery => DraftQuery::create()->withLabel('')];
    }

    /**
     * @param \Closure(): DraftQuery $build
     */
    #[DataProvider('invalidDraftQueries')]
    public function testInvalidDraftQueries(\Closure $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        $build();
    }
}
