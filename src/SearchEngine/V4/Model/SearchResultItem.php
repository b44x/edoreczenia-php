<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * One address found by the advanced search ("BaeSearchResponse").
 *
 * $records holds current and, when the query matched archived data, historical data
 * of the holder, ordered from current (index 1) to oldest.
 */
final readonly class SearchResultItem
{
    /**
     * @param list<Entity> $records
     */
    public function __construct(
        public RecipientAddress $address,
        public array $records,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        $records = array_map(Entity::fromPayload(...), $payload->objectList('baeSearchData'));
        usort($records, static fn(Entity $a, Entity $b): int => ($a->index ?? \PHP_INT_MAX) <=> ($b->index ?? \PHP_INT_MAX));

        return new self(RecipientAddress::fromPayload($payload->object('recipientEda')), $records);
    }

    /**
     * Current data of the holder (index 1), or null when the response carried no entity data.
     */
    public function current(): ?Entity
    {
        return $this->records[0] ?? null;
    }
}
