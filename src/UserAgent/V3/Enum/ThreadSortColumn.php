<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

/**
 * Sort columns of GET /{eDeliveryAddress}/threads.
 */
enum ThreadSortColumn: string
{
    case FirstMessageTitle = 'firstMessageTitle';
    case FirstMessageSubmissionDate = 'firstMessageSubmissionDate';
    case FirstMessageEventDate = 'firstMessageEventDate';
    case LastMessageSubmissionDate = 'lastMessageSubmissionDate';
    case LastMessageEventDate = 'lastMessageEventDate';
}
