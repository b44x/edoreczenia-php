<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Model;

use B4x\Edoreczenia\Internal\Payload;
use B4x\Edoreczenia\SearchEngine\V4\Enum\AssignmentDegree;
use B4x\Edoreczenia\SearchEngine\V4\Enum\EdaStatus;

/**
 * Electronic delivery address (ADE) data: "RecipientEda", "EdaConfirmationRecipientEda"
 * and "EdaSearchRecipientEda" in the SE API v4 contract.
 *
 * Enum-typed properties hold a raw string when the server returns a value unknown to this SDK version.
 */
final readonly class RecipientAddress
{
    public function __construct(
        public string $address,
        public EdaStatus|string $status,
        public bool $designatedOperator,
        public AssignmentDegree|string|null $assignmentDegree = null,
        public ?bool $isMain = null,
        public ?Warning $warning = null,
        public ?string $enteredAt = null,
        public ?string $removedAt = null,
        public ?DesignatedOperatorService $designatedOperatorService = null,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        $warning = $payload->optionalObject('warning');
        $service = $payload->optionalObject('additionalServiceOW');

        return new self(
            address: $payload->string('recipientEda'),
            status: $payload->requiredEnumOrString('edaStatus', EdaStatus::class),
            designatedOperator: $payload->bool('designatedOperator'),
            assignmentDegree: $payload->enumOrString('assignmentDegree', AssignmentDegree::class),
            isMain: $payload->optionalBool('isMainEda'),
            warning: $warning === null ? null : Warning::fromPayload($warning),
            enteredAt: $payload->optionalString('dateOfEnteringToBAE'),
            removedAt: $payload->optionalString('dateOfRemovalFromBAE'),
            designatedOperatorService: $service === null ? null : DesignatedOperatorService::fromPayload($service),
        );
    }

    public function isActive(): bool
    {
        return $this->status === EdaStatus::Active;
    }

    /**
     * True when the server attached a blocking warning, i.e. a message cannot be sent to this address.
     */
    public function hasBlockingWarning(): bool
    {
        return $this->warning?->isBlocking() ?? false;
    }
}
