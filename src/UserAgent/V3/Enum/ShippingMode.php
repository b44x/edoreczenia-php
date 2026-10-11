<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

/**
 * Hybrid (PUH) shipments: registered or ordinary (not registered) letter ("HybridShipment.shippingMode").
 */
enum ShippingMode: string
{
    case Registered = 'registered';
    case NotRegistered = 'notRegistered';
}
