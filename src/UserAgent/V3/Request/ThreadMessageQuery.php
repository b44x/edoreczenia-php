<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Request;

use B4x\Edoreczenia\UserAgent\V3\Enum\SortDirection;
use B4x\Edoreczenia\UserAgent\V3\Enum\ThreadMessageSortColumn;

/**
 * Filters for the messages of one thread, GET /{eDeliveryAddress}/threads/{threadId} (page size 1-50, server default 50).
 */
final class ThreadMessageQuery
{
    use ThreadFilters;

    public const MAX_LIMIT = 50;

    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    public function sortBy(ThreadMessageSortColumn $column, SortDirection $direction = SortDirection::Descending): self
    {
        return $this->withSort($column->value, $direction);
    }
}
