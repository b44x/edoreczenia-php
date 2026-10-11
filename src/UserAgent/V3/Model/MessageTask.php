<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * Answer to a send request ("MessageInfo", HTTP 202): the server accepted the message for asynchronous processing.
 *
 * Poll {@see \B4x\Edoreczenia\UserAgent\V3\UserAgentClient::messageTaskStatus()} with {@see $messageTaskId} and
 * read the per-recipient outcome with {@see \B4x\Edoreczenia\UserAgent\V3\UserAgentClient::messageTask()}.
 */
final readonly class MessageTask
{
    /**
     * @param string|null $messageTaskId identifier of the send task; the contract does not mark it as required, so it is
     *                                   null if the server omitted it. The message may still have been accepted: check the
     *                                   SENT folder instead of sending again.
     * @param string|null $warning       mailbox close to full
     */
    public function __construct(
        public ?string $messageTaskId,
        public ?string $warning = null,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self($payload->optionalString('messageTaskId'), $payload->optionalString('warning'));
    }
}
