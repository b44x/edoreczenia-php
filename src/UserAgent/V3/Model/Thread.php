<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * One thread with a page of its messages ("Thread"). Messages are returned like the message list (UC045),
 * so fetching a thread does not open its messages.
 */
final readonly class Thread
{
    public function __construct(
        public ?string $threadId,
        public MessageList $messages,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        $messages = $payload->optionalObject('messages');

        return new self(
            $payload->optionalString('threadId'),
            $messages === null ? new MessageList([], null) : MessageList::fromPayload($messages),
        );
    }
}
