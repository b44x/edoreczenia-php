<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Testing;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sending and draft routes of {@see FakeEdoreczenia}, kept in memory per fake instance.
 *
 *  - POST {mailbox}/messages and POST {mailbox}/drafts/send answer HTTP 202 with a new "messageTaskId".
 *    Recipients listed in {@see SyntheticData::ADDRESSES} with status ACTIVE get a message id; any other
 *    address gets a per-recipient error in the task, as an asynchronous rejection would look like.
 *    Hybrid (paper) messages need "hybridShipment" with the fields the contract requires; their recipients
 *    given by postal address get a message id when "address" has country, postalCode, city and street;
 *  - GET {mailbox}/messages/tasks/{id}/status answers PENDING on the first poll of a task and FINISHED afterwards;
 *  - drafts and tasks belong to the mailbox of the access token; drafts can be created, listed, read, replaced and deleted, and attachments added (JSON or multipart
 *    upload), read and removed.
 *
 * Nothing leaves the process; accepted messages are recorded for {@see sentMessages()}.
 *
 * @internal part of {@see FakeEdoreczenia}
 */
trait FakeSendingRoutes
{
    /** @var list<array<mixed>> */
    private array $sentMessages = [];

    /** @var array<string, array<string, list<array<string, mixed>>>> mailbox => task id => per-recipient results */
    private array $sendTasks = [];

    /** @var array<string, int> task id => number of status polls */
    private array $sendTaskPolls = [];

    /** @var array<string, array<string, array<string, mixed>>> mailbox => draft id => stored "Message" */
    private array $drafts = [];

    /**
     * Bodies ("Message") of every message accepted so far by POST …/messages or POST …/drafts/send, in order.
     *
     * @return list<array<mixed>>
     */
    public function sentMessages(): array
    {
        return $this->sentMessages;
    }

    /**
     * @param list<string> $s decoded path segments
     */
    private function sendingRoute(RequestInterface $request, array $s, string $path): ?ResponseInterface
    {
        $n = \count($s);
        $at = static fn(int $fromEnd): ?string => $s[$n - $fromEnd] ?? null;
        $method = $request->getMethod();

        $route = match (true) {
            $method === 'POST' && $n >= 2 && $at(1) === 'messages' => ['send', $at(2), null, null],
            $method === 'GET' && $n >= 4 && $at(3) === 'messages' && $at(2) === 'tasks' => ['task', $at(4), $at(1), null],
            $method === 'GET' && $n >= 5 && $at(4) === 'messages' && $at(3) === 'tasks' && $at(1) === 'status' => ['taskStatus', $at(5), $at(2), null],
            $method === 'POST' && $n >= 4 && $at(2) === 'attachments' && $at(1) === 'upload' => ['upload', $at(4), $at(3), null],
            $n >= 2 && $at(1) === 'drafts' => ['drafts', $at(2), null, null],
            $method === 'POST' && $n >= 3 && $at(2) === 'drafts' && $at(1) === 'send' => ['sendDraft', $at(3), null, null],
            $n >= 3 && $at(2) === 'drafts' => ['draft', $at(3), $at(1), null],
            $n >= 4 && $at(3) === 'drafts' && $at(1) === 'attachments' => ['draftAttachments', $at(4), $at(2), null],
            $n >= 5 && $at(4) === 'drafts' && $at(2) === 'attachments' => ['draftAttachment', $at(5), $at(3), $at(1)],
            default => null,
        };
        if ($route === null) {
            return null;
        }
        [$name, $pathMailbox, $id, $attachmentId] = $route;

        $mailbox = $this->mailboxOf($request);
        if ($mailbox === null) {
            return $this->uaError(401, 'unauthorized', 'Missing or unknown bearer token');
        }
        if ($pathMailbox !== $mailbox) {
            return $this->uaError(403, 'forbidden', 'The access token was not issued for this mailbox');
        }

        $id = (string) $id;

        return match ($name) {
            'send' => $this->sendFromBody($request, $mailbox),
            'task' => isset($this->sendTasks[$mailbox][$id]) ? $this->json(200, $this->sendTasks[$mailbox][$id]) : $this->uaError(400, 'INVALID_PARAMETER', 'Unknown message task id'),
            'taskStatus' => $this->sendTaskStatus($mailbox, $id),
            'upload' => $this->uploadDraftAttachment($mailbox, $request, $id),
            'drafts' => match ($method) {
                'GET' => $this->listDrafts($mailbox),
                'POST' => $this->createDraft($request, $mailbox),
                default => $this->sendingMethodNotAllowed($method, $path),
            },
            'sendDraft' => $this->sendDraft($request, $mailbox),
            'draft' => match ($method) {
                'GET' => $this->getDraft($mailbox, $id),
                'PUT' => $this->replaceDraft($request, $mailbox, $id),
                'DELETE' => $this->deleteDrafts($mailbox, $id),
                default => $this->sendingMethodNotAllowed($method, $path),
            },
            'draftAttachments' => match ($method) {
                'POST' => $this->addDraftAttachment($mailbox, $request, $id),
                'DELETE' => $this->deleteDraftAttachments($mailbox, $id, null),
                default => $this->sendingMethodNotAllowed($method, $path),
            },
            default => match ($method) {
                'GET' => $this->getDraftAttachment($mailbox, $id, (string) $attachmentId),
                'DELETE' => $this->deleteDraftAttachments($mailbox, $id, (string) $attachmentId),
                default => $this->sendingMethodNotAllowed($method, $path),
            },
        };
    }

    private function sendFromBody(RequestInterface $request, string $mailbox): ResponseInterface
    {
        $message = self::jsonObject($request);
        if ($message === null) {
            return $this->uaError(400, 'INVALID_REQUEST', 'The body must be a JSON object ("Message")');
        }

        return $this->accept($message, $mailbox);
    }

    private function sendDraft(RequestInterface $request, string $mailbox): ResponseInterface
    {
        $body = self::jsonObject($request);
        $id = $body['messageId'] ?? null;
        if (!\is_string($id) || !isset($this->drafts[$mailbox][$id])) {
            return $this->uaError(400, 'INVALID_PARAMETER', 'Unknown draft id');
        }

        $response = $this->accept($this->drafts[$mailbox][$id], $mailbox);
        if ($response->getStatusCode() === 202) {
            unset($this->drafts[$mailbox][$id]);
        }

        return $response;
    }

    /**
     * @param array<mixed> $message
     */
    private function accept(array $message, string $mailbox): ResponseInterface
    {
        $problem = self::messageProblem($message, $mailbox);
        $metadata = $message['messageMetadata'] ?? null;
        $recipients = \is_array($metadata) && \is_array($metadata['to'] ?? null) ? $metadata['to'] : [];
        if ($problem === null && $recipients === []) {
            $problem = 'messageMetadata.to must list at least one recipient';
        }
        $service = \is_array($metadata) ? ($metadata['shippingService'] ?? null) : null;
        $hybrid = $service === 'hybrid';
        if ($problem === null && !$hybrid && $service !== 'electronic') {
            $problem = 'messageMetadata.shippingService must be "electronic" or "hybrid"';
        }
        if ($problem === null && $hybrid) {
            $problem = self::hybridShipmentProblem(\is_array($metadata) ? ($metadata['hybridShipment'] ?? null) : null);
        }
        if ($problem !== null) {
            return $this->uaError(400, 'INVALID_REQUEST', $problem);
        }

        $results = [];
        foreach ($recipients as $recipient) {
            $address = \is_array($recipient) ? ($recipient['eDeliveryAddress'] ?? null) : null;
            if ($hybrid && $address === null && \is_array($recipient)) {
                $results[] = self::paperRecipientResult($recipient);
                continue;
            }
            $entry = \is_string($address) ? (SyntheticData::ADDRESSES[$address] ?? null) : null;
            $results[] = $entry !== null && $entry['eda']['edaStatus'] === 'ACTIVE'
                ? ['messageId' => self::fakeUuid(), 'addressee' => ['eDeliveryAddress' => $address]]
                : ['addressee' => ['eDeliveryAddress' => \is_string($address) ? $address : ''], 'error' => 'INVALID_RECIPIENT', 'error_description' => 'The recipient address is not active'];
        }

        $taskId = self::fakeUuid();
        $this->sendTasks[$mailbox][$taskId] = $results;
        $this->sentMessages[] = $message;

        return $this->json(202, ['messageTaskId' => $taskId]);
    }

    /**
     * @param array<mixed> $recipient "MessageAddressData" without an e-Delivery address
     *
     * @return array<string, mixed>
     */
    private static function paperRecipientResult(array $recipient): array
    {
        $address = \is_array($recipient['address'] ?? null) ? $recipient['address'] : [];
        $complete = true;
        foreach (['country', 'postalCode', 'city', 'street'] as $field) {
            $value = $address[$field] ?? null;
            $complete = $complete && \is_string($value) && trim($value) !== '';
        }

        return $complete
            ? ['messageId' => self::fakeUuid(), 'addressee' => $recipient]
            : ['addressee' => $recipient, 'error' => 'INVALID_RECIPIENT', 'error_description' => 'The postal address is incomplete'];
    }

    private static function hybridShipmentProblem(mixed $shipment): ?string
    {
        if (!\is_array($shipment)) {
            return 'messageMetadata.hybridShipment is required for hybrid shipments';
        }
        foreach (['categoryService', 'printingMode', 'overprintMode', 'returnService', 'procedingType', 'shippingMode'] as $field) {
            if (!\is_string($shipment[$field] ?? null)) {
                return \sprintf('messageMetadata.hybridShipment.%s is required', $field);
            }
        }

        return null;
    }

    private function sendTaskStatus(string $mailbox, string $taskId): ResponseInterface
    {
        if (!isset($this->sendTasks[$mailbox][$taskId])) {
            return $this->uaError(400, 'INVALID_PARAMETER', 'Unknown message task id');
        }
        $polls = $this->sendTaskPolls[$taskId] = ($this->sendTaskPolls[$taskId] ?? 0) + 1;

        return $this->json(200, ['messageTaskStatus' => $polls === 1 ? 'PENDING' : 'FINISHED']);
    }

    private function listDrafts(string $mailbox): ResponseInterface
    {
        $drafts = array_values(array_map(static fn(array $d): array => array_diff_key($d, ['textBody' => true, 'attachments' => true]), ($this->drafts[$mailbox] ?? [])));

        return $this->json(200, ['messages' => $drafts, 'resultSize' => \count($drafts), 'total' => \count($drafts)]);
    }

    private function getDraft(string $mailbox, string $id): ResponseInterface
    {
        $draft = $this->drafts[$mailbox][$id] ?? null;
        if ($draft === null) {
            return $this->uaError(400, 'INVALID_PARAMETER', 'Unknown draft id');
        }
        $attachments = \is_array($draft['attachments'] ?? null) ? $draft['attachments'] : [];
        // Like the "full" format of messages: attachments are listed without their content.
        $draft['attachments'] = array_map(static fn(mixed $a): mixed => \is_array($a) && \is_array($a['file'] ?? null) ? ['file' => array_diff_key($a['file'], ['file' => true])] + $a : $a, $attachments);
        if ($draft['attachments'] === []) {
            unset($draft['attachments']);
        }

        return $this->json(200, $draft);
    }

    private function createDraft(RequestInterface $request, string $mailbox): ResponseInterface
    {
        return $this->storeDraft($request, $mailbox, self::fakeUuid(), false);
    }

    private function replaceDraft(RequestInterface $request, string $mailbox, string $id): ResponseInterface
    {
        if (!isset($this->drafts[$mailbox][$id])) {
            return $this->uaError(400, 'INVALID_PARAMETER', 'Unknown draft id');
        }

        return $this->storeDraft($request, $mailbox, $id, true);
    }

    private function storeDraft(RequestInterface $request, string $mailbox, string $id, bool $keepAttachments): ResponseInterface
    {
        $message = self::jsonObject($request);
        $problem = $message === null ? 'The body must be a JSON object ("Message")' : self::messageProblem($message, $mailbox);
        if ($message === null || $problem !== null) {
            return $this->uaError(400, 'INVALID_REQUEST', $problem ?? '');
        }

        $metadata = \is_array($message['messageMetadata'] ?? null) ? $message['messageMetadata'] : [];
        $draft = [
            'messageControlData' => ['messageType' => 'Message'],
            'messageMetadata' => ['messageId' => $id, 'timestamp' => gmdate('Y-m-d\TH:i:s\Z')] + array_diff_key($metadata, ['messageId' => true]),
        ];
        if (\is_string($message['textBody'] ?? null)) {
            $draft['textBody'] = $message['textBody'];
        }
        $attachments = \is_array($message['attachments'] ?? null) ? $message['attachments'] : null;
        if ($attachments === null && $keepAttachments) {
            $attachments = $this->drafts[$mailbox][$id]['attachments'] ?? null;
        }
        if (\is_array($attachments) && $attachments !== []) {
            $draft['attachments'] = array_map(static fn(mixed $a): mixed => \is_array($a) && !isset($a['attachmentId']) ? ['attachmentId' => self::fakeUuid()] + $a : $a, array_values($attachments));
        }
        $this->drafts[$mailbox][$id] = $draft;

        return $this->json(200, ['messageId' => $id]);
    }

    private function deleteDrafts(string $mailbox, string $ids): ResponseInterface
    {
        $list = explode(',', $ids);
        foreach ($list as $id) {
            if (!isset($this->drafts[$mailbox][$id])) {
                return $this->uaError(400, 'INVALID_PARAMETER', 'Unknown draft id');
            }
        }
        foreach ($list as $id) {
            unset($this->drafts[$mailbox][$id]);
        }

        return $this->json(200, array_map(static fn(string $id): array => ['messageId' => $id], $list));
    }

    private function addDraftAttachment(string $mailbox, RequestInterface $request, string $id): ResponseInterface
    {
        if (!isset($this->drafts[$mailbox][$id])) {
            return $this->uaError(400, 'INVALID_PARAMETER', 'Unknown draft id');
        }
        $data = self::jsonObject($request);
        $metadata = $data['fileMetadata'] ?? null;
        $file = $data['file'] ?? null;
        if (!\is_array($metadata) || !\is_string($metadata['fileId'] ?? null) || !\is_string($file) || base64_decode($file, true) === false) {
            return $this->uaError(400, 'INVALID_REQUEST', 'The body must be "FileData" with fileMetadata.fileId and base64 content');
        }

        return $this->storeDraftAttachment($mailbox, $id, $metadata, $file);
    }

    private function uploadDraftAttachment(string $mailbox, RequestInterface $request, string $id): ResponseInterface
    {
        if (!isset($this->drafts[$mailbox][$id])) {
            return $this->uaError(400, 'INVALID_PARAMETER', 'Unknown draft id');
        }
        if (preg_match('/^multipart\/form-data;\s*boundary=("?)([^";]+)\1/i', $request->getHeaderLine('Content-Type'), $m) !== 1) {
            return $this->uaError(400, 'INVALID_REQUEST', 'multipart/form-data with a boundary expected');
        }

        $fields = [];
        foreach (explode('--' . $m[2], (string) $request->getBody()) as $part) {
            $split = strpos($part, "\r\n\r\n");
            if ($split === false || preg_match('/name="([^"]*)"/', substr($part, 0, $split), $name) !== 1) {
                continue;
            }
            $fields[$name[1]] = substr($part, $split + 4, -2);
        }
        foreach (['filename', 'contentType', 'file'] as $required) {
            if (!isset($fields[$required])) {
                return $this->uaError(400, 'INVALID_REQUEST', \sprintf('Missing multipart field "%s"', $required));
            }
        }

        $metadata = ['fileId' => self::fakeUuid(), 'filename' => $fields['filename'], 'contentType' => $fields['contentType'], 'size' => \strlen($fields['file'])];
        if (isset($fields['description'])) {
            $metadata['description'] = $fields['description'];
        }

        return $this->storeDraftAttachment($mailbox, $id, $metadata, base64_encode($fields['file']));
    }

    /**
     * @param array<mixed> $metadata
     */
    private function storeDraftAttachment(string $mailbox, string $id, array $metadata, string $base64): ResponseInterface
    {
        $attachments = \is_array($this->drafts[$mailbox][$id]['attachments'] ?? null) ? $this->drafts[$mailbox][$id]['attachments'] : [];
        $attachmentId = self::fakeUuid();
        $attachments[] = ['order' => \count($attachments) + 1, 'attachmentId' => $attachmentId, 'file' => ['fileMetadata' => $metadata, 'file' => $base64]];
        $this->drafts[$mailbox][$id]['attachments'] = $attachments;

        return $this->json(200, ['attachmentId' => $attachmentId, 'fileMetadata' => $metadata]);
    }

    private function getDraftAttachment(string $mailbox, string $id, string $attachmentId): ResponseInterface
    {
        foreach ($this->draftAttachments($mailbox, $id) ?? [] as $attachment) {
            if (\is_array($attachment) && ($attachment['attachmentId'] ?? null) === $attachmentId) {
                return $this->json(200, $attachment);
            }
        }

        return $this->uaError(400, 'INVALID_PARAMETER', 'Unknown draft or attachment id');
    }

    /**
     * Removes one attachment ($attachmentId) or all of them (null) and answers with their "FileMetadata".
     */
    private function deleteDraftAttachments(string $mailbox, string $id, ?string $attachmentId): ResponseInterface
    {
        $attachments = $this->draftAttachments($mailbox, $id);
        if ($attachments === null) {
            return $this->uaError(400, 'INVALID_PARAMETER', 'Unknown draft id');
        }

        $kept = [];
        $removed = [];
        foreach ($attachments as $attachment) {
            $matches = \is_array($attachment) && ($attachmentId === null || ($attachment['attachmentId'] ?? null) === $attachmentId);
            if ($matches) {
                $removed[] = \is_array($attachment['file'] ?? null) ? ($attachment['file']['fileMetadata'] ?? []) : [];
            } else {
                $kept[] = $attachment;
            }
        }
        if ($attachmentId !== null && $removed === []) {
            return $this->uaError(400, 'INVALID_PARAMETER', 'Unknown attachment id');
        }
        $this->drafts[$mailbox][$id]['attachments'] = $kept;

        return $this->json(200, $attachmentId === null ? $removed : (\is_array($removed[0]) ? $removed[0] : []));
    }

    /**
     * @return list<mixed>|null null for an unknown draft
     */
    private function draftAttachments(string $mailbox, string $id): ?array
    {
        if (!isset($this->drafts[$mailbox][$id])) {
            return null;
        }
        $attachments = $this->drafts[$mailbox][$id]['attachments'] ?? [];

        return \is_array($attachments) ? array_values($attachments) : [];
    }

    private function sendingMethodNotAllowed(string $method, string $path): ResponseInterface
    {
        return $this->uaError(405, 'method_not_allowed', \sprintf('%s %s is not supported by FakeEdoreczenia.', $method, $path));
    }

    /**
     * Checks shared by sending and drafts; null when the message is acceptable.
     *
     * @param array<mixed> $message
     */
    private static function messageProblem(array $message, string $mailbox): ?string
    {
        $metadata = $message['messageMetadata'] ?? null;
        $from = \is_array($metadata) && \is_array($metadata['from'] ?? null) ? ($metadata['from']['eDeliveryAddress'] ?? null) : null;
        $text = $message['textBody'] ?? null;

        return match (true) {
            !\is_array($metadata) => 'messageMetadata is required',
            !\is_string($metadata['shippingService'] ?? null) => 'messageMetadata.shippingService is required',
            $from !== null && $from !== $mailbox => 'messageMetadata.from must be the mailbox of the access token',
            $text !== null && (!\is_string($text) || mb_strlen($text, 'UTF-8') > 5000) => 'textBody must be a string of at most 5000 characters',
            default => null,
        };
    }

    /**
     * @return array<mixed>|null
     */
    private static function jsonObject(RequestInterface $request): ?array
    {
        $data = json_decode((string) $request->getBody(), true);

        return \is_array($data) && !array_is_list($data) ? $data : null;
    }

    private static function fakeUuid(): string
    {
        $hex = bin2hex(random_bytes(16));

        return strtoupper(\sprintf('%s-%s-4%s-a%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), substr($hex, 17, 3), substr($hex, 20, 12)));
    }
}
