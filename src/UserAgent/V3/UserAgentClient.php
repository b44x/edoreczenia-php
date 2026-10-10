<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3;

use B4x\Edoreczenia\Exception\EdoreczeniaException;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\Exception\UnexpectedResponseException;
use B4x\Edoreczenia\Internal\PathSegment;
use B4x\Edoreczenia\Internal\Payload;
use B4x\Edoreczenia\Internal\Transport;
use B4x\Edoreczenia\UserAgent\V3\Enum\EvidenceService;
use B4x\Edoreczenia\UserAgent\V3\Model\Directory;
use B4x\Edoreczenia\UserAgent\V3\Model\Download;
use B4x\Edoreczenia\UserAgent\V3\Model\Evidence;
use B4x\Edoreczenia\UserAgent\V3\Model\EvidenceList;
use B4x\Edoreczenia\UserAgent\V3\Model\MailboxSettings;
use B4x\Edoreczenia\UserAgent\V3\Model\MessageAttachment;
use B4x\Edoreczenia\UserAgent\V3\Model\MessageList;
use B4x\Edoreczenia\UserAgent\V3\Model\MessagePending;
use B4x\Edoreczenia\UserAgent\V3\Model\OpenedMessage;
use B4x\Edoreczenia\UserAgent\V3\Model\Thread;
use B4x\Edoreczenia\UserAgent\V3\Model\ThreadList;
use B4x\Edoreczenia\UserAgent\V3\Request\EvidenceQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\MessageQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\ThreadMessageQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\ThreadQuery;
use Psr\Http\Message\ResponseInterface;

/**
 * User Agent API v3 (contract 3.0.8) of the designated operator.
 *
 * Status: implemented from the official contract only, NOT yet verified against INT or PROD.
 *
 * Operations free of side effects are retried when a retry policy is configured. {@see openMessage()} has
 * legal effect and is never retried.
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
        $response = $this->transport->postStream(
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
