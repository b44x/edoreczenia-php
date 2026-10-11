<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Request;

/**
 * Recipient of a hybrid (paper, PUH) shipment identified by a postal address instead of an e-Delivery address
 * ("MessageAddressData" with "address" and "contributor"), built immutably.
 *
 * The contract makes the name ("contributor") optional; set a person, a company or both. It limits the company
 * name to {@see MAX_COMPANY_NAME_LENGTH} and its continuation to {@see MAX_COMPANY_NAME_CONTINUED_LENGTH}
 * characters for hybrid shipments; first and last names have no stated limit.
 *
 * Contains personal data.
 */
final class PaperRecipient
{
    /** "Contributor.companyName" limit for hybrid shipments. */
    public const MAX_COMPANY_NAME_LENGTH = 50;

    /** "Contributor.companyNameContinued" limit for hybrid shipments. */
    public const MAX_COMPANY_NAME_CONTINUED_LENGTH = 100;

    private ?string $firstName = null;
    private ?string $lastName = null;
    private ?string $companyName = null;
    private ?string $companyNameContinued = null;

    private function __construct(
        private readonly PaperAddress $address,
    ) {}

    public static function at(PaperAddress $address): self
    {
        return new self($address);
    }

    /**
     * Name of a natural person ("firstName", "lastName").
     */
    public function withPerson(string $firstName, string $lastName): self
    {
        $recipient = clone $this;
        $recipient->firstName = Field::text('firstName', $firstName);
        $recipient->lastName = Field::text('lastName', $lastName);

        return $recipient;
    }

    /**
     * Company name ("companyName") and its optional continuation ("companyNameContinued").
     */
    public function withCompany(string $companyName, ?string $companyNameContinued = null): self
    {
        $recipient = clone $this;
        $recipient->companyName = Field::text('companyName', $companyName, self::MAX_COMPANY_NAME_LENGTH);
        $recipient->companyNameContinued = $companyNameContinued === null
            ? null
            : Field::text('companyNameContinued', $companyNameContinued, self::MAX_COMPANY_NAME_CONTINUED_LENGTH);

        return $recipient;
    }

    public function address(): PaperAddress
    {
        return $this->address;
    }

    public function firstName(): ?string
    {
        return $this->firstName;
    }

    public function lastName(): ?string
    {
        return $this->lastName;
    }

    public function companyName(): ?string
    {
        return $this->companyName;
    }

    public function companyNameContinued(): ?string
    {
        return $this->companyNameContinued;
    }

    /**
     * JSON object ("MessageAddressData" schema).
     *
     * @internal
     *
     * @return array<string, array<string, string>>
     */
    public function toPayload(): array
    {
        $payload = ['address' => $this->address->toPayload()];
        $contributor = array_filter([
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'companyName' => $this->companyName,
            'companyNameContinued' => $this->companyNameContinued,
        ], static fn(?string $value): bool => $value !== null);
        if ($contributor !== []) {
            $payload['contributor'] = $contributor;
        }

        return $payload;
    }
}
