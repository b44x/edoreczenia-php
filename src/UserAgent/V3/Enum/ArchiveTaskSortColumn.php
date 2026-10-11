<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

/**
 * "sortColumn" of GET /{eDeliveryAddress}/message-archive-task-list.
 */
enum ArchiveTaskSortColumn: string
{
    case CreationDate = 'creationDate';
    case DownloadDeadline = 'dateForDownload';
}
