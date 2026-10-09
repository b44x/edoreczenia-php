<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Request;

use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\SearchEngine\V4\Enum\AddressType;

/**
 * Postal address used as search criterion ("AddressData", data set no. 2).
 *
 * Without $types the server searches both headquarters and correspondence addresses.
 */
final readonly class PostalAddress
{
    /**
     * @param string            $countryCode ISO 3166-1 alpha-2, e.g. "PL"
     * @param list<AddressType> $types
     */
    public function __construct(
        public string $countryCode,
        public string $city,
        public ?string $street = null,
        public ?string $buildingNumber = null,
        public ?string $flatNumber = null,
        public ?string $postalCode = null,
        public array $types = [],
    ) {
        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            throw new InvalidArgumentException('countryCode must be an upper-case ISO 3166-1 alpha-2 code, e.g. "PL".');
        }

        Length::assert('city', $city, 1, 512);
        Length::assertOptional('street', $street, 512);
        Length::assertOptional('buildingNumber', $buildingNumber, 128);
        Length::assertOptional('flatNumber', $flatNumber, 128);
        Length::assertOptional('postalCode', $postalCode, 128);

        foreach ($types as $type) {
            if (!$type instanceof AddressType) {
                throw new InvalidArgumentException('types must contain only AddressType cases.');
            }
        }
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = ['countryCode' => $this->countryCode, 'city' => $this->city];

        if ($this->types !== []) {
            $payload['addressType'] = array_map(static fn(AddressType $t): string => $t->value, $this->types);
        }

        foreach (['street' => $this->street, 'postalCode' => $this->postalCode, 'buildingNumber' => $this->buildingNumber, 'flatNumber' => $this->flatNumber] as $key => $value) {
            if ($value !== null) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
