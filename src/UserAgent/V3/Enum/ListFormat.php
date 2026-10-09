<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

/**
 * Formats allowed by GET /{eDeliveryAddress}/messages. Neither marks messages as read:
 * only fetching a single message in "full"/"fullExtended" mode does (UC006).
 */
enum ListFormat: string
{
    case Minimal = 'minimal';
    case Metadata = 'metadata';
}
