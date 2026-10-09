<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3;

use B4x\Edoreczenia\Exception\EdoreczeniaException;
use B4x\Edoreczenia\Internal\PathSegment;
use B4x\Edoreczenia\Internal\Payload;
use B4x\Edoreczenia\Internal\Transport;
use B4x\Edoreczenia\UserAgent\V3\Model\Directory;
use B4x\Edoreczenia\UserAgent\V3\Model\MailboxSettings;
use B4x\Edoreczenia\UserAgent\V3\Model\MessageList;
use B4x\Edoreczenia\UserAgent\V3\Request\MessageQuery;

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
