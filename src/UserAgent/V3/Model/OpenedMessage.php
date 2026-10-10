<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * A message returned by {@see \B4x\Edoreczenia\UserAgent\V3\UserAgentClient::openMessage()} ("Message" in
 * "full" or "fullExtended" format).
 *
 * Obtaining it has marked the message as opened and, for non-public recipients, triggered its transfer and the
 * E.1 delivery evidence. Contains personal data.
 */
final readonly class OpenedMessage
{
    /**
     * @param MessageSummary          $metadata    identifiers, parties, subject, status and dates (same model as in the message list)
     * @param string|null             $textBody    plain-text part of the message ("textBody", at most 5000 characters)
     * @param list<MessageAttachment> $attachments attachments in server order
     * @param list<Evidence>          $evidences   submission/delivery evidences listed with the message
     */
    public function __construct(
        public MessageSummary $metadata,
        public ?string $textBody = null,
        public array $attachments = [],
        public array $evidences = [],
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self(
            MessageSummary::fromPayload($payload),
            $payload->optionalString('textBody'),
            array_map(MessageAttachment::fromPayload(...), $payload->objectList('attachments')),
            array_map(Evidence::fromPayload(...), $payload->objectList('evidences')),
        );
    }

    public function messageId(): string
    {
        return $this->metadata->messageId;
    }

    public function attachment(string $attachmentId): ?MessageAttachment
    {
        foreach ($this->attachments as $attachment) {
            if ($attachment->attachmentId === $attachmentId) {
                return $attachment;
            }
        }

        return null;
    }
}
