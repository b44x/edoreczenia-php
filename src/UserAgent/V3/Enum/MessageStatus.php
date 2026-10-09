<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

/**
 * "MessageControlData.status" (UA API 3.0.8; meaning defined by rule R9 of the technical design).
 *
 * The contract uses Polish labels as wire values.
 */
enum MessageStatus: string
{
    /** In verification by the sender's provider. */
    case InVerification = 'W trakcie weryfikacji';
    /** Hybrid (PUH) only: passed to the hybrid service after validation. */
    case ForwardedForProcessing = 'Przekazana do realizacji';
    /** Rejected (evidence A.2 or H.DW); see statusDescription. */
    case Rejected = 'Odrzucona';
    /** Accepted for delivery (evidence A.1 or H.PN). */
    case Submitted = 'Nadana';
    /** Electronic only: relayed to the recipient's provider (evidence B.1). */
    case Sent = 'Wysłana';
    /** Electronic only: ready for pick-up (evidence D.1). */
    case AwaitingDelivery = 'Czeka na doręczenie';
    /** Electronic only: delivered (evidence E.1). */
    case Delivered = 'Doręczona';
    /** Electronic only: deemed delivered (receipt issued without E.1). */
    case DeemedDelivered = 'Uznana za doręczoną';
    /** Electronic only: not delivered; see statusDescription. */
    case NotDelivered = 'Niedoręczona';
}
