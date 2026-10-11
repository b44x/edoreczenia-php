<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Request;

use B4x\Edoreczenia\Exception\InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

/**
 * A file sent inside the JSON body of a message or draft ("Attachment" with "FileData").
 *
 * The content is read once, when the attachment is created, and kept base64-encoded, so the object
 * is immutable even when it was built from a stream. Files larger than {@see MAX_INLINE_BYTES}
 * must be added to a draft with {@see AttachmentUpload} instead.
 *
 * Assumptions not pinned down by the contract (verify on INT before relying on them):
 *  - "FileData.file" is declared as a plain string; the SDK sends standard base64 (RFC 4648, padded);
 *  - "FileMetadata.fileId" is required by the contract even for new files; the SDK generates a random
 *    UUID unless one is given with {@see withFileId()};
 *  - the optional hash ("alg" = SHA-3, "hash") is not sent, because its exact algorithm variant and
 *    encoding are not specified.
 */
final class OutgoingAttachment
{
    /**
     * Largest file accepted inline. The technical design sends files above 15 MB through the dedicated
     * multipart endpoint; the SDK uses the decimal reading of "15 MB", the more conservative one.
     */
    public const MAX_INLINE_BYTES = 15_000_000;

    private const CHUNK_BYTES = 3 * 65536;

    private ?string $description = null;
    private string $fileId;

    private function __construct(
        private readonly string $fileName,
        private readonly string $contentType,
        private readonly string $base64,
        private readonly int $size,
    ) {
        $this->fileId = self::uuid();
    }

    /**
     * @param string $fileName    plain file name shown to the recipient, e.g. "pismo.pdf"
     * @param string $contentType media type, e.g. "application/pdf"
     */
    public static function fromString(string $content, string $fileName, string $contentType): self
    {
        $size = \strlen($content);
        self::assertSize($size);

        return new self(Field::fileName($fileName), Field::contentType($contentType), base64_encode($content), $size);
    }

    /**
     * Reads the stream (from the start when it is seekable) and encodes it in chunks; the raw content is never
     * held in memory as a whole. The stream is left at its end and is not closed.
     */
    public static function fromStream(StreamInterface $stream, string $fileName, string $contentType): self
    {
        Field::fileName($fileName);
        Field::contentType($contentType);

        $declared = $stream->getSize();
        if ($declared !== null) {
            self::assertSize($declared);
        }
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $encoded = '';
        $pending = '';
        $size = 0;
        while (!$stream->eof()) {
            $chunk = $stream->read(self::CHUNK_BYTES);
            if ($chunk === '') {
                continue;
            }
            $size += \strlen($chunk);
            if ($size > self::MAX_INLINE_BYTES) {
                self::assertSize($size);
            }
            $pending .= $chunk;
            $whole = \strlen($pending) - \strlen($pending) % 3;
            $encoded .= base64_encode(substr($pending, 0, $whole));
            $pending = substr($pending, $whole);
        }
        self::assertSize($size);

        return new self($fileName, $contentType, $encoded . base64_encode($pending), $size);
    }

    /**
     * Description of the file ("FileMetadata.description").
     */
    public function withDescription(string $description): self
    {
        $attachment = clone $this;
        $attachment->description = Field::text('description', $description);

        return $attachment;
    }

    /**
     * Uses your own file identifier ("FileMetadata.fileId") instead of a generated UUID.
     */
    public function withFileId(string $fileId): self
    {
        $attachment = clone $this;
        $attachment->fileId = Field::id('fileId', $fileId);

        return $attachment;
    }

    public function fileName(): string
    {
        return $this->fileName;
    }

    public function contentType(): string
    {
        return $this->contentType;
    }

    /**
     * Size of the original (decoded) content in bytes.
     */
    public function size(): int
    {
        return $this->size;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function fileId(): string
    {
        return $this->fileId;
    }

    /**
     * "FileData": metadata and base64 content.
     *
     * @internal
     *
     * @return array{fileMetadata: array<string, string|int>, file: string}
     */
    public function toFileData(): array
    {
        $metadata = [
            'fileId' => $this->fileId,
            'filename' => $this->fileName,
            'contentType' => $this->contentType,
            'size' => $this->size,
        ];
        if ($this->description !== null) {
            $metadata['description'] = $this->description;
        }

        return ['fileMetadata' => $metadata, 'file' => $this->base64];
    }

    private static function assertSize(int $size): void
    {
        if ($size === 0) {
            throw new InvalidArgumentException('An attachment must not be empty.');
        }
        if ($size > self::MAX_INLINE_BYTES) {
            throw new InvalidArgumentException(\sprintf(
                'Attachments larger than %d bytes cannot be sent inline; add them to a draft with AttachmentUpload.',
                self::MAX_INLINE_BYTES,
            ));
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return strtoupper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4)));
    }
}
