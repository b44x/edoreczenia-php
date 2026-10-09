<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * "additionalServiceOW": usage period of the designated operator's additional service.
 *
 * Dates are kept as returned: the contract declares them as strings without a format.
 */
final readonly class DesignatedOperatorService
{
    public function __construct(
        public ?string $activationDate,
        public ?string $resignationDate,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self($payload->optionalString('activationDate'), $payload->optionalString('resignationDate'));
    }
}
