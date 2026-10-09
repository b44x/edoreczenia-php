<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;
use B4x\Edoreczenia\UserAgent\V3\Enum\DirectoryType;

/**
 * Mailbox folder ("Directory" / "DirectoryExtended").
 *
 * Message counts are present only when listed with {@see \B4x\Edoreczenia\UserAgent\V3\UserAgentClient::directoriesWithCounts()}.
 */
final readonly class Directory
{
    public function __construct(
        public ?string $id,
        public ?string $name,
        public ?string $label,
        public DirectoryType|string|null $type,
        public ?int $unreadMessages = null,
        public ?int $totalMessages = null,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self(
            $payload->optionalString('directoryId'),
            $payload->optionalString('name'),
            $payload->optionalString('label'),
            $payload->enumOrString('type', DirectoryType::class),
            $payload->optionalInt('numberOfUnreadMessages'),
            $payload->optionalInt('total'),
        );
    }
}
