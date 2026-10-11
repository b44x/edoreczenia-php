<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * Attachment listed in a draft ("Attachment"). Only metadata is mapped; file content is not kept by this model.
 */
final readonly class DraftAttachment
{
    public function __construct(
        public ?string $attachmentId,
        public ?int $order = null,
        public ?FileInfo $file = null,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        $file = $payload->optionalObject('file')?->optionalObject('fileMetadata');

        return new self(
            $payload->optionalString('attachmentId'),
            $payload->optionalInt('order'),
            $file === null ? null : FileInfo::fromPayload($file),
        );
    }
}
