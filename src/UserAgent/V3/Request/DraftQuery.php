<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Request;

use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\UserAgent\V3\Enum\DraftSortColumn;
use B4x\Edoreczenia\UserAgent\V3\Enum\ShippingService;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortDirection;

/**
 * Filters for GET /{eDeliveryAddress}/drafts, built immutably. Drafts are always listed in "metadata" format.
 */
final class DraftQuery
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
     * @param int $offset number of drafts to skip (>= 0)
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

    public function toRecipient(string $recipient): self
    {
        return $this->with('recipient', self::nonEmpty('recipient', $recipient));
    }

    public function withSubject(string $subject): self
    {
        return $this->with('subject', self::nonEmpty('subject', $subject));
    }

    public function withAttachments(bool $hasAttachments = true): self
    {
        return $this->with('attachments', $hasAttachments);
    }

    public function withLabel(string $label): self
    {
        return $this->with('label', self::nonEmpty('label', $label));
    }

    public function withShippingService(ShippingService $service): self
    {
        return $this->with('shippingService', $service->value);
    }

    public function sortBy(DraftSortColumn $column, SortDirection $direction = SortDirection::Descending): self
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

    private static function nonEmpty(string $field, string $value): string
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(\sprintf('%s must not be empty.', $field));
        }

        return $value;
    }
}
