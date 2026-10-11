<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * Result of creating or updating a draft ("MessageOperationResponseSingleWrapperPut").
 */
final readonly class SavedDraft
{
    /**
     * @param string|null $warning mailbox close to full
     */
    public function __construct(
        public string $messageId,
        public ?string $warning = null,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self($payload->string('messageId'), $payload->optionalString('warning'));
    }
}
