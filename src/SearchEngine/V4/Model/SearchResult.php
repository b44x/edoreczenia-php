<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * Result page of POST /search/bae_search ("SearchResponse").
 */
final readonly class SearchResult
{
    /**
     * @param list<SearchResultItem> $items
     * @param int|null               $totalResults total number of matches; may exceed the page size
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
            array_map(SearchResultItem::fromPayload(...), $payload->objectList('baeSearchResponses')),
            $payload->optionalInt('totalResults'),
        );
    }
}
