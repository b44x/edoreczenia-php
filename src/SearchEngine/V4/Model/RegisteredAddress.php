<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Model;

use B4x\Edoreczenia\Internal\Payload;
use B4x\Edoreczenia\SearchEngine\V4\Enum\AddressType;

/**
 * Correspondence or headquarters address returned by the search ("AddressDataResponse").
 */
final readonly class RegisteredAddress
{
    /**
     * @param list<AddressType|string> $types
     */
    public function __construct(
        public array $types,
        public string $countryCode,
        public string $city,
        public ?string $street = null,
        public ?string $typeOfStreet = null,
        public ?string $postalCode = null,
        public ?string $buildingNumber = null,
        public ?string $flatNumber = null,
        public ?string $postalOffice = null,
        public ?string $postOfficeBox = null,
        public ?string $voivodeship = null,
        public ?string $district = null,
        public ?string $community = null,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        $types = array_map(
            static fn(string $type): AddressType|string => AddressType::tryFrom($type) ?? $type,
            $payload->stringList('addressType'),
        );

        return new self(
            types: $types,
            countryCode: $payload->string('countryCode'),
            city: $payload->string('city'),
            street: $payload->optionalString('street'),
            typeOfStreet: $payload->optionalString('typeOfStreet'),
            postalCode: $payload->optionalString('postalCode'),
            buildingNumber: $payload->optionalString('buildingNumber'),
            flatNumber: $payload->optionalString('flatNumber'),
            postalOffice: $payload->optionalString('postalOffice'),
            postOfficeBox: $payload->optionalString('postOfficeBox'),
            voivodeship: $payload->optionalString('voivodeship'),
            district: $payload->optionalString('district'),
            community: $payload->optionalString('community'),
        );
    }

    public function isOfType(AddressType $type): bool
    {
        return \in_array($type, $this->types, true);
    }
}
