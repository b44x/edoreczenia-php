<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Enum;

/**
 * Status of an electronic delivery address (ADE).
 *
 * RESERVED appears in the technical design (section 4.3.2) but not in the 4.0.5 contract enum;
 * it is included so that it is mapped if the server ever returns it.
 */
enum EdaStatus: string
{
    case Reserved = 'RESERVED';
    case Active = 'ACTIVE';
    case ClosedRecoverable = 'CLOSED_RECOVERABLE';
    case ClosedUnrecoverable = 'CLOSED_UNRECOVERABLE';
    case StruckOff = 'STRUCK_OFF';
}
