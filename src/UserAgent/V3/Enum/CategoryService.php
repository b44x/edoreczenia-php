<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

/**
 * Hybrid (PUH) shipments: delivery time ("HybridShipment.categoryService").
 */
enum CategoryService: string
{
    case Economic = 'economic';
    case Priority = 'priority';
}
