<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Model;

use B4x\Edoreczenia\Internal\Payload;
use B4x\Edoreczenia\SearchEngine\V4\Enum\NipStatus;
use B4x\Edoreczenia\SearchEngine\V4\Enum\OfficialIdType;

/**
 * Data of the person or organisation holding an address
 * ("BaeSearchData", "EdaConfirmationRecipient", "EdaSearchRecipient").
 *
 * Contains personal data (names, PESEL). Do not log or persist it without a legal basis.
 */
final readonly class Entity
{
    /**
     * @param list<OfficialId>        $officialIds
     * @param list<RegisteredAddress> $addresses
     * @param int|null                $index       version in chronological order; 1 = current data (search results only)
     */
    public function __construct(
        public bool $isPublic,
        public ?int $index = null,
        public ?string $name = null,
        public ?string $surname = null,
        public ?string $entityName = null,
        public ?string $professionalTitle = null,
        public ?string $legalForm = null,
        public array $officialIds = [],
        public NipStatus|string|null $nipStatus = null,
        public array $addresses = [],
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self(
            isPublic: $payload->bool('isPublic'),
            index: $payload->optionalInt('index'),
            name: $payload->optionalString('name'),
            surname: $payload->optionalString('surname'),
            entityName: $payload->optionalString('entityName'),
            professionalTitle: $payload->optionalString('professionalTitle'),
            legalForm: $payload->optionalString('legalForm'),
            officialIds: array_map(OfficialId::fromPayload(...), $payload->objectList('officialIds')),
            nipStatus: $payload->enumOrString('nipStatus', NipStatus::class),
            addresses: array_map(RegisteredAddress::fromPayload(...), $payload->objectList('address')),
        );
    }

    public function officialId(OfficialIdType $type): ?string
    {
        foreach ($this->officialIds as $officialId) {
            if ($officialId->type === $type) {
                return $officialId->id;
            }
        }

        return null;
    }

    /**
     * Entity name for organisations, otherwise "name surname".
     */
    public function displayName(): string
    {
        return $this->entityName ?? trim(($this->name ?? '') . ' ' . ($this->surname ?? ''));
    }
}
