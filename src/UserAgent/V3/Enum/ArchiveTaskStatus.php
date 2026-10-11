<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

/**
 * "MessageArchivingTask.taskStatus": state of a message archive task.
 *
 * The contract lists the values without describing the transitions between them. Values unknown to this
 * SDK version are returned as strings by {@see \B4x\Edoreczenia\UserAgent\V3\Model\ArchiveTask::$status}.
 */
enum ArchiveTaskStatus: string
{
    case ArchivingInProgress = 'ARCHIVING_IN_PROGRESS';
    case Error = 'ERROR';
    case ReadyToDownload = 'READY_TO_DOWNLOAD';
    case CancelInProgress = 'CANCEL_IN_PROGRESS';
    case Canceled = 'CANCELED';
    case FinishingInProgress = 'FINISHING_IN_PROGRESS';
    case Finished = 'FINISHED';
    case Expired = 'EXPIRED';
}
