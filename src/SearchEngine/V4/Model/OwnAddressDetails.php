<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * Current data of the sender's own address, POST /search/eda-confirmation ("EdaConfirmationResponse").
 */
final readonly class OwnAddressDetails
{
    public function __construct(
        public RecipientAddress $address,
        public ?Entity $holder,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        $holder = $payload->optionalObject('recipient');

        return new self(
            RecipientAddress::fromPayload($payload->object('recipientEda')),
            $holder === null ? null : Entity::fromPayload($holder),
        );
    }
}
