<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * One thread of the thread list ("ThreadWrapper"). First/last values refer to messages visible to the caller.
 */
final readonly class ThreadSummary
{
    /**
     * @param list<string> $caseIds
     * @param list<Party>  $senders
     * @param list<Party>  $recipients
     */
    public function __construct(
        public string $threadId,
        public ?bool $hasUnread = null,
        public array $caseIds = [],
        public array $senders = [],
        public array $recipients = [],
        public ?string $firstMessageTitle = null,
        public ?\DateTimeImmutable $firstMessageSubmissionDate = null,
        public ?\DateTimeImmutable $firstMessageEventDate = null,
        public ?\DateTimeImmutable $lastMessageSubmissionDate = null,
        public ?\DateTimeImmutable $lastMessageEventDate = null,
        public ?int $messageCount = null,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self(
            threadId: $payload->string('threadId'),
            hasUnread: $payload->optionalBool('opened'),
            caseIds: $payload->stringList('caseIDs'),
            senders: array_map(Party::fromContributor(...), $payload->objectList('senders')),
            recipients: array_map(Party::fromContributor(...), $payload->objectList('recipients')),
            firstMessageTitle: $payload->optionalString('firstMessageTitle'),
            firstMessageSubmissionDate: $payload->optionalDateTime('firstMessageSubmissionDate'),
            // The 3.0.8 contract spells these "firstMessagEventDate"/"lastMessagEventDate"; the corrected spelling is accepted too.
            firstMessageEventDate: $payload->optionalDateTime('firstMessagEventDate') ?? $payload->optionalDateTime('firstMessageEventDate'),
            lastMessageSubmissionDate: $payload->optionalDateTime('lastMessageSubmissionDate'),
            lastMessageEventDate: $payload->optionalDateTime('lastMessagEventDate') ?? $payload->optionalDateTime('lastMessageEventDate'),
            messageCount: $payload->optionalInt('messagesCount'),
        );
    }
}
