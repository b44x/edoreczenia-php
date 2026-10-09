<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Enum;

/**
 * "assignmentDegree": 0 proposed, 1 being created or withdrawn, 2 undisclosed, 3 disclosed.
 *
 * The contract declares a string enum with numeric values; both 3 and "3" are accepted.
 */
enum AssignmentDegree: int
{
    case Proposed = 0;
    case InCreationOrWithdrawn = 1;
    case Undisclosed = 2;
    case Disclosed = 3;
}
