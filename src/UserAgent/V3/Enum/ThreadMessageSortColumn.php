<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

/**
 * Sort columns of GET /{eDeliveryAddress}/threads/{threadId}; values are sent exactly as in the 3.0.8 contract.
 */
enum ThreadMessageSortColumn: string
{
    case Sender = 'sender';
    case Recipient = 'recipient';
    case CaseId = 'caseID';
    case SubmissionDate = 'submissionDate';
    case EventDate = 'eventDate';
    /** Spelled "reciptDate" in the contract. */
    case ReceiptDate = 'reciptDate';
    case Timestamp = 'timestamp';
}
