<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * Folder assignment of a message ("Label", rule R3): e.g. label INBOX/SENT/DRAFTS and the folder label in messageLocation.
 */
final readonly class MessageLabel
{
    public function __construct(
        public ?string $label,
        public ?string $location,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self($payload->optionalString('label'), $payload->optionalString('messageLocation'));
    }
}
