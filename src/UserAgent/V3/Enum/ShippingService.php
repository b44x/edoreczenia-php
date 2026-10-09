<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

enum ShippingService: string
{
    /** Registered electronic delivery (RDE). */
    case Electronic = 'electronic';
    /** Public hybrid service (PUH): printed and delivered on paper. */
    case Hybrid = 'hybrid';
}
