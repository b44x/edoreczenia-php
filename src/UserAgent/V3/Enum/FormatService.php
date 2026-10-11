<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

/**
 * Hybrid (PUH) shipments: letter format ("HybridShipment.formatService").
 */
enum FormatService: string
{
    case XS = 'XS';
    case S = 'S';
    case M = 'M';
    case L = 'L';
}
