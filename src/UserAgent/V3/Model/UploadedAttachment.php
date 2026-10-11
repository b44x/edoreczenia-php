<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * Attachment added to a draft ("FileWrapper").
 */
final readonly class UploadedAttachment
{
    /**
     * @param string|null $warning mailbox close to full
     */
    public function __construct(
        public ?string $attachmentId,
        public ?FileInfo $file = null,
        public ?string $warning = null,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        $file = $payload->optionalObject('fileMetadata');

        return new self(
            $payload->optionalString('attachmentId'),
            $file === null ? null : FileInfo::fromPayload($file),
            $payload->optionalString('warning'),
        );
    }
}
