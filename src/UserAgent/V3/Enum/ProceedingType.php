<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

/**
 * Hybrid (PUH) shipments: registered letter sent under special rules ("HybridShipment.procedingType").
 */
enum ProceedingType: string
{
    case General = 'general';
    case Administrative = 'administrative';
    case Tax = 'tax';
    case CourtCivil = 'court_civil';
    case CourtCriminal = 'court_criminal';
}
