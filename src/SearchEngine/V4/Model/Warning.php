<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Model;

use B4x\Edoreczenia\Internal\Payload;
use B4x\Edoreczenia\SearchEngine\V4\Enum\WarningType;

/**
 * Warning attached to a recipient address, e.g. SEAPI_INFO-00004 (inactive address, blocking).
 */
final readonly class Warning
{
    public function __construct(
        public ?string $code,
        public WarningType|string|null $type,
        public ?string $description,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self(
            $payload->optionalString('code'),
            $payload->enumOrString('type', WarningType::class),
            $payload->optionalString('description'),
        );
    }

    public function isBlocking(): bool
    {
        return $this->type === WarningType::Blocking;
    }
}
