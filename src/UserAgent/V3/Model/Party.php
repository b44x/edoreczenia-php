<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * Sender or recipient of a message ("MessageAddressData" with its "Contributor").
 *
 * Electronic messages carry an e-Delivery address; hybrid ones a postal address (not mapped in 0.2).
 * Contains personal data.
 */
final readonly class Party
{
    public function __construct(
        public ?string $deliveryAddress,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $companyName = null,
        public ?string $companyNameContinued = null,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        $contributor = $payload->optionalObject('contributor');

        return new self(
            $payload->optionalString('eDeliveryAddress'),
            $contributor?->optionalString('firstName'),
            $contributor?->optionalString('lastName'),
            $contributor?->optionalString('companyName'),
            $contributor?->optionalString('companyNameContinued'),
        );
    }

    /**
     * Party known only by name ("Contributor", used in thread summaries).
     *
     * @internal
     */
    public static function fromContributor(Payload $payload): self
    {
        return new self(
            null,
            $payload->optionalString('firstName'),
            $payload->optionalString('lastName'),
            $payload->optionalString('companyName'),
            $payload->optionalString('companyNameContinued'),
        );
    }

    /**
     * Company name (with continuation) for organisations, otherwise "first last", otherwise the address.
     */
    public function displayName(): string
    {
        if ($this->companyName !== null) {
            return trim($this->companyName . ' ' . ($this->companyNameContinued ?? ''));
        }

        $person = trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));

        return $person !== '' ? $person : ($this->deliveryAddress ?? '');
    }
}
