<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Request;

use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\UserAgent\V3\Enum\ArchiveTaskSortColumn;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortDirection;

/**
 * Filters for GET /{eDeliveryAddress}/message-archive-task-list, built immutably.
 */
final class ArchiveTaskQuery
{
    public const MAX_LIMIT = 100;
    public const DEFAULT_LIMIT = 20;

    /** @var array<string, string|int|bool> */
    private array $params = [];

    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    /**
     * @param int $offset number of tasks to skip (>= 0)
     * @param int $limit  page size, 1-100
     */
    public function withPagination(int $offset, int $limit = self::DEFAULT_LIMIT): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('offset must be >= 0.');
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(\sprintf('limit must be between 1 and %d.', self::MAX_LIMIT));
        }

        return $this->with('offset', $offset)->with('limit', $limit);
    }

    /**
     * Only the task with this id ("taskId").
     */
    public function forTask(string $taskId): self
    {
        if ($taskId === '' || preg_match('/\s/u', $taskId) === 1) {
            throw new InvalidArgumentException('Archive task id must be a non-empty string without whitespace.');
        }

        return $this->with('taskId', $taskId);
    }

    public function sortBy(ArchiveTaskSortColumn $column, SortDirection $direction = SortDirection::Descending): self
    {
        return $this->with('sortColumn', $column->value)->with('sortDirection', $direction->value);
    }

    /**
     * @internal
     *
     * @return array<string, string|int|bool>
     */
    public function toQuery(): array
    {
        return $this->params;
    }

    private function with(string $name, string|int|bool $value): self
    {
        $query = clone $this;
        $query->params[$name] = $value;

        return $query;
    }
}
