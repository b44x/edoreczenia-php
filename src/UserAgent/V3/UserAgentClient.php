<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3;

use B4x\Edoreczenia\Exception\EdoreczeniaException;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\Exception\UnexpectedResponseException;
use B4x\Edoreczenia\Internal\PathSegment;
use B4x\Edoreczenia\Internal\Payload;
use B4x\Edoreczenia\Internal\Transport;
use B4x\Edoreczenia\UserAgent\V3\Enum\ArchiveTaskStatus;
use B4x\Edoreczenia\UserAgent\V3\Enum\EvidenceService;
use B4x\Edoreczenia\UserAgent\V3\Enum\MessageTaskState;
use B4x\Edoreczenia\UserAgent\V3\Model\ArchiveTask;
use B4x\Edoreczenia\UserAgent\V3\Model\ArchiveTaskList;
use B4x\Edoreczenia\UserAgent\V3\Model\DeletedMessage;
use B4x\Edoreczenia\UserAgent\V3\Model\Directory;
use B4x\Edoreczenia\UserAgent\V3\Model\Download;
use B4x\Edoreczenia\UserAgent\V3\Model\Draft;
use B4x\Edoreczenia\UserAgent\V3\Model\Evidence;
use B4x\Edoreczenia\UserAgent\V3\Model\EvidenceList;
use B4x\Edoreczenia\UserAgent\V3\Model\FileInfo;
use B4x\Edoreczenia\UserAgent\V3\Model\MailboxSettings;
use B4x\Edoreczenia\UserAgent\V3\Model\MessageAttachment;
use B4x\Edoreczenia\UserAgent\V3\Model\MessageList;
use B4x\Edoreczenia\UserAgent\V3\Model\MessagePending;
use B4x\Edoreczenia\UserAgent\V3\Model\MessageTask;
use B4x\Edoreczenia\UserAgent\V3\Model\MessageTaskItem;
use B4x\Edoreczenia\UserAgent\V3\Model\OpenedMessage;
use B4x\Edoreczenia\UserAgent\V3\Model\SavedDraft;
use B4x\Edoreczenia\UserAgent\V3\Model\Thread;
use B4x\Edoreczenia\UserAgent\V3\Model\ThreadList;
use B4x\Edoreczenia\UserAgent\V3\Model\UploadedAttachment;
use B4x\Edoreczenia\UserAgent\V3\Request\ArchiveTaskQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\ArchiveTaskRequest;
use B4x\Edoreczenia\UserAgent\V3\Request\AttachmentUpload;
use B4x\Edoreczenia\UserAgent\V3\Request\DraftQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\EvidenceQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\MessageQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingAttachment;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingMessage;
use B4x\Edoreczenia\UserAgent\V3\Request\ThreadMessageQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\ThreadQuery;
use Psr\Http\Message\ResponseInterface;

/**
 * User Agent API v3 (contract 3.0.8) of the designated operator.
 *
 * Status: implemented from the official contract only, NOT yet verified against INT or PROD.
 *
 * Read-only operations are retried when a retry policy is configured. Opening a message, sending
 * messages and drafts, and changing drafts have side effects and are never retried automatically.
 */
final class UserAgentClient
{
    /**
     * @internal use {@see \B4x\Edoreczenia\EdoreczeniaClient::userAgent()}
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly string $baseUri,
        private readonly string $mailboxAddress,
    ) {}

    /**
     * Country names accepted in postal addresses (GET /dictionary/countries).
     *
     * @return list<string>
     *
     * @throws EdoreczeniaException
     */
    public function countries(): array
    {
        $data = $this->transport->getJson($this->baseUri . 'dictionary/countries', [], 'UA API v3 dictionary/countries', true);

        return Payload::rootStringList($data);
    }

    /**
     * Folders of the mailbox (GET /{eDeliveryAddress}/directories).
     *
     * @return list<Directory>
     *
     * @throws EdoreczeniaException
     */
    public function directories(): array
    {
        return array_map(Directory::fromPayload(...), Payload::rootObjectList($this->get('directories', [], 'directories')));
    }

    /**
     * Folders with total and unread message counts (GET /{eDeliveryAddress}/numberMessages).
     *
     * @return list<Directory>
     *
     * @throws EdoreczeniaException
     */
    public function directoriesWithCounts(): array
    {
        return array_map(Directory::fromPayload(...), Payload::rootObjectList($this->get('numberMessages', [], 'numberMessages')));
    }

    /**
     * Notification settings and capacity (GET /{eDeliveryAddress}/settings). Requires the "read_settings" permission.
     *
     * @throws EdoreczeniaException
     */
    public function settings(): MailboxSettings
    {
        return MailboxSettings::fromPayload(Payload::root($this->get('settings', [], 'settings')));
    }

    /**
     * Lists messages (GET /{eDeliveryAddress}/messages) in "minimal" (default) or "metadata" format.
     *
     * Listing does not open messages and has no legal effect.
     *
     * @throws EdoreczeniaException
     */
    public function listMessages(?MessageQuery $query = null): MessageList
    {
        $data = $this->get('messages', ($query ?? MessageQuery::create())->toQuery(), 'messages');

        return MessageList::fromPayload(Payload::root($data));
    }

    // --- Receiving (legal effect) ---

    /**
     * Opens (reads) one message: GET /{eDeliveryAddress}/messages/{messageId} in "full" format, or
     * "fullExtended" with $extended.
     *
     * LEGAL EFFECT: opening marks the message as opened and, when the mailbox owner is not a public entity,
     * triggers the transfer of the message and the E.1 delivery evidence – the message counts as delivered.
     * Call it only when the message is really meant to be received, not to preview or poll. To browse
     * messages without this effect use {@see listMessages()} or {@see getThread()}.
     *
     * Never retried, even with a retry policy: after a {@see \B4x\Edoreczenia\Exception\TransportException}
     * or a 5xx error the message may already be opened; check its "opened" flag with {@see listMessages()}
     * before calling again.
     *
     * The server may answer HTTP 202 while the message is still being transferred; this returns
     * {@see MessagePending} and the caller opens the message again later.
     *
     * "full" lists attachments without their content; download them with {@see downloadAttachment()}.
     * "fullExtended" also embeds the content of every attachment in the JSON response, which the SDK reads
     * into memory and caps at 10 MiB, so it suits small attachments only.
     *
     * Opens exactly one message per call: the contract also accepts up to 50 comma-separated ids, which
     * this method deliberately does not expose.
     *
     * Implemented from the official contract only; NOT yet verified against INT or PROD.
     *
     * @throws EdoreczeniaException
     */
    public function openMessage(string $messageId, bool $extended = false): OpenedMessage|MessagePending
    {
        if (str_contains(self::id('Message id', $messageId), ',')) {
            throw new InvalidArgumentException('Message id must not contain ","; open one message per call.');
        }

        [$status, $data] = $this->transport->getJsonWithStatus(
            $this->baseUri . PathSegment::encode($this->mailboxAddress) . '/messages/' . PathSegment::encode($messageId),
            ['format' => $extended ? 'fullExtended' : 'full'],
            'UA API v3 messages/{messageId} (open)',
            false,
            [$this->mailboxAddress],
        );

        if ($status === 202) {
            return MessagePending::fromPayload($messageId, Payload::root($data));
        }
        if ($status !== 200) {
            throw new UnexpectedResponseException(\sprintf('Unexpected response: HTTP %d is not defined for opening a message.', $status), $status);
        }

        $messages = Payload::rootObjectList($data);
        if (\count($messages) !== 1) {
            throw new UnexpectedResponseException(\sprintf('Unexpected response: exactly one message expected at "$", got %d.', \count($messages)), $status);
        }

        return OpenedMessage::fromPayload($messages[0]);
    }

    /**
     * Streams one attachment of a received message (POST /{eDeliveryAddress}/attachments/download).
     *
     * This is the octet-stream endpoint introduced for large files (over 15 MB); the contract does not limit
     * it to them, so it is used for every size and the file never has to fit in memory. Take the ids from
     * {@see openMessage()}. The returned content type is the transport type; the attachment's own type is in
     * {@see MessageAttachment::$contentType}.
     *
     * Downloading has no documented side effect, so it is retried when a retry policy is configured.
     * Implemented from the official contract only; NOT yet verified against INT or PROD.
     *
     * @throws EdoreczeniaException
     */
    public function downloadAttachment(string $messageId, string $attachmentId): Download
    {
        $response = $this->transport->postForStream(
            $this->baseUri . PathSegment::encode($this->mailboxAddress) . '/attachments/download',
            ['messageId' => self::id('Message id', $messageId), 'attachmentId' => self::id('Attachment id', $attachmentId)],
            'UA API v3 attachments/download',
            true,
            [$this->mailboxAddress],
        );

        return Download::fromResponse($response);
    }

    /**
     * One attachment with its content embedded in JSON (GET /{eDeliveryAddress}/messages/{messageId}/attachments/{attachmentId}).
     *
     * The whole response is held in memory and capped at 10 MiB, so this suits small files only; prefer
     * {@see downloadAttachment()}. Decode the content with {@see MessageAttachment::decodeContent()}.
     *
     * No documented side effect; retried when a retry policy is configured.
     * Implemented from the official contract only; NOT yet verified against INT or PROD.
     *
     * @throws EdoreczeniaException
     */
    public function getAttachment(string $messageId, string $attachmentId): MessageAttachment
    {
        $path = 'messages/' . PathSegment::encode(self::id('Message id', $messageId)) . '/attachments/' . PathSegment::encode(self::id('Attachment id', $attachmentId));

        return MessageAttachment::fromPayload(Payload::root($this->get($path, [], 'messages/{messageId}/attachments/{attachmentId}')));
    }

    // --- End of receiving ---

    // --- Deleting and archives ---

    /** Maximum number of messages per {@see deleteMessages()} call ("maxItems" of the path parameter). */
    public const MAX_DELETED_MESSAGES = 50;

    /**
     * DESTRUCTIVE: deletes up to 50 messages of the mailbox (DELETE /{eDeliveryAddress}/messages/{messageId}, ids
     * joined with ","). Requires the "write_messages" permission.
     *
     * What the contract says about the effect is only "Usunięcie wiadomości o podanym id" (deletion of the message
     * with the given id). It does NOT say whether the message is moved to a trash folder or removed permanently,
     * whether it can be restored, what happens to its evidences, nor whether deleting a message that has not been
     * opened yet opens it (marks it as opened, with the legal effect described at {@see openMessage()}). All of
     * this is unspecified: do not rely on either outcome. To keep a copy, download it first with
     * {@see downloadMessageArchive()} or an archive task ({@see createArchiveTask()}).
     *
     * NEVER retried automatically, whatever the retry policy. After a
     * {@see \B4x\Edoreczenia\Exception\TransportException} or a 5xx error some or all of the messages may already
     * be deleted; check with {@see listMessages()} before calling again. How a request mixing deletable and
     * non-deletable messages is answered is not specified either; the contract's error entries may name the
     * affected message ({@see \B4x\Edoreczenia\Exception\BadRequestException}).
     *
     * Implemented from the official contract only; NOT yet verified against INT or PROD.
     *
     * @return list<DeletedMessage> one entry per message, as reported by the server
     *
     * @throws EdoreczeniaException
     */
    public function deleteMessages(string $messageId, string ...$more): array
    {
        $ids = [$messageId, ...$more];
        if (\count($ids) > self::MAX_DELETED_MESSAGES) {
            throw new InvalidArgumentException(\sprintf('At most %d messages can be deleted at once.', self::MAX_DELETED_MESSAGES));
        }
        $segments = [];
        foreach ($ids as $id) {
            if (str_contains(self::id('Message id', $id), ',')) {
                throw new InvalidArgumentException('Message id must not contain ",".');
            }
            $segments[] = PathSegment::encode($id);
        }

        return array_map(DeletedMessage::fromPayload(...), Payload::rootObjectList($this->delete('messages/' . implode(',', $segments), 'DELETE messages/{messageId}')));
    }

    /**
     * Orders a ZIP archive of the given messages (POST /{eDeliveryAddress}/message-archive-task) and returns the id
     * of the archive task. The archive is prepared asynchronously: poll {@see archiveTask()} until it is
     * {@see ArchiveTaskStatus::ReadyToDownload}, then call {@see downloadArchive()}, then finish the task with
     * {@see finishArchiveTask()} or {@see finishArchiveTaskAndDeleteMessages()}.
     *
     * Creates server state and has no idempotency key, so it is NEVER retried automatically; after a transport or
     * 5xx error look for the task with {@see listArchiveTasks()} before ordering it again. The contract declares
     * only the "read_messages" permission for it. It does not say whether archiving a message that has not been
     * opened yet opens it; that is unspecified.
     *
     * Implemented from the official contract only; NOT yet verified against INT or PROD.
     *
     * @throws EdoreczeniaException
     */
    public function createArchiveTask(ArchiveTaskRequest $request): string
    {
        return Payload::root($this->post('message-archive-task', $request->toPayload(), 'POST message-archive-task'))->string('taskId');
    }

    /**
     * Lists message archive tasks (GET /{eDeliveryAddress}/message-archive-task-list).
     * Read-only, so it is retried when a retry policy is configured. Not yet verified against INT or PROD.
     *
     * @throws EdoreczeniaException
     */
    public function listArchiveTasks(?ArchiveTaskQuery $query = null): ArchiveTaskList
    {
        $data = $this->get('message-archive-task-list', ($query ?? ArchiveTaskQuery::create())->toQuery(), 'message-archive-task-list');

        return ArchiveTaskList::fromPayload(Payload::root($data));
    }

    /**
     * One archive task, read with the "taskId" filter of {@see listArchiveTasks()}; null when the server does not
     * list it. Read-only and retried when a retry policy is configured. Not yet verified against INT or PROD.
     *
     * @throws EdoreczeniaException
     */
    public function archiveTask(string $taskId): ?ArchiveTask
    {
        return $this->listArchiveTasks(ArchiveTaskQuery::create()->forTask($taskId))->find($taskId);
    }

    /**
     * Streams the ZIP prepared by an archive task (GET /{eDeliveryAddress}/message-archive-task/{taskId}/download).
     * Save it with {@see Download::saveTo()}; it is never buffered by the SDK.
     *
     * No side effect is documented, so it is retried when a retry policy is configured. The contract does not say
     * whether downloading opens messages that were not opened yet; that is unspecified.
     * Not yet verified against INT or PROD.
     *
     * @throws EdoreczeniaException
     */
    public function downloadArchive(string $taskId): Download
    {
        $path = 'message-archive-task/' . PathSegment::encode(self::id('Archive task id', $taskId)) . '/download';

        return Download::fromResponse($this->stream($path, [], 'message-archive-task/{taskId}/download'));
    }

    /**
     * Cancels an archive task (PATCH /{eDeliveryAddress}/message-archive-task, "changeStatus": "CANCEL").
     * The contract does not say in which states this is allowed. Never retried automatically.
     * Not yet verified against INT or PROD.
     *
     * @return ArchiveTaskList the server's answer ("MessageArchivingTaskResponse", HTTP 202)
     *
     * @throws EdoreczeniaException
     */
    public function cancelArchiveTask(string $taskId): ArchiveTaskList
    {
        return $this->changeArchiveTask($taskId, 'CANCEL');
    }

    /**
     * Finishes an archive task and keeps its messages in the mailbox (PATCH /{eDeliveryAddress}/message-archive-task,
     * "changeStatus": "FINISH_WITHOUT_DELETING_MESSAGES"). Download the archive first: the contract does not say
     * whether it stays available afterwards. Never retried automatically. Not yet verified against INT or PROD.
     *
     * @return ArchiveTaskList the server's answer ("MessageArchivingTaskResponse", HTTP 202)
     *
     * @throws EdoreczeniaException
     */
    public function finishArchiveTask(string $taskId): ArchiveTaskList
    {
        return $this->changeArchiveTask($taskId, 'FINISH_WITHOUT_DELETING_MESSAGES');
    }

    /**
     * DESTRUCTIVE: finishes an archive task and deletes its messages from the mailbox
     * (PATCH /{eDeliveryAddress}/message-archive-task, "changeStatus": "FINISH_WITH_DELETING_MESSAGES").
     *
     * The contract describes it only as finishing the task with deletion of the messages; whether they are removed
     * permanently or can be restored is unspecified, as for {@see deleteMessages()}. Download and verify the archive
     * ({@see downloadArchive()}) before calling this. The contract declares only the "read_messages" permission for
     * this operation.
     *
     * NEVER retried automatically; after a transport or 5xx error check the task with {@see archiveTask()} before
     * calling again. Not yet verified against INT or PROD.
     *
     * @return ArchiveTaskList the server's answer ("MessageArchivingTaskResponse", HTTP 202)
     *
     * @throws EdoreczeniaException
     */
    public function finishArchiveTaskAndDeleteMessages(string $taskId): ArchiveTaskList
    {
        return $this->changeArchiveTask($taskId, 'FINISH_WITH_DELETING_MESSAGES');
    }

    /**
     * Streams a ZIP with the content of one message, for local archiving
     * (GET /{eDeliveryAddress}/messages/{messageId}/message-archive-file). Never buffered by the SDK.
     *
     * No side effect is documented, so it is retried when a retry policy is configured. The contract does not say
     * whether this opens a message that was not opened yet; that is unspecified, so prefer it for messages already
     * opened with {@see openMessage()}. Not yet verified against INT or PROD.
     *
     * @throws EdoreczeniaException
     */
    public function downloadMessageArchive(string $messageId): Download
    {
        $path = 'messages/' . PathSegment::encode(self::id('Message id', $messageId)) . '/message-archive-file';

        return Download::fromResponse($this->stream($path, [], 'messages/{messageId}/message-archive-file'));
    }

    /**
     * Side-effecting PATCH: never retried, whatever the retry policy.
     */
    private function changeArchiveTask(string $taskId, string $change): ArchiveTaskList
    {
        $data = $this->transport->patchJson(
            $this->mailboxUri('message-archive-task'),
            ['taskId' => self::id('Archive task id', $taskId), 'changeStatus' => $change],
            'UA API v3 PATCH message-archive-task',
            false,
            [$this->mailboxAddress],
        );

        return ArchiveTaskList::fromPayload(Payload::root($data));
    }

    // --- End of deleting and archives ---

    /**
     * Lists conversation threads (GET /{eDeliveryAddress}/threads). Does not open messages.
     *
     * @throws EdoreczeniaException
     */
    public function listThreads(?ThreadQuery $query = null): ThreadList
    {
        $data = $this->get('threads', ($query ?? ThreadQuery::create())->toQuery(), 'threads');

        return ThreadList::fromPayload(Payload::root($data));
    }

    /**
     * One thread with a page of its messages (GET /{eDeliveryAddress}/threads/{threadId}).
     * Messages are listed, not opened (UC045), so this has no legal effect.
     *
     * @throws EdoreczeniaException
     */
    public function getThread(string $threadId, ?ThreadMessageQuery $query = null): Thread
    {
        $data = $this->get('threads/' . PathSegment::encode(self::id('Thread id', $threadId)), ($query ?? ThreadMessageQuery::create())->toQuery(), 'threads/{threadId}');

        return Thread::fromPayload(Payload::root($data));
    }

    /**
     * Evidences registered for one message (GET /{eDeliveryAddress}/messages/{messageId}/evidences), UC063-UC066.
     *
     * @throws EdoreczeniaException
     */
    public function messageEvidences(string $messageId, ?EvidenceQuery $query = null): EvidenceList
    {
        $path = 'messages/' . PathSegment::encode(self::id('Message id', $messageId)) . '/evidences';

        return EvidenceList::fromPayload(Payload::root($this->get($path, ($query ?? EvidenceQuery::create())->toQuery(), 'messages/{messageId}/evidences')));
    }

    /**
     * Evidences of all messages still present in the mailbox (GET /{eDeliveryAddress}/messages/evidences), UC067.
     *
     * @throws EdoreczeniaException
     */
    public function mailboxEvidences(?EvidenceQuery $query = null): EvidenceList
    {
        return EvidenceList::fromPayload(Payload::root($this->get('messages/evidences', ($query ?? EvidenceQuery::create())->toQuery(), 'messages/evidences')));
    }

    /**
     * Metadata of one evidence (GET /{eDeliveryAddress}/messages/evidences/{evidenceId}), UC068.
     * The contract returns an array.
     *
     * @return list<Evidence>
     *
     * @throws EdoreczeniaException
     */
    public function evidence(string $evidenceId): array
    {
        $path = 'messages/evidences/' . PathSegment::encode(self::id('Evidence id', $evidenceId));

        return array_map(Evidence::fromPayload(...), Payload::rootObjectList($this->get($path, [], 'messages/evidences/{evidenceId}')));
    }

    /**
     * Evidence file (GET /{eDeliveryAddress}/evidences/{messageType}/{evidenceId}), UC106. Returned as a stream.
     *
     * With $visualisationPdf the server returns a PDF presentation of an H.PN evidence (hybrid service only);
     * that PDF is not signed and is not itself evidence.
     *
     * The evidence list exposes a "downloaded" flag, so the server presumably records downloads; repeating a
     * download is harmless. Not yet verified against a live environment.
     *
     * @throws EdoreczeniaException
     */
    public function downloadEvidence(EvidenceService $service, string $evidenceId, bool $visualisationPdf = false): Download
    {
        $path = 'evidences/' . $service->value . '/' . PathSegment::encode(self::id('Evidence id', $evidenceId));

        return Download::fromResponse($this->stream($path, $visualisationPdf ? ['evidenceFormat' => 'PDF'] : [], 'evidences/{messageType}/{evidenceId}'));
    }

    /**
     * H.EPO acknowledgement of receipt of a hybrid (PUH) message (GET /{eDeliveryAddress}/evidences/puhepo/{messageId}), UC108.
     * With $visualisationPdf a PDF presentation is returned; it is not signed and is not itself evidence.
     *
     * @throws EdoreczeniaException
     */
    public function downloadHybridAcknowledgement(string $messageId, bool $visualisationPdf = false): Download
    {
        $path = 'evidences/puhepo/' . PathSegment::encode(self::id('Message id', $messageId));

        return Download::fromResponse($this->stream($path, $visualisationPdf ? ['evidenceFormat' => 'PDF'] : [], 'evidences/puhepo/{messageId}'));
    }

    /**
     * ZIP with the technical evidences of one message, for local archiving
     * (GET /{eDeliveryAddress}/evidences/{messageId}/technical-evidences-file).
     *
     * @throws EdoreczeniaException
     */
    public function downloadTechnicalEvidences(string $messageId): Download
    {
        $path = 'evidences/' . PathSegment::encode(self::id('Message id', $messageId)) . '/technical-evidences-file';

        return Download::fromResponse($this->stream($path, [], 'evidences/{messageId}/technical-evidences-file'));
    }

    // --- Sending (legal effect) ---

    /** Maximum number of drafts per {@see deleteDrafts()} call ("maxItems" of the path parameter). */
    public const MAX_DELETED_DRAFTS = 50;

    /**
     * Sends a message (POST /{eDeliveryAddress}/messages): electronic, or hybrid (printed and delivered on paper)
     * when built with {@see OutgoingMessage::withHybridShipment()}. Requires the "write_messages" permission.
     *
     * LEGAL EFFECT: an accepted message is a registered electronic delivery, or a hybrid shipment that is printed and
     * posted; deadlines and deemed delivery follow from it. The contract has no idempotency key, so this call is
     * NEVER retried automatically, whatever the retry policy. After a {@see \B4x\Edoreczenia\Exception\TransportException} (e.g. a timeout)
     * or a 5xx error the message may still have been accepted: look for it in the SENT folder
     * ({@see listMessages()}) before sending again. An {@see \B4x\Edoreczenia\Exception\UnexpectedResponseException}
     * carrying a 2xx status code means the server accepted the request but its answer could not be read: do not resend.
     *
     * Sending is asynchronous: the server answers HTTP 202 with a task id; follow it with {@see messageTaskStatus()}
     * and {@see messageTask()}.
     *
     * Recipients are not checked by the SDK. Validate them first with the Search Engine API
     * (`$client->searchEngine()->findByAddresses([...])`) to avoid sending to inactive addresses.
     * Postal addresses of hybrid recipients are only checked for the fields the contract requires.
     *
     * Not yet verified against INT or PROD (hybrid shipments included).
     *
     * @throws EdoreczeniaException
     */
    public function sendMessage(OutgoingMessage $message): MessageTask
    {
        if ($message->recipients() === [] && $message->paperRecipients() === []) {
            throw new InvalidArgumentException('A message needs at least one recipient.');
        }

        return MessageTask::fromPayload(Payload::root($this->post('messages', $message->toPayload($this->mailboxAddress), 'POST messages')));
    }

    /**
     * Per-recipient outcome of a send task (GET /{eDeliveryAddress}/messages/tasks/{messageTaskId}).
     * Read-only, so it is retried when a retry policy is configured. Not yet verified against INT or PROD.
     *
     * @return list<MessageTaskItem>
     *
     * @throws EdoreczeniaException
     */
    public function messageTask(string $messageTaskId): array
    {
        $path = 'messages/tasks/' . PathSegment::encode(self::id('Message task id', $messageTaskId));

        return array_map(MessageTaskItem::fromPayload(...), Payload::rootObjectList($this->get($path, [], 'messages/tasks/{messageTaskId}')));
    }

    /**
     * Processing state of a send task (GET /{eDeliveryAddress}/messages/tasks/{messageTaskId}/status): PENDING or
     * FINISHED; values unknown to this SDK version are returned as strings. FINISHED does not mean delivered.
     * Read-only, so it is retried when a retry policy is configured. Not yet verified against INT or PROD.
     *
     * @throws EdoreczeniaException
     */
    public function messageTaskStatus(string $messageTaskId): MessageTaskState|string
    {
        $path = 'messages/tasks/' . PathSegment::encode(self::id('Message task id', $messageTaskId)) . '/status';

        return Payload::root($this->get($path, [], 'messages/tasks/{messageTaskId}/status'))->requiredEnumOrString('messageTaskStatus', MessageTaskState::class);
    }

    /**
     * Lists drafts (GET /{eDeliveryAddress}/drafts) in "metadata" format. Read-only. Not yet verified against INT or PROD.
     *
     * @throws EdoreczeniaException
     */
    public function listDrafts(?DraftQuery $query = null): MessageList
    {
        return MessageList::fromPayload(Payload::root($this->get('drafts', ($query ?? DraftQuery::create())->toQuery(), 'drafts')));
    }

    /**
     * One draft with its text and attachment list (GET /{eDeliveryAddress}/drafts/{messageId}). Read-only.
     * Not yet verified against INT or PROD.
     *
     * @throws EdoreczeniaException
     */
    public function getDraft(string $draftId): Draft
    {
        $data = $this->get('drafts/' . PathSegment::encode(self::id('Draft id', $draftId)), [], 'drafts/{messageId}');

        return Draft::fromPayload(Payload::root($data));
    }

    /**
     * Saves a new draft (POST /{eDeliveryAddress}/drafts); recipients may still be missing. Nothing is sent.
     * Creates server state, so it is never retried automatically. Not yet verified against INT or PROD.
     *
     * @throws EdoreczeniaException
     */
    public function createDraft(OutgoingMessage $message): SavedDraft
    {
        return SavedDraft::fromPayload(Payload::root($this->post('drafts', $message->toPayload($this->mailboxAddress), 'POST drafts')));
    }

    /**
     * Replaces the content of a draft (PUT /{eDeliveryAddress}/drafts/{messageId}). Nothing is sent.
     * Never retried automatically. Not yet verified against INT or PROD.
     *
     * @throws EdoreczeniaException
     */
    public function updateDraft(string $draftId, OutgoingMessage $message): SavedDraft
    {
        $path = 'drafts/' . PathSegment::encode(self::id('Draft id', $draftId));
        $data = $this->transport->putJson($this->mailboxUri($path), $message->toPayload($this->mailboxAddress), 'UA API v3 PUT drafts/{messageId}', false, [$this->mailboxAddress]);

        return SavedDraft::fromPayload(Payload::root($data));
    }

    /**
     * Deletes up to 50 drafts at once (DELETE /{eDeliveryAddress}/drafts/{messageId}, ids joined with ",").
     * Never retried automatically. Not yet verified against INT or PROD.
     *
     * @return list<string> ids of the deleted drafts, as reported by the server
     *
     * @throws EdoreczeniaException
     */
    public function deleteDrafts(string $draftId, string ...$more): array
    {
        $ids = [$draftId, ...$more];
        if (\count($ids) > self::MAX_DELETED_DRAFTS) {
            throw new InvalidArgumentException(\sprintf('At most %d drafts can be deleted at once.', self::MAX_DELETED_DRAFTS));
        }
        $segments = [];
        foreach ($ids as $id) {
            if (str_contains(self::id('Draft id', $id), ',')) {
                throw new InvalidArgumentException('Draft id must not contain ",".');
            }
            $segments[] = PathSegment::encode($id);
        }

        $data = $this->delete('drafts/' . implode(',', $segments), 'DELETE drafts/{messageId}');
        $deleted = array_map(static fn(Payload $item): ?string => $item->optionalString('messageId'), Payload::rootObjectList($data));

        return array_values(array_filter($deleted, static fn(?string $id): bool => $id !== null));
    }

    /**
     * Adds a file of up to {@see OutgoingAttachment::MAX_INLINE_BYTES} to a draft, base64-encoded in JSON
     * (POST /{eDeliveryAddress}/drafts/{messageId}/attachments). Never retried automatically.
     * Not yet verified against INT or PROD.
     *
     * @throws EdoreczeniaException
     */
    public function addDraftAttachment(string $draftId, OutgoingAttachment $attachment): UploadedAttachment
    {
        $path = 'drafts/' . PathSegment::encode(self::id('Draft id', $draftId)) . '/attachments';

        return UploadedAttachment::fromPayload(Payload::root($this->post($path, $attachment->toFileData(), 'POST drafts/{messageId}/attachments')));
    }

    /**
     * Uploads a large file to a draft as multipart/form-data (POST /{eDeliveryAddress}/{messageId}/attachments/upload),
     * streaming it from the given PSR-7 stream without buffering it. Never retried automatically.
     * Not yet verified against INT or PROD.
     *
     * @throws EdoreczeniaException
     */
    public function uploadDraftAttachment(string $draftId, AttachmentUpload $upload): UploadedAttachment
    {
        $boundary = 'b4x-' . bin2hex(random_bytes(16));
        $data = $this->transport->postStreamBody(
            $this->mailboxUri(PathSegment::encode(self::id('Draft id', $draftId)) . '/attachments/upload'),
            $upload->toMultipart($boundary),
            'multipart/form-data; boundary=' . $boundary,
            'UA API v3 POST {messageId}/attachments/upload',
            [$this->mailboxAddress],
        );

        return UploadedAttachment::fromPayload(Payload::root($data));
    }

    /**
     * Removes one attachment from a draft (DELETE /{eDeliveryAddress}/drafts/{messageId}/attachments/{attachmentId}).
     * Never retried automatically. Not yet verified against INT or PROD.
     *
     * @throws EdoreczeniaException
     */
    public function deleteDraftAttachment(string $draftId, string $attachmentId): FileInfo
    {
        $path = 'drafts/' . PathSegment::encode(self::id('Draft id', $draftId)) . '/attachments/' . PathSegment::encode(self::id('Attachment id', $attachmentId));

        return FileInfo::fromPayload(Payload::root($this->delete($path, 'DELETE drafts/{messageId}/attachments/{attachmentId}')));
    }

    /**
     * Removes all attachments from a draft (DELETE /{eDeliveryAddress}/drafts/{messageId}/attachments).
     * Never retried automatically. Not yet verified against INT or PROD.
     *
     * @return list<FileInfo> the removed files
     *
     * @throws EdoreczeniaException
     */
    public function deleteDraftAttachments(string $draftId): array
    {
        $path = 'drafts/' . PathSegment::encode(self::id('Draft id', $draftId)) . '/attachments';

        return array_map(FileInfo::fromPayload(...), Payload::rootObjectList($this->delete($path, 'DELETE drafts/{messageId}/attachments')));
    }

    /**
     * Sends a saved draft (POST /{eDeliveryAddress}/drafts/send). Requires the "write_messages" permission.
     *
     * LEGAL EFFECT, exactly like {@see sendMessage()}: NEVER retried automatically, and after a transport or 5xx
     * error the message may still have been accepted; check the SENT folder before sending again.
     * Validate the recipients with the Search Engine API first. Not yet verified against INT or PROD.
     *
     * @throws EdoreczeniaException
     */
    public function sendDraft(string $draftId): MessageTask
    {
        $data = $this->post('drafts/send', ['messageId' => self::id('Draft id', $draftId)], 'POST drafts/send');

        return MessageTask::fromPayload(Payload::root($data));
    }

    private function mailboxUri(string $path): string
    {
        return $this->baseUri . PathSegment::encode($this->mailboxAddress) . '/' . $path;
    }

    /**
     * Side-effecting POST: never retried, whatever the retry policy.
     *
     * @param array<mixed> $payload
     *
     * @return array<mixed>
     */
    private function post(string $path, array $payload, string $operation): array
    {
        return $this->transport->postJson($this->mailboxUri($path), $payload, 'UA API v3 ' . $operation, false, [$this->mailboxAddress]);
    }

    /**
     * Side-effecting DELETE: never retried, whatever the retry policy.
     *
     * @return array<mixed>
     */
    private function delete(string $path, string $operation): array
    {
        return $this->transport->deleteJson($this->mailboxUri($path), 'UA API v3 ' . $operation, false, [$this->mailboxAddress]);
    }

    /**
     * @param array<string, string|int|bool> $query
     */
    private function stream(string $path, array $query, string $operation): ResponseInterface
    {
        return $this->transport->getStream(
            $this->baseUri . PathSegment::encode($this->mailboxAddress) . '/' . $path,
            $query,
            'UA API v3 ' . $operation,
            true,
            [$this->mailboxAddress],
        );
    }

    private static function id(string $label, string $value): string
    {
        if ($value === '' || preg_match('/\s/u', $value) === 1) {
            throw new InvalidArgumentException(\sprintf('%s must be a non-empty string without whitespace.', $label));
        }

        return $value;
    }

    /**
     * @param array<string, string|int|bool> $query
     *
     * @return array<mixed>
     */
    private function get(string $path, array $query, string $operation): array
    {
        return $this->transport->getJson(
            $this->baseUri . PathSegment::encode($this->mailboxAddress) . '/' . $path,
            $query,
            'UA API v3 ' . $operation,
            true,
            [$this->mailboxAddress],
        );
    }
}
