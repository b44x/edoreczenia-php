<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use B4x\Edoreczenia\Internal\Payload;
use B4x\Edoreczenia\UserAgent\V3\Enum\ArchiveTaskStatus;

/**
 * A message archive task ("MessageArchivingTask").
 */
final readonly class ArchiveTask
{
    /**
     * @param \DateTimeImmutable       $createdAt                  when the task was registered ("creationDate")
     * @param ArchiveTaskStatus|string $status                     "taskStatus"; values unknown to this SDK version are kept as strings
     * @param int                      $messageCount               number of messages in the task ("numberMessages")
     * @param bool                     $canDownloadAndChangeStatus the caller may download the archive and change the task status ("downloadAndChangeStatus")
     * @param \DateTimeImmutable|null  $downloadDeadline           until when the prepared archive can be downloaded ("dateForDownload")
     * @param bool|null                $deletesMessages            the task is finished with deletion of its messages ("withDeletingMessages")
     * @param string|null              $errorDescription           why the task failed
     * @param string|null              $estimatedTimeToComplete    estimated time until the archive is ready, "HH:MM" according to the contract
     * @param string|null              $description                text given when the task was created ("taskDescription")
     */
    public function __construct(
        public string $taskId,
        public \DateTimeImmutable $createdAt,
        public ArchiveTaskStatus|string $status,
        public int $messageCount,
        public bool $canDownloadAndChangeStatus,
        public ?\DateTimeImmutable $downloadDeadline = null,
        public ?bool $deletesMessages = null,
        public ?string $errorDescription = null,
        public ?string $estimatedTimeToComplete = null,
        public ?string $description = null,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self(
            taskId: $payload->string('taskId'),
            createdAt: $payload->dateTime('creationDate'),
            status: $payload->requiredEnumOrString('taskStatus', ArchiveTaskStatus::class),
            messageCount: $payload->int('numberMessages'),
            canDownloadAndChangeStatus: $payload->bool('downloadAndChangeStatus'),
            downloadDeadline: $payload->optionalDateTime('dateForDownload'),
            deletesMessages: $payload->optionalBool('withDeletingMessages'),
            errorDescription: $payload->optionalString('errorDescription'),
            estimatedTimeToComplete: $payload->optionalString('estimatedTimeToComplete'),
            description: $payload->optionalString('taskDescription'),
        );
    }

    public function isReadyToDownload(): bool
    {
        return $this->status === ArchiveTaskStatus::ReadyToDownload;
    }
}
