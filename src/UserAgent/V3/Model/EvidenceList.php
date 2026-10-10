<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * Evidence list ("EvidenceWrapper").
 */
final readonly class EvidenceList
{
    /**
     * @param list<Evidence> $evidences
     */
    public function __construct(
        public array $evidences,
        public ?string $warning = null,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self(
            array_map(Evidence::fromPayload(...), $payload->objectList('evidences')),
            $payload->optionalString('warning'),
        );
    }
}
