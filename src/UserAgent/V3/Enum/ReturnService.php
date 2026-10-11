<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

/**
 * Hybrid (PUH) shipments: what happens to a registered letter that cannot be delivered ("HybridShipment.returnService").
 */
enum ReturnService: string
{
    case ReturnToSender = 'returntosender';
    case Destroy = 'destroy';
}
