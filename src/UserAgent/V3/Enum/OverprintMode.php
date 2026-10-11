<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

/**
 * Hybrid (PUH) shipments: black-and-white or colour printing ("HybridShipment.overprintMode").
 */
enum OverprintMode: string
{
    case Monochrome = 'monochrome';
    case Color = 'color';
}
