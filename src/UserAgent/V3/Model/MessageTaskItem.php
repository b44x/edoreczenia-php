<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * Outcome of a send task for one recipient ("MessageOperationResponseWrapperStatus").
 *
 * The contract lists "addresseeAde" and "status" as required but does not define them; both are read when present.
 * Contains personal data (recipient names).
 */
final readonly class MessageTaskItem
{
    /**
     * @param string|null $messageId        identifier of the message created for this recipient
     * @param Party|null  $recipient        "addressee"
     * @param string|null $recipientAddress "addresseeAde", or the address of {@see $recipient}
     * @param string|null $error            error code when no message was created for this recipient
     */
    public function __construct(
        public ?string $messageId,
        public ?Party $recipient = null,
        public ?string $recipientAddress = null,
        public ?string $status = null,
        public ?string $error = null,
        public ?string $errorDescription = null,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        $addressee = $payload->optionalObject('addressee');
        $recipient = $addressee === null ? null : Party::fromPayload($addressee);

        return new self(
            $payload->optionalString('messageId'),
            $recipient,
            $payload->optionalString('addresseeAde') ?? $recipient?->deliveryAddress,
            $payload->optionalString('status'),
            $payload->optionalString('error'),
            $payload->optionalString('error_description'),
        );
    }

    /**
     * A message was created for this recipient and no error was reported. Delivery is confirmed only by evidences.
     */
    public function isAccepted(): bool
    {
        return $this->messageId !== null && $this->error === null;
    }
}
