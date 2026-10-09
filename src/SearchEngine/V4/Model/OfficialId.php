<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Model;

use B4x\Edoreczenia\Internal\Payload;
use B4x\Edoreczenia\SearchEngine\V4\Enum\OfficialIdType;

final readonly class OfficialId
{
    public function __construct(
        public OfficialIdType|string $type,
        public string $id,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self($payload->requiredEnumOrString('referenceRegistry', OfficialIdType::class), $payload->string('id'));
    }
}
