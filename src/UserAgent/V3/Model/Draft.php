<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * A draft read with GET /{eDeliveryAddress}/drafts/{messageId} ("Message" in the default "full" format).
 */
final readonly class Draft
{
    /**
     * @param MessageSummary        $metadata    identifiers, subject, recipients and control data
     * @param list<DraftAttachment> $attachments
     */
    public function __construct(
        public MessageSummary $metadata,
        public ?string $textBody = null,
        public array $attachments = [],
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self(
            MessageSummary::fromPayload($payload),
            $payload->optionalString('textBody'),
            array_map(DraftAttachment::fromPayload(...), $payload->objectList('attachments')),
        );
    }

    public function messageId(): string
    {
        return $this->metadata->messageId;
    }
}
