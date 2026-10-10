<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * Result of GET /{eDeliveryAddress}/threads ("Threads").
 */
final readonly class ThreadList
{
    /**
     * @param list<ThreadSummary> $threads
     */
    public function __construct(
        public array $threads,
        public ?int $total,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        // The contract names the array property "Thread" (capitalised, singular); "threads" is accepted as well.
        $key = $payload->has('Thread') ? 'Thread' : 'threads';

        return new self(
            array_map(ThreadSummary::fromPayload(...), $payload->objectList($key)),
            $payload->optionalInt('total'),
        );
    }
}
