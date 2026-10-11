<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Request;

/**
 * Postal address of a recipient of a hybrid (paper, PUH) shipment ("Address" schema), built immutably.
 *
 * The contract requires country, postal code, city and street; building and flat numbers are optional.
 * It defines no format for any of them (e.g. whether "country" is a name or a code, or the postal code
 * pattern), so the SDK only checks that each value is non-blank UTF-8; the rest is validated by the server.
 *
 * Contains personal data.
 */
final class PaperAddress
{
    private ?string $buildingNumber = null;
    private ?string $houseNumber = null;

    private function __construct(
        private readonly string $country,
        private readonly string $postalCode,
        private readonly string $city,
        private readonly string $street,
    ) {}

    /**
     * @param string $country    "country"
     * @param string $postalCode "postalCode", e.g. "05-569"
     * @param string $city       "city"
     * @param string $street     "street"
     */
    public static function create(string $country, string $postalCode, string $city, string $street): self
    {
        return new self(
            Field::text('country', $country),
            Field::text('postalCode', $postalCode),
            Field::text('city', $city),
            Field::text('street', $street),
        );
    }

    /**
     * Building number ("buildingNumber"), e.g. "12A".
     */
    public function withBuildingNumber(string $buildingNumber): self
    {
        $address = clone $this;
        $address->buildingNumber = Field::text('buildingNumber', $buildingNumber);

        return $address;
    }

    /**
     * Flat number ("houseNumber").
     */
    public function withHouseNumber(string $houseNumber): self
    {
        $address = clone $this;
        $address->houseNumber = Field::text('houseNumber', $houseNumber);

        return $address;
    }

    public function country(): string
    {
        return $this->country;
    }

    public function postalCode(): string
    {
        return $this->postalCode;
    }

    public function city(): string
    {
        return $this->city;
    }

    public function street(): string
    {
        return $this->street;
    }

    public function buildingNumber(): ?string
    {
        return $this->buildingNumber;
    }

    public function houseNumber(): ?string
    {
        return $this->houseNumber;
    }

    /**
     * JSON object ("Address" schema).
     *
     * @internal
     *
     * @return array<string, string>
     */
    public function toPayload(): array
    {
        $payload = ['country' => $this->country, 'street' => $this->street, 'postalCode' => $this->postalCode, 'city' => $this->city];
        if ($this->buildingNumber !== null) {
            $payload['buildingNumber'] = $this->buildingNumber;
        }
        if ($this->houseNumber !== null) {
            $payload['houseNumber'] = $this->houseNumber;
        }

        return $payload;
    }
}
