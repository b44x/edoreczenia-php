<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * Mailbox notification settings and capacity ("Settings").
 *
 * Contains contact data (phone number, e-mail) – treat as personal data.
 */
final readonly class MailboxSettings
{
    /**
     * @param array<string, string> $values key/value settings agreed with the provider, e.g. "totalCapacity", "usedCapacity" (MB)
     */
    public function __construct(
        public ?string $phoneNumber,
        public ?string $email,
        public array $values,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self(
            $payload->optionalString('phoneNumber'),
            $payload->optionalString('email'),
            $payload->stringMap('settings'),
        );
    }

    /**
     * Total mailbox capacity in MB, when reported as a number.
     */
    public function totalCapacityMb(): ?float
    {
        return self::number($this->values['totalCapacity'] ?? null);
    }

    /**
     * Used mailbox capacity in MB, when reported as a number.
     */
    public function usedCapacityMb(): ?float
    {
        return self::number($this->values['usedCapacity'] ?? null);
    }

    private static function number(?string $value): ?float
    {
        return $value !== null && is_numeric($value) ? (float) $value : null;
    }
}
