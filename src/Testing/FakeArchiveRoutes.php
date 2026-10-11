<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Testing;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Deletion and archive routes of {@see FakeEdoreczenia}, kept in memory per fake instance and per mailbox.
 *
 *  - DELETE {mailbox}/messages/{id,id,…} deletes listed synthetic messages (and {@see SyntheticData::PENDING_MESSAGE_ID}).
 *    Deleted messages disappear from the message list, threads and mailbox evidences of this fake instance, and
 *    any other request naming them fails with HTTP 400, like an unknown id. An unknown or already deleted id
 *    fails the whole request with HTTP 400 and deletes nothing. Deleting does not mark a message as opened;
 *    whether the real service does is not specified;
 *  - POST {mailbox}/message-archive-task answers HTTP 202 with a new "taskId". The task is listed as
 *    ARCHIVING_IN_PROGRESS the first time it is read and READY_TO_DOWNLOAD afterwards;
 *  - GET {mailbox}/message-archive-task/{taskId}/download returns a synthetic ZIP (one JSON file with the listed
 *    metadata of each message and a README) while the task is READY_TO_DOWNLOAD;
 *  - PATCH {mailbox}/message-archive-task: CANCEL (before finishing) → CANCELED; FINISH_WITHOUT_DELETING_MESSAGES
 *    and FINISH_WITH_DELETING_MESSAGES (when ready) → FINISHED, the latter deleting the task's messages. The
 *    intermediate *_IN_PROGRESS states of the real service are skipped;
 *  - GET {mailbox}/messages/{id}/message-archive-file returns a synthetic ZIP of one message.
 *
 * Archiving never marks a message as opened here. The ZIP files are synthetic and are not real e-Doręczenia archives.
 *
 * @internal part of {@see FakeEdoreczenia}
 */
trait FakeArchiveRoutes
{
    /** @var array<string, array<string, true>> mailbox => ids of deleted messages */
    private array $deletedMessages = [];

    /** @var array<string, array<string, array{task: array<string, mixed>, messageIds: list<string>, reads: int}>> mailbox => task id => task */
    private array $archiveTasks = [];

    /**
     * @param list<string> $s decoded path segments
     */
    private function archiveRoute(RequestInterface $request, array $s, string $path): ?ResponseInterface
    {
        $n = \count($s);
        $at = static fn(int $fromEnd): ?string => $s[$n - $fromEnd] ?? null;
        $method = $request->getMethod();

        [$name, $pathMailbox] = match (true) {
            $method === 'DELETE' && $n >= 3 && $at(2) === 'messages' => ['delete', $at(3)],
            $n >= 2 && $at(1) === 'message-archive-task' => ['task', $at(2)],
            $method === 'GET' && $n >= 2 && $at(1) === 'message-archive-task-list' => ['list', $at(2)],
            $method === 'GET' && $n >= 4 && $at(3) === 'message-archive-task' && $at(1) === 'download' => ['download', $at(4)],
            $method === 'GET' && $n >= 4 && $at(3) === 'messages' && $at(1) === 'message-archive-file' => ['messageArchive', $at(4)],
            default => [null, null],
        };

        $mailbox = $this->mailboxOf($request);
        if ($name === null) {
            return $mailbox === null ? null : $this->withoutDeletedMessages($request, $s, $mailbox);
        }
        if ($mailbox === null) {
            return $this->uaError(401, 'unauthorized', 'Missing or unknown bearer token');
        }
        if ($pathMailbox !== $mailbox) {
            return $this->uaError(403, 'forbidden', 'The access token was not issued for this mailbox');
        }

        return match ($name) {
            'delete' => $this->deleteMessages($mailbox, (string) $at(1)),
            'task' => match ($method) {
                'POST' => $this->createArchiveTask($request, $mailbox),
                'PATCH' => $this->changeArchiveTask($request, $mailbox),
                default => $this->uaError(405, 'method_not_allowed', \sprintf('%s %s is not supported by FakeEdoreczenia.', $method, $path)),
            },
            'list' => $this->listArchiveTasks($request, $mailbox),
            'download' => $this->downloadArchive($mailbox, (string) $at(2)),
            default => $this->deletableMessage($mailbox, (string) $at(2)) === null
                ? $this->unknownMessages([(string) $at(2)])
                : $this->binary('application/zip', $at(2) . '-archive.zip', self::archiveZip([(string) $at(2)])),
        };
    }

    /**
     * Hides deleted messages: filtered message, thread and evidence lists; HTTP 400 for requests naming them.
     * Returns null when nothing was deleted from the mailbox or the request is not affected.
     *
     * @param list<string> $s decoded path segments
     */
    private function withoutDeletedMessages(RequestInterface $request, array $s, string $mailbox): ?ResponseInterface
    {
        $deleted = $this->deletedMessages[$mailbox] ?? [];
        if ($deleted === [] || !\in_array($mailbox, $s, true)) {
            return null;
        }

        foreach ($s as $segment) {
            if (isset($deleted[$segment])) {
                return $this->unknownMessages([$segment]);
            }
        }
        $body = json_decode((string) $request->getBody(), true);
        $bodyId = \is_array($body) ? ($body['messageId'] ?? null) : null;
        if (\is_string($bodyId) && isset($deleted[$bodyId]) && $request->getMethod() === 'POST' && end($s) === 'download') {
            return $this->unknownMessages([$bodyId]);
        }

        if ($request->getMethod() !== 'GET') {
            return null;
        }
        $n = \count($s);
        $at = static fn(int $fromEnd): ?string => $s[$n - $fromEnd] ?? null;
        parse_str($request->getUri()->getQuery(), $query);
        $present = array_values(array_filter(SyntheticData::MESSAGES, static fn(array $m): bool => !isset($deleted[$m['messageMetadata']['messageId']])));

        if ($at(1) === 'messages' && $at(2) === $mailbox) {
            $page = array_map($this->withOpenedFlag(...), self::page($present, $query['offset'] ?? 0, $query['limit'] ?? null));
            $electronic = array_filter($present, static fn(array $m): bool => $m['messageMetadata']['shippingService'] === 'electronic');

            return $this->json(200, ['messages' => $page, 'resultSize' => \count($page), 'total' => \count($present), 'electronicCount' => \count($electronic)]);
        }
        if ($at(2) === 'threads' && $at(3) === $mailbox) {
            $id = (string) $at(1);
            if (array_filter(SyntheticData::THREADS, static fn(array $t): bool => $t['threadId'] === $id) === []) {
                return null;
            }
            $messages = array_values(array_filter($present, static fn(array $m): bool => ($m['messageMetadata']['threadId'] ?? null) === $id));
            $page = array_map($this->withOpenedFlag(...), self::page($messages, $query['offset'] ?? 0, $query['limit'] ?? null));

            return $this->json(200, ['threadId' => $id, 'messages' => ['messages' => $page, 'resultSize' => \count($page), 'total' => \count($messages)]]);
        }
        if ($at(1) === 'evidences' && $at(2) === 'messages' && $at(3) === $mailbox) {
            return $this->json(200, ['evidences' => array_values(array_filter(self::evidences($query, null), static fn(array $e): bool => !\is_string($e['messageId'] ?? null) || !isset($deleted[$e['messageId']])))]);
        }

        return null;
    }

    private function deleteMessages(string $mailbox, string $ids): ResponseInterface
    {
        $list = explode(',', $ids);
        if (\count($list) > 50) {
            return $this->uaError(400, 'INVALID_PARAMETER', 'At most 50 message ids are allowed');
        }
        $unknown = array_values(array_filter($list, fn(string $id): bool => $this->deletableMessage($mailbox, $id) === null));
        if ($unknown !== []) {
            return $this->unknownMessages($unknown);
        }
        foreach ($list as $id) {
            $this->deletedMessages[$mailbox][$id] = true;
        }

        return $this->json(200, array_map(static fn(string $id): array => ['messageId' => $id], $list));
    }

    private function createArchiveTask(RequestInterface $request, string $mailbox): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        $requested = \is_array($body) ? ($body['messageId'] ?? null) : null;
        $description = \is_array($body) ? ($body['taskDescription'] ?? null) : null;
        $ids = \is_array($requested) && array_is_list($requested) ? array_values(array_filter($requested, \is_string(...))) : [];
        if ($ids === [] || $ids !== $requested) {
            return $this->uaError(400, 'INVALID_REQUEST', 'messageId must be a non-empty array of message ids');
        }
        if ($description !== null && (!\is_string($description) || mb_strlen($description, 'UTF-8') > 255)) {
            return $this->uaError(400, 'INVALID_REQUEST', 'taskDescription must be a string of at most 255 characters');
        }
        $unknown = array_values(array_filter($ids, fn(string $id): bool => $this->deletableMessage($mailbox, $id) === null));
        if ($unknown !== []) {
            return $this->unknownMessages($unknown);
        }

        $taskId = self::fakeUuid();
        $task = [
            'taskId' => $taskId,
            'creationDate' => gmdate('Y-m-d\TH:i:s\Z'),
            'taskStatus' => 'ARCHIVING_IN_PROGRESS',
            'numberMessages' => \count($ids),
            'downloadAndChangeStatus' => true,
            'estimatedTimeToComplete' => '00:01',
        ];
        if (\is_string($description)) {
            $task['taskDescription'] = $description;
        }
        $this->archiveTasks[$mailbox][$taskId] = ['task' => $task, 'messageIds' => $ids, 'reads' => 0];

        return $this->json(202, ['taskId' => $taskId]);
    }

    private function listArchiveTasks(RequestInterface $request, string $mailbox): ResponseInterface
    {
        parse_str($request->getUri()->getQuery(), $query);
        $taskId = $query['taskId'] ?? null;
        $ids = array_keys($this->archiveTasks[$mailbox] ?? []);
        if (\is_string($taskId)) {
            $ids = \in_array($taskId, $ids, true) ? [$taskId] : [];
        }
        if (($query['sortDirection'] ?? null) === 'desc') {
            $ids = array_reverse($ids);
        }

        $page = [];
        foreach (self::page(array_map(strval(...), $ids), $query['offset'] ?? 0, $query['limit'] ?? 20) as $id) {
            $page[] = $this->observeArchiveTask($mailbox, $id);
        }

        return $this->json(200, ['total' => \count($ids), 'tasks' => $page]);
    }

    /**
     * The task as listed now; the first read sees it in progress, every later one ready to download.
     *
     * @return array<string, mixed>
     */
    private function observeArchiveTask(string $mailbox, string $taskId): array
    {
        $entry = &$this->archiveTasks[$mailbox][$taskId];
        ++$entry['reads'];
        if ($entry['reads'] > 1 && $entry['task']['taskStatus'] === 'ARCHIVING_IN_PROGRESS') {
            $created = new \DateTimeImmutable(\is_string($entry['task']['creationDate']) ? $entry['task']['creationDate'] : 'now');
            $entry['task']['taskStatus'] = 'READY_TO_DOWNLOAD';
            $entry['task']['dateForDownload'] = $created->modify('+7 days')->format('Y-m-d\TH:i:s\Z');
            unset($entry['task']['estimatedTimeToComplete']);
        }

        return $entry['task'];
    }

    private function changeArchiveTask(RequestInterface $request, string $mailbox): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        $taskId = \is_array($body) ? ($body['taskId'] ?? null) : null;
        $change = \is_array($body) ? ($body['changeStatus'] ?? null) : null;
        if (!\is_string($taskId) || !isset($this->archiveTasks[$mailbox][$taskId])) {
            return $this->uaError(400, 'INVALID_PARAMETER', 'Unknown archive task id');
        }
        $entry = $this->archiveTasks[$mailbox][$taskId];
        $status = $entry['task']['taskStatus'];

        if ($change === 'CANCEL' && \in_array($status, ['ARCHIVING_IN_PROGRESS', 'READY_TO_DOWNLOAD'], true)) {
            $entry['task']['taskStatus'] = 'CANCELED';
        } elseif (\in_array($change, ['FINISH_WITH_DELETING_MESSAGES', 'FINISH_WITHOUT_DELETING_MESSAGES'], true) && $status === 'READY_TO_DOWNLOAD') {
            $withDeleting = $change === 'FINISH_WITH_DELETING_MESSAGES';
            $entry['task']['taskStatus'] = 'FINISHED';
            $entry['task']['withDeletingMessages'] = $withDeleting;
            if ($withDeleting) {
                foreach ($entry['messageIds'] as $id) {
                    $this->deletedMessages[$mailbox][$id] = true;
                }
            }
        } elseif (!\in_array($change, ['CANCEL', 'FINISH_WITH_DELETING_MESSAGES', 'FINISH_WITHOUT_DELETING_MESSAGES'], true)) {
            return $this->uaError(400, 'INVALID_REQUEST', 'changeStatus must be CANCEL, FINISH_WITH_DELETING_MESSAGES or FINISH_WITHOUT_DELETING_MESSAGES');
        } else {
            return $this->uaError(400, 'INVALID_STATUS', \sprintf('The task cannot be changed in status %s', \is_string($status) ? $status : ''));
        }
        $this->archiveTasks[$mailbox][$taskId] = $entry;

        return $this->json(202, ['total' => 1, 'tasks' => [$entry['task']]]);
    }

    private function downloadArchive(string $mailbox, string $taskId): ResponseInterface
    {
        $entry = $this->archiveTasks[$mailbox][$taskId] ?? null;
        if ($entry === null) {
            return $this->uaError(400, 'INVALID_PARAMETER', 'Unknown archive task id');
        }
        if ($entry['task']['taskStatus'] !== 'READY_TO_DOWNLOAD') {
            return $this->uaError(400, 'INVALID_STATUS', 'The archive is not ready to download');
        }

        return $this->binary('application/zip', $taskId . '.zip', self::archiveZip($entry['messageIds']));
    }

    /**
     * The listed form of a message of the mailbox that has not been deleted, or null.
     *
     * @return array<string, mixed>|null
     */
    private function deletableMessage(string $mailbox, string $id): ?array
    {
        if (isset($this->deletedMessages[$mailbox][$id])) {
            return null;
        }

        return $id === SyntheticData::PENDING_MESSAGE_ID ? SyntheticData::PENDING_MESSAGE : self::message($id);
    }

    /**
     * HTTP 400 with one "ErrorInfo" per message id.
     *
     * @param list<string> $ids
     */
    private function unknownMessages(array $ids): ResponseInterface
    {
        return $this->json(400, array_map(static fn(string $id): array => ['error' => 'INVALID_PARAMETER', 'error_description' => 'Unknown message id', 'messageId' => $id], $ids));
    }

    /**
     * Synthetic archive: README.txt and "{messageId}.json" with the listed metadata of each message.
     *
     * @param list<string> $messageIds
     */
    private static function archiveZip(array $messageIds): string
    {
        $files = ['README.txt' => "Synthetic message archive served by FakeEdoreczenia.\nNot a real archive from e-Doręczenia.\n"];
        foreach ($messageIds as $id) {
            $message = $id === SyntheticData::PENDING_MESSAGE_ID ? SyntheticData::PENDING_MESSAGE : self::message($id);
            $files[$id . '.json'] = json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
        }

        return self::storedZip($files);
    }

    /**
     * Minimal ZIP archive with stored (uncompressed) entries; built without ext-zip.
     *
     * @param array<string, string> $files name => content
     */
    private static function storedZip(array $files): string
    {
        $dosDate = (0 << 9) | (1 << 5) | 1; // 1980-01-01
        $local = '';
        $central = '';
        foreach ($files as $name => $content) {
            $name = (string) $name;
            $crc = crc32($content);
            $size = \strlen($content);
            $offset = \strlen($local);
            $local .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, $dosDate, $crc, $size, $size, \strlen($name), 0) . $name . $content;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 0, 0, $dosDate, $crc, $size, $size, \strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        }

        return $local . $central . pack('VvvvvVVv', 0x06054B50, 0, 0, \count($files), \count($files), \strlen($central), \strlen($local), 0);
    }
}
