<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * Result page of POST /search/eda-search ("EdaSearchResponses").
 */
final readonly class AddressSearchResult
{
    /**
     * @param list<AddressSearchItem> $items
     */
    public function __construct(
        public array $items,
        public ?int $totalResults,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self(
            array_map(AddressSearchItem::fromPayload(...), $payload->objectList('edaSearchResponses')),
            $payload->optionalInt('totalResults'),
        );
    }
}
