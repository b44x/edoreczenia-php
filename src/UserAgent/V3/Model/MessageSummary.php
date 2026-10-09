<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;
use B4x\Edoreczenia\UserAgent\V3\Enum\MessageStatus;
use B4x\Edoreczenia\UserAgent\V3\Enum\MessageType;
use B4x\Edoreczenia\UserAgent\V3\Enum\ShippingService;

/**
 * One entry of the message list ("Message" returned in "minimal" or "metadata" format).
 *
 * Listing never opens a message, so it has no legal effect. Fields beyond the identifiers are
 * filled only in "metadata" format.
 */
final readonly class MessageSummary
{
    /**
     * @param list<Party>           $to
     * @param list<MessageLabel>    $labels
     * @param array<string, string> $additionalData
     */
    public function __construct(
        public string $messageId,
        public ?string $threadId = null,
        public ?string $refToMessageId = null,
        public ?string $caseId = null,
        public ?string $subject = null,
        public ?Party $from = null,
        public array $to = [],
        public ShippingService|string|null $shippingService = null,
        public ?bool $opened = null,
        public MessageStatus|string|null $status = null,
        public ?string $statusDescription = null,
        public MessageType|string|null $type = null,
        public ?int $daysToReceive = null,
        public array $labels = [],
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $submissionDate = null,
        public ?\DateTimeImmutable $eventDate = null,
        public ?\DateTimeImmutable $receiptDate = null,
        public ?string $messageTaskId = null,
        public ?string $massMessageId = null,
        public ?bool $massMessage = null,
        public array $additionalData = [],
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        $control = $payload->optionalObject('messageControlData') ?? new Payload([], '$.messageControlData');
        $metadata = $payload->object('messageMetadata');
        $from = $metadata->optionalObject('from');

        return new self(
            messageId: $metadata->string('messageId'),
            threadId: $metadata->optionalString('threadId'),
            refToMessageId: $metadata->optionalString('refToMessageId'),
            caseId: $metadata->optionalString('caseId'),
            subject: $metadata->optionalString('subject'),
            from: $from === null ? null : Party::fromPayload($from),
            to: array_map(Party::fromPayload(...), $metadata->objectList('to')),
            shippingService: $metadata->enumOrString('shippingService', ShippingService::class),
            opened: $control->optionalBool('opened'),
            status: $control->enumOrString('status', MessageStatus::class),
            statusDescription: $control->optionalString('statusDescription'),
            type: $control->enumOrString('messageType', MessageType::class),
            daysToReceive: $control->optionalInt('daysToReceive'),
            labels: array_map(MessageLabel::fromPayload(...), $control->objectList('labels')),
            createdAt: $metadata->optionalDateTime('timestamp'),
            submissionDate: $metadata->optionalDateTime('submissionDate'),
            eventDate: $metadata->optionalDateTime('eventDate'),
            receiptDate: $metadata->optionalDateTime('receiptDate'),
            messageTaskId: $control->optionalString('messageTaskId'),
            massMessageId: $control->optionalString('massMessageId'),
            massMessage: $control->optionalBool('massMessage'),
            additionalData: $metadata->stringMap('additionalData'),
        );
    }

    /**
     * A notification about an incoming message: the original must still be fetched (non-public recipients).
     */
    public function isIncomingNotification(): bool
    {
        return $this->type === MessageType::Stub;
    }

    /**
     * Status descriptions may list several reasons separated by ";" (rule R8).
     *
     * @return list<string>
     */
    public function statusReasons(): array
    {
        if ($this->statusDescription === null || trim($this->statusDescription) === '') {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(';', $this->statusDescription)), static fn(string $r): bool => $r !== ''));
    }
}
