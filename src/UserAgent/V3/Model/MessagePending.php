<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * HTTP 202 answer to opening a message ("MessagePending"): the message is still being transferred to the mailbox.
 *
 * The request was accepted and may already have had its legal effect. Nothing is returned yet; open the
 * message again later to obtain it. The SDK never repeats the call by itself.
 */
final readonly class MessagePending
{
    /**
     * @param string $status free-form status reported by the server (the contract defines no values)
     */
    public function __construct(
        public string $messageId,
        public string $status,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(string $messageId, Payload $payload): self
    {
        return new self($messageId, $payload->string('status'));
    }
}
