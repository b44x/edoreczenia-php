<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

/**
 * Evidence types handled by UA API (technical design 5.27, annex A).
 *
 * The 3.0.8 contract lists every code twice – with and without the dot ("E.1" and "E1", "BP.OX" and "BPOX") –
 * plus undocumented values ("D", "C2"). {@see fromWire()} maps both spellings to one case; values it does not
 * know are kept as raw strings by the SDK.
 */
enum EvidenceType: string
{
    /** Acceptance of submission. */
    case A1 = 'A.1';
    /** Rejection of submission. */
    case A2 = 'A.2';
    /** Relay to the recipient's provider accepted. */
    case B1 = 'B.1';
    /** Relay rejected by the recipient's provider. */
    case B2 = 'B.2';
    /** Relay failure. */
    case B3 = 'B.3';
    /** Ready for pick-up. */
    case D1 = 'D.1';
    /** Technical failure preparing pick-up. */
    case D2 = 'D.2';
    /** Notification about a message ready for pick-up. */
    case D3 = 'D.3';
    /** Delivery (handover to the recipient's mailbox). */
    case E1 = 'E.1';
    /** Delivery failure. */
    case E2 = 'E.2';
    /** Sending confirmation, XML. */
    case SendingConfirmationXml = 'BP.WX';
    /** Sending confirmation, PDF. */
    case SendingConfirmationPdf = 'BP.WP';
    /** Receipt confirmation, XML. */
    case ReceiptConfirmationXml = 'BP.OX';
    /** Receipt confirmation, PDF. */
    case ReceiptConfirmationPdf = 'BP.OP';
    /** Hybrid service: negative validation. */
    case HybridRejected = 'H.DW';
    /** Hybrid service: submission confirmation. */
    case HybridSubmitted = 'H.PN';
    /** Hybrid service: electronic acknowledgement of receipt. */
    case HybridDelivered = 'H.EPO';

    /**
     * Maps both contract spellings ("E.1"/"E1", "BP.OX"/"BPOX", "H.EPO"/"HEPO") to a case.
     */
    public static function fromWire(string $value): ?self
    {
        $direct = self::tryFrom($value);
        if ($direct !== null) {
            return $direct;
        }

        foreach (self::cases() as $case) {
            if (str_replace('.', '', $case->value) === $value) {
                return $case;
            }
        }

        return null;
    }

    public function isHybrid(): bool
    {
        return str_starts_with($this->value, 'H.');
    }
}
