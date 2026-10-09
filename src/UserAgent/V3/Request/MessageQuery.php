<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Request;

use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\UserAgent\V3\Enum\ListFormat;
use B4x\Edoreczenia\UserAgent\V3\Enum\ShippingService;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortColumn;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortDirection;

/**
 * Filters for GET /{eDeliveryAddress}/messages, built immutably.
 *
 * Only the "minimal" and "metadata" formats exist for lists; neither opens a message.
 * Date filters are sent as RFC 3339 timestamps in UTC; the exact format accepted by the server is unverified.
 */
final class MessageQuery
{
    public const MAX_LIMIT = 2000;
    public const DEFAULT_LIMIT = 20;

    /** @var array<string, string|int|bool> */
    private array $params = [];

    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    public function withFormat(ListFormat $format): self
    {
        return $this->with('format', $format->value);
    }

    /**
     * Folder label, e.g. "INBOX", "SENT" or the label of a user-defined folder.
     */
    public function inFolder(string $label): self
    {
        return $this->with('label', self::nonEmpty('label', $label));
    }

    /**
     * @param int $offset number of messages to skip (>= 0)
     * @param int $limit  page size, 1-2000
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
     * Sender: name, entity name or e-Delivery address.
     */
    public function fromSender(string $sender): self
    {
        return $this->with('sender', self::nonEmpty('sender', $sender));
    }

    /**
     * Recipient: name, entity name or e-Delivery address.
     */
    public function toRecipient(string $recipient): self
    {
        return $this->with('recipient', self::nonEmpty('recipient', $recipient));
    }

    public function withSubject(string $subject): self
    {
        return $this->with('subject', self::nonEmpty('subject', $subject));
    }

    public function onlyOpened(bool $opened = true): self
    {
        return $this->with('opened', $opened);
    }

    public function withAttachments(bool $hasAttachments = true): self
    {
        return $this->with('attachments', $hasAttachments);
    }

    public function withShippingService(ShippingService $service): self
    {
        return $this->with('shippingService', $service->value);
    }

    public function submittedBetween(?\DateTimeInterface $from, ?\DateTimeInterface $to): self
    {
        return $this->withRange('submissionDate', $from, $to);
    }

    public function eventBetween(?\DateTimeInterface $from, ?\DateTimeInterface $to): self
    {
        return $this->withRange('eventDate', $from, $to);
    }

    public function receivedBetween(?\DateTimeInterface $from, ?\DateTimeInterface $to): self
    {
        return $this->withRange('receiptDate', $from, $to);
    }

    public function sortBy(SortColumn $column, SortDirection $direction = SortDirection::Descending): self
    {
        return $this->with('sortColumn', $column->value)->with('sortDirection', $direction->value);
    }

    /**
     * Return mass-sent messages as grouping elements ("massMessagesGrouped").
     */
    public function groupMassMessages(bool $grouped = true): self
    {
        return $this->with('massMessagesGrouped', $grouped);
    }

    public function forMassMessage(string $massMessageId): self
    {
        return $this->with('massMessageId', self::nonEmpty('massMessageId', $massMessageId));
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
