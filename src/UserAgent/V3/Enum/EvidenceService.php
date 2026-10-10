<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

/**
 * Service of the message an evidence belongs to ("messageType" path parameter of the evidence download).
 */
enum EvidenceService: string
{
    /** Public registered electronic delivery. */
    case Purde = 'purde';
    /** Public hybrid service. */
    case Puh = 'puh';
}
