<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3;

use B4x\Edoreczenia\Exception\EdoreczeniaException;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\Internal\PathSegment;
use B4x\Edoreczenia\Internal\Payload;
use B4x\Edoreczenia\Internal\Transport;
use B4x\Edoreczenia\UserAgent\V3\Enum\EvidenceService;
use B4x\Edoreczenia\UserAgent\V3\Model\Directory;
use B4x\Edoreczenia\UserAgent\V3\Model\Download;
use B4x\Edoreczenia\UserAgent\V3\Model\Evidence;
use B4x\Edoreczenia\UserAgent\V3\Model\EvidenceList;
use B4x\Edoreczenia\UserAgent\V3\Model\MailboxSettings;
use B4x\Edoreczenia\UserAgent\V3\Model\MessageList;
use B4x\Edoreczenia\UserAgent\V3\Model\Thread;
use B4x\Edoreczenia\UserAgent\V3\Model\ThreadList;
use B4x\Edoreczenia\UserAgent\V3\Request\EvidenceQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\MessageQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\ThreadMessageQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\ThreadQuery;
use Psr\Http\Message\ResponseInterface;

/**
 * User Agent API v3 (contract 3.0.8) of the designated operator – read-only operations.
 *
 * Status: implemented from the official contract only, NOT yet verified against INT or PROD.
 *
 * Every operation here is free of side effects, so it is retried when a retry policy is configured.
 * Operations that open, send or delete messages are deliberately not part of this release.
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
