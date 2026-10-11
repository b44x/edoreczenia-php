<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * One entry of the answer to a message deletion ("MessageOperationResponseSingleWrapper").
 *
 * The contract defines no other field: it does not say whether an entry confirms the deletion of that
 * message, nor how messages that could not be deleted are reported.
 */
final readonly class DeletedMessage
{
    /**
     * @param string|null $messageId identifier reported by the server; the contract does not mark it as required
     */
    public function __construct(
        public ?string $messageId,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self($payload->optionalString('messageId'));
    }
}
