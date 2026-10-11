<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

/**
 * Hybrid (PUH) shipments: two-sided (duplex) or one-sided (simplex) printing ("HybridShipment.printingMode").
 */
enum PrintingMode: string
{
    case Duplex = 'duplex';
    case Simplex = 'simplex';
}
