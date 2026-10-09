<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * Result of GET /{eDeliveryAddress}/messages ("MessagesWrapper").
 */
final readonly class MessageList
{
    /**
     * @param list<MessageSummary> $messages
     * @param int|null             $total             number of messages matching the query (may exceed the page)
     * @param int|null             $pendingElectronic messages still waiting to be fetched from the public service ("electronicCount")
     * @param string|null          $warning           mailbox close to full
     */
    public function __construct(
        public array $messages,
        public ?int $total,
        public ?int $pendingElectronic = null,
        public ?string $warning = null,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self(
            array_map(MessageSummary::fromPayload(...), $payload->objectList('messages')),
            // The contract lists a required "resultSize" without defining it, and defines "total"; both are accepted.
            $payload->optionalInt('total') ?? $payload->optionalInt('resultSize'),
            $payload->optionalInt('electronicCount'),
            $payload->optionalString('warning'),
        );
    }
}
