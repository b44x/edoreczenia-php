<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * Metadata of a stored file ("FileMetadata").
 */
final readonly class FileInfo
{
    /**
     * @param int|null    $size bytes
     * @param string|null $alg  hash algorithm; the contract allows only "SHA-3"
     */
    public function __construct(
        public string $fileId,
        public ?string $fileName = null,
        public ?string $contentType = null,
        public ?int $size = null,
        public ?string $description = null,
        public ?string $alg = null,
        public ?string $hash = null,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self(
            $payload->string('fileId'),
            $payload->optionalString('filename'),
            $payload->optionalString('contentType'),
            $payload->optionalInt('size'),
            $payload->optionalString('description'),
            $payload->optionalString('alg'),
            $payload->optionalString('hash'),
        );
    }
}
