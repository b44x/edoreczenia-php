<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

enum SortColumn: string
{
    case Sender = 'sender';
    case Recipient = 'recipient';
    case Subject = 'subject';
    case SubmissionDate = 'submissionDate';
    case EventDate = 'eventDate';
    /** Spelled "reciptDate" in the 3.0.8 contract; sent exactly as specified. */
    case ReceiptDate = 'reciptDate';
    case Timestamp = 'timestamp';
}
