<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;
use B4x\Edoreczenia\UserAgent\V3\Enum\EvidenceType;

/**
 * Metadata of an evidence (dowód) registered for a message ("Evidence").
 *
 * Evidences document delivery events with legal significance (e.g. E.1 delivery, BP.OX receipt confirmation);
 * keep the downloaded files as received.
 */
final readonly class Evidence
{
    /**
     * @param list<string> $reasonDetails
     */
    public function __construct(
        public string $evidenceId,
        public string $messageId,
        public EvidenceType|string|null $type,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $eventDate = null,
        public ?bool $downloaded = null,
        public ?string $reasonId = null,
        public array $reasonDetails = [],
        public ?Party $from = null,
        public ?Party $to = null,
        public ?string $externalData = null,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        $type = $payload->optionalString('type');
        $from = $payload->optionalObject('from');
        $to = $payload->optionalObject('to');

        return new self(
            evidenceId: $payload->string('evidenceId'),
            messageId: $payload->string('messageId'),
            type: $type === null ? null : (EvidenceType::fromWire($type) ?? $type),
            createdAt: $payload->optionalDateTime('createDate'),
            eventDate: $payload->optionalDateTime('eventDate'),
            downloaded: $payload->optionalBool('downloaded'),
            reasonId: $payload->optionalString('reasonId'),
            reasonDetails: $payload->stringList('reasonDetails'),
            from: $from === null ? null : Party::fromPayload($from),
            to: $to === null ? null : Party::fromPayload($to),
            externalData: $payload->optionalString('externalData'),
        );
    }
}
