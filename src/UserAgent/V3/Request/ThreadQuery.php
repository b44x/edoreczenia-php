<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Request;

use B4x\Edoreczenia\UserAgent\V3\Enum\SortDirection;
use B4x\Edoreczenia\UserAgent\V3\Enum\ThreadSortColumn;

/**
 * Filters for GET /{eDeliveryAddress}/threads (page size 1-2000, server default 10).
 */
final class ThreadQuery
{
    use ThreadFilters;

    public const MAX_LIMIT = 2000;

    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    public function sortBy(ThreadSortColumn $column, SortDirection $direction = SortDirection::Descending): self
    {
        return $this->withSort($column->value, $direction);
    }
}
