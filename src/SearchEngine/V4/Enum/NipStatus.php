<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Enum;

enum NipStatus: string
{
    case Assigned = 'ASSIGNED';
    case Blank = 'BLANK';
    case Cancelled = 'CANCELLED';
    case Revoked = 'REVOKED';
}
