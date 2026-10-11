<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Request;

use B4x\Edoreczenia\Internal\MultipartStream;
use Psr\Http\Message\StreamInterface;

/**
 * A large file for POST /{eDeliveryAddress}/{messageId}/attachments/upload ("AttachmentUploadRequestBody",
 * multipart/form-data), meant for files above {@see OutgoingAttachment::MAX_INLINE_BYTES}.
 *
 * The stream is not read when this object is created: the SDK hands a multipart body to your HTTP client,
 * which reads the file chunk by chunk while sending it, so the file is never held in memory by the SDK.
 * Keep the stream open until the upload returns. A stream can be uploaded once: the request is never
 * retried automatically.
 *
 * Not verified against INT/PROD; the contract does not state the maximum size accepted by this endpoint.
 */
final class AttachmentUpload
{
    private ?string $description = null;

    private function __construct(
        private readonly StreamInterface $stream,
        private readonly string $fileName,
        private readonly string $contentType,
    ) {}

    /**
     * @param string $fileName    plain file name shown to the recipient, e.g. "akta.zip"
     * @param string $contentType media type, e.g. "application/zip"
     */
    public static function fromStream(StreamInterface $stream, string $fileName, string $contentType): self
    {
        return new self($stream, Field::fileName($fileName), Field::contentType($contentType));
    }

    public function withDescription(string $description): self
    {
        $upload = clone $this;
        $upload->description = Field::text('description', $description);

        return $upload;
    }

    public function fileName(): string
    {
        return $this->fileName;
    }

    public function contentType(): string
    {
        return $this->contentType;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * Size of the file when the stream knows it.
     */
    public function size(): ?int
    {
        return $this->stream->getSize();
    }

    /**
     * Streamed multipart/form-data body; the file is read from its start when the stream is seekable.
     *
     * @internal
     */
    public function toMultipart(string $boundary): MultipartStream
    {
        $fields = ['filename' => $this->fileName, 'contentType' => $this->contentType];
        $size = $this->stream->getSize();
        if ($size !== null) {
            // "size" is declared as a string in the contract.
            $fields['size'] = (string) $size;
        }
        if ($this->description !== null) {
            $fields['description'] = $this->description;
        }

        $head = '';
        foreach ($fields as $name => $value) {
            $head .= \sprintf("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n", $boundary, $name, $value);
        }
        $head .= \sprintf(
            "--%s\r\nContent-Disposition: form-data; name=\"file\"; filename=\"%s\"\r\nContent-Type: %s\r\n\r\n",
            $boundary,
            str_replace('"', '%22', $this->fileName),
            $this->contentType,
        );

        if ($this->stream->isSeekable()) {
            $this->stream->rewind();
        }

        return new MultipartStream([$head, $this->stream, \sprintf("\r\n--%s--\r\n", $boundary)]);
    }
}
