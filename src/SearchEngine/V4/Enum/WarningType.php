<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Enum;

/**
 * A blocking warning means the sender cannot send to this address (e.g. SEAPI_INFO-00004: inactive ADE).
 */
enum WarningType: string
{
    case NotBlocking = 'NOT_BLOCK_WARNING';
    case Blocking = 'BLOCK_WARNING';
}
