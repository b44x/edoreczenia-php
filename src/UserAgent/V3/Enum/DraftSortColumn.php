<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

/**
 * "sortColumn" of GET /{eDeliveryAddress}/drafts.
 */
enum DraftSortColumn: string
{
    case Recipient = 'recipient';
    case Subject = 'subject';
    case Timestamp = 'timestamp';
}
