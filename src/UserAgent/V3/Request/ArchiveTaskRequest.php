<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Request;

use B4x\Edoreczenia\Exception\InvalidArgumentException;

/**
 * Body of POST /{eDeliveryAddress}/message-archive-task ("MessageArchiveTaskPostBody"), built immutably.
 *
 * The contract defines exactly two criteria: the ids of the messages to archive (required) and an optional
 * description of the task. It sets no upper limit on the number of messages.
 */
final class ArchiveTaskRequest
{
    /** "maxLength" of "taskDescription" in the contract. */
    public const MAX_DESCRIPTION_LENGTH = 255;

    private ?string $description = null;

    /**
     * @param non-empty-list<string> $messageIds
     */
    private function __construct(private readonly array $messageIds) {}

    /**
     * Archive of the given messages; duplicates are sent once, in the order given.
     */
    public static function forMessages(string $messageId, string ...$more): self
    {
        $ids = [];
        foreach ([$messageId, ...$more] as $id) {
            if ($id === '' || preg_match('/\s/u', $id) === 1) {
                throw new InvalidArgumentException('Message id must be a non-empty string without whitespace.');
            }
            $ids[$id] = $id;
        }

        return new self(array_values($ids));
    }

    /**
     * Free text stored with the task ("taskDescription"), at most 255 characters.
     */
    public function withDescription(string $description): self
    {
        if (trim($description) === '') {
            throw new InvalidArgumentException('taskDescription must not be empty.');
        }
        if (mb_strlen($description, 'UTF-8') > self::MAX_DESCRIPTION_LENGTH) {
            throw new InvalidArgumentException(\sprintf('taskDescription must not exceed %d characters.', self::MAX_DESCRIPTION_LENGTH));
        }

        $request = clone $this;
        $request->description = $description;

        return $request;
    }

    /**
     * @return non-empty-list<string>
     */
    public function messageIds(): array
    {
        return $this->messageIds;
    }

    /**
     * @internal
     *
     * @return array{messageId: non-empty-list<string>, taskDescription?: string}
     */
    public function toPayload(): array
    {
        $payload = ['messageId' => $this->messageIds];
        if ($this->description !== null) {
            $payload['taskDescription'] = $this->description;
        }

        return $payload;
    }
}
