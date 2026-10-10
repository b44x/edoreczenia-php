<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Request;

use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortDirection;

/**
 * Filters shared by the thread list and the thread detail queries.
 *
 * @internal
 */
trait ThreadFilters
{
    /** @var array<string, string|int|bool> */
    private array $params = [];

    public function fromSender(string $sender): self
    {
        return $this->with('sender', self::nonEmpty('sender', $sender));
    }

    public function toRecipient(string $recipient): self
    {
        return $this->with('recipient', self::nonEmpty('recipient', $recipient));
    }

    public function withCaseId(string $caseId): self
    {
        return $this->with('caseId', self::nonEmpty('caseId', $caseId));
    }

    public function withMessageTitle(string $title): self
    {
        return $this->with('messageTitle', self::nonEmpty('messageTitle', $title));
    }

    public function submittedBetween(?\DateTimeInterface $from, ?\DateTimeInterface $to): self
    {
        return $this->withRange('submissionDate', $from, $to);
    }

    public function eventBetween(?\DateTimeInterface $from, ?\DateTimeInterface $to): self
    {
        return $this->withRange('eventDate', $from, $to);
    }

    public function withPagination(int $offset, ?int $limit = null): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('offset must be >= 0.');
        }

        $query = $this->with('offset', $offset);
        if ($limit === null) {
            return $query;
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(\sprintf('limit must be between 1 and %d.', self::MAX_LIMIT));
        }

        return $query->with('limit', $limit);
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

    private function withSort(string $column, SortDirection $direction): self
    {
        return $this->with('sortColumn', $column)->with('sortDirection', $direction->value);
    }

    private function withRange(string $field, ?\DateTimeInterface $from, ?\DateTimeInterface $to): self
    {
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException(\sprintf('%s range: "from" must not be after "to".', $field));
        }

        $query = $this;
        if ($from !== null) {
            $query = $query->with($field . 'From', self::timestamp($from));
        }
        if ($to !== null) {
            $query = $query->with($field . 'To', self::timestamp($to));
        }

        return $query;
    }

    private function with(string $name, string|int|bool $value): self
    {
        $query = clone $this;
        $query->params[$name] = $value;

        return $query;
    }

    private static function timestamp(\DateTimeInterface $value): string
    {
        return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function nonEmpty(string $field, string $value): string
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(\sprintf('%s must not be empty.', $field));
        }

        return $value;
    }
}
