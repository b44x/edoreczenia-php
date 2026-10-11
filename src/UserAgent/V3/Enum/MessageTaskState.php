<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

/**
 * "MessageTaskStatus.messageTaskStatus": processing state of a send task.
 *
 * FINISHED means the server finished creating the messages of the task; it does not mean they were
 * delivered. The outcome per recipient is returned by the task itself, delivery by evidences and the
 * message status.
 */
enum MessageTaskState: string
{
    case Pending = 'PENDING';
    case Finished = 'FINISHED';
}
