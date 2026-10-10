<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Exception\UnexpectedResponseException;
use B4x\Edoreczenia\Internal\Payload;

/**
 * Attachment of a received message ("Attachment" with its "FileData" and "FileMetadata").
 *
 * Opening a message in "full" format lists attachments without their content; "fullExtended" and
 * {@see \B4x\Edoreczenia\UserAgent\V3\UserAgentClient::getAttachment()} also carry the content inline.
 * Prefer {@see \B4x\Edoreczenia\UserAgent\V3\UserAgentClient::downloadAttachment()}, which streams the file.
 *
 * File names and descriptions are supplied by the sender: never use them as paths.
 */
final readonly class MessageAttachment
{
    /**
     * @param string|null $attachmentId   id used to download the attachment (optional in the contract)
     * @param int|null    $order          position of the attachment within the message
     * @param string|null $fileId         id of the stored file ("fileId", required in the contract once file metadata is present)
     * @param int|null    $size           size in bytes, as reported by the server
     * @param string|null $hashAlgorithm  hash algorithm name, e.g. "SHA-3" ("alg")
     * @param string|null $hash           file hash as sent by the server; its encoding is not defined by the contract
     * @param string|null $encodedContent file content exactly as sent ("file"); absent in "full" format
     */
    public function __construct(
        public ?string $attachmentId,
        public ?int $order = null,
        public ?string $fileId = null,
        public ?string $fileName = null,
        public ?string $contentType = null,
        public ?int $size = null,
        public ?string $hashAlgorithm = null,
        public ?string $hash = null,
        public ?string $description = null,
        public ?string $encodedContent = null,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        $file = $payload->optionalObject('file');
        $metadata = $file?->optionalObject('fileMetadata');

        return new self(
            attachmentId: $payload->optionalString('attachmentId'),
            order: $payload->optionalInt('order'),
            fileId: $metadata?->string('fileId'),
            fileName: $metadata?->optionalString('filename'),
            contentType: $metadata?->optionalString('contentType'),
            size: $metadata?->optionalInt('size'),
            hashAlgorithm: $metadata?->optionalString('alg'),
            hash: $metadata?->optionalString('hash'),
            description: $metadata?->optionalString('description'),
            encodedContent: $file?->optionalString('file'),
        );
    }

    public function hasContent(): bool
    {
        return $this->encodedContent !== null;
    }

    /**
     * Decodes the inline content.
     *
     * The contract declares the content only as a JSON string; Base64 (RFC 4648) is assumed, as it is the
     * only way to carry binary data in JSON. Not yet verified against a live environment.
     *
     * @throws \LogicException              when the response carried no content ("full" format)
     * @throws UnexpectedResponseException when the content is not valid Base64
     */
    public function decodeContent(): string
    {
        if ($this->encodedContent === null) {
            throw new \LogicException('This attachment carries no content; download it with UserAgentClient::downloadAttachment().');
        }

        // Strict mode still skips whitespace, so line-wrapped Base64 is accepted.
        $decoded = base64_decode($this->encodedContent, true);
        if ($decoded === false) {
            throw new UnexpectedResponseException('Unexpected response: attachment content is not valid Base64.');
        }

        return $decoded;
    }

    /**
     * The sender's file name reduced to a safe base name (no directories, no control characters), or null.
     * Still choose the target directory yourself.
     */
    public function safeFileName(): ?string
    {
        if ($this->fileName === null) {
            return null;
        }

        $name = basename(str_replace('\\', '/', $this->fileName));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $name);

        return $name === '' || $name === '.' || $name === '..' ? null : $name;
    }
}
