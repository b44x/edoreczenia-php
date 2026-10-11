<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;

/**
 * A page of message archive tasks ("MessageArchivingTaskResponse").
 */
final readonly class ArchiveTaskList
{
    /**
     * @param list<ArchiveTask> $tasks
     * @param int|null          $total number of tasks matching the query (may exceed the page)
     */
    public function __construct(
        public array $tasks,
        public ?int $total,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self(array_map(ArchiveTask::fromPayload(...), $payload->objectList('tasks')), $payload->optionalInt('total'));
    }

    /**
     * The task with this id on this page, or null.
     */
    public function find(string $taskId): ?ArchiveTask
    {
        foreach ($this->tasks as $task) {
            if ($task->taskId === $taskId) {
                return $task;
            }
        }

        return null;
    }
}
