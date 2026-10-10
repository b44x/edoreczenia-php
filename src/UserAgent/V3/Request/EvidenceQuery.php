<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Request;

use B4x\Edoreczenia\UserAgent\V3\Enum\EvidenceType;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortDirection;

/**
 * Filters for evidence lists (GET /{eDeliveryAddress}/messages/evidences and …/messages/{messageId}/evidences).
 */
final class EvidenceQuery
{
    /** @var array<string, string|int|bool> */
    private array $params = [];

    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    /**
     * Type code, e.g. EvidenceType::ReceiptConfirmationPdf ("BP.OP"). Sent in the dotted spelling.
     */
    public function ofType(EvidenceType $type): self
    {
        return $this->with('type', $type->value);
    }

    /**
     * Only evidences that were (true) or were not (false) downloaded before.
     */
    public function downloaded(bool $downloaded): self
    {
        return $this->with('downloaded', $downloaded);
    }

    public function sortByEventDate(SortDirection $direction = SortDirection::Descending): self
    {
        return $this->with('sortColumn', 'eventDate')->with('sortDirection', $direction->value);
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
