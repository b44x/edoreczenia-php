<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Testing;

use B4x\Edoreczenia\Auth\AccessToken;
use B4x\Edoreczenia\Auth\StaticTokenProvider;
use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * In-memory PSR-18 client emulating e-Doręczenia for offline tests of your own integration.
 *
 * It answers the token endpoint, Search Engine API v4 and the User Agent API v3 operations supported by
 * the SDK with the synthetic dataset of {@see SyntheticData}; nothing is sent over the network. Routes are matched
 * by path suffix, so every {@see Environment} (production, integration, custom) is served.
 *
 * What it checks, like the real services:
 *  - token requests: client_credentials grant, jwt-bearer assertion type, a three-segment JWT whose
 *    iss/sub belong to the "login_hint=ADE.{address}" mailbox. Signatures are NOT verified;
 *  - API calls: a bearer token issued by this fake or registered with {@see acceptToken()};
 *  - SE API: "senderEda" present and equal to the token's mailbox, recipientEda = senderEda for
 *    eda-confirmation, recipient address counts;
 *  - UA API: the mailbox in the path equals the token's mailbox, known message/thread/evidence ids.
 *
 * Opening a message (format "full" or "fullExtended") is recorded: the message is listed as opened
 * afterwards. {@see SyntheticData::PENDING_MESSAGE_ID} answers HTTP 202 to its first opening. Attachments
 * are served as JSON and through POST attachments/download. Any other non-GET request to the User Agent API
 * fails with HTTP 405, so such a request in your tests means something bypassed the SDK.
 *
 * Token lifetimes are not enforced and the data is otherwise static: it is a test double, not a simulator.
 */
final class FakeEdoreczenia implements ClientInterface
{
    public const DEFAULT_MAILBOX = SyntheticData::MAILBOX;

    private const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
    private const MAILBOX_ROUTES = ['directories', 'numberMessages', 'settings', 'messages', 'threads'];

    /** @var array<string, string> access token => mailbox address */
    private array $tokens = [];

    /** @var list<array{string, int, array<mixed>|string}> */
    private array $failures = [];

    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var array<string, true> ids of messages opened through this fake */
    private array $openedMessages = [];

    /** @var array<string, true> ids of messages that already answered HTTP 202 */
    private array $pendingAnswered = [];

    /**
     * @param RequestFactoryInterface|null $requestFactory needed by {@see createClient()} only when neither
     *                                                     factory above also implements it
     */
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?RequestFactoryInterface $requestFactory = null,
    ) {}

    /**
     * A client wired to this fake, authenticated with a static token accepted for $mailboxAddress.
     */
    public function createClient(string $mailboxAddress = self::DEFAULT_MAILBOX, ?Environment $environment = null): EdoreczeniaClient
    {
        $requestFactory = $this->requestFactory
            ?? ($this->responseFactory instanceof RequestFactoryInterface ? $this->responseFactory : null)
            ?? ($this->streamFactory instanceof RequestFactoryInterface ? $this->streamFactory : null)
            ?? throw new InvalidArgumentException('Pass a PSR-17 request factory to FakeEdoreczenia to use createClient().');

        $token = 'fake-static-' . bin2hex(random_bytes(16));
        $this->acceptToken($token, $mailboxAddress);

        return new EdoreczeniaClient(
            $environment ?? Environment::integration(),
            $mailboxAddress,
            new StaticTokenProvider(new AccessToken($token, new \DateTimeImmutable('+1 day'))),
            $this,
            $requestFactory,
            $this->streamFactory,
        );
    }

    /**
     * Accepts a bearer token obtained elsewhere (e.g. your own StaticTokenProvider) for one mailbox.
     */
    public function acceptToken(#[\SensitiveParameter] string $token, string $mailboxAddress = self::DEFAULT_MAILBOX): self
    {
        $this->tokens[$token] = $mailboxAddress;

        return $this;
    }

    /**
     * The next request whose path ends with $pathSuffix (e.g. "search/eda-search", "/messages",
     * "openid-connect/token") gets this response, once, before any other check.
     *
     * An array body is sent as JSON; a string body as-is (JSON content type when it is valid JSON).
     *
     * @param array<mixed>|string $body
     */
    public function failNext(string $pathSuffix, int $status, array|string $body): self
    {
        $this->failures[] = [$pathSuffix, $status, $body];

        return $this;
    }

    /**
     * Every request received so far, token requests included, in order.
     *
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $rawPath = $request->getUri()->getPath();
        $segments = array_values(array_map(rawurldecode(...), array_filter(explode('/', $rawPath), static fn(string $s): bool => $s !== '')));
        $path = '/' . implode('/', $segments);

        foreach ($this->failures as $i => [$suffix, $status, $body]) {
            if (str_ends_with($path, $suffix) || str_ends_with($rawPath, $suffix)) {
                array_splice($this->failures, $i, 1);

                return \is_array($body)
                    ? $this->json($status, $body)
                    : $this->respond($status, json_validate($body) ? 'application/json' : 'text/plain', $body);
            }
        }

        if (str_ends_with($path, '/protocol/openid-connect/token')) {
            return $this->token($request);
        }
        foreach (['bae_search', 'eda-confirmation', 'eda-search'] as $operation) {
            if (str_ends_with($path, '/search/' . $operation)) {
                return $this->searchEngine($request, $operation);
            }
        }

        return $this->userAgent($request, $segments, $path);
    }

    private function token(RequestInterface $request): ResponseInterface
    {
        parse_str((string) $request->getBody(), $form);
        parse_str($request->getUri()->getQuery(), $query);
        $hint = $query['login_hint'] ?? null;
        $assertion = $form['client_assertion'] ?? null;

        $problem = match (true) {
            $request->getMethod() !== 'POST' => 'HTTP POST required',
            ($form['grant_type'] ?? null) !== 'client_credentials' => 'grant_type must be client_credentials',
            ($form['client_assertion_type'] ?? null) !== self::ASSERTION_TYPE => 'client_assertion_type must be ' . self::ASSERTION_TYPE,
            !\is_string($assertion) || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $assertion) !== 1 => 'client_assertion must be a signed JWT',
            !\is_string($hint) || !str_starts_with($hint, 'ADE.') || \strlen($hint) <= 4 => 'login_hint query parameter must be "ADE.{delivery address}"',
            default => null,
        };
        if ($problem === null && \is_string($hint) && \is_string($assertion)) {
            $mailbox = substr($hint, 4);
            $claims = json_decode((string) base64_decode(strtr(explode('.', $assertion)[1], '-_', '+/'), true), true);
            $issuer = \is_array($claims) ? ($claims['iss'] ?? null) : null;
            if (!\is_string($issuer) || $issuer !== ($claims['sub'] ?? null) || !str_starts_with($issuer, $mailbox . '.SYSTEM.')) {
                $problem = 'client_assertion iss and sub must be "{delivery address}.SYSTEM.{system name}" of the login_hint mailbox';
            } else {
                $token = 'fake-' . bin2hex(random_bytes(24));
                $this->tokens[$token] = $mailbox;

                return $this->json(200, [
                    'access_token' => $token,
                    'expires_in' => 300,
                    'refresh_expires_in' => 0,
                    'token_type' => 'Bearer',
                    'scope' => 'system-attributes',
                ]);
            }
        }

        return $this->json(400, ['error' => 'invalid_request', 'error_description' => $problem]);
    }

    private function mailboxOf(RequestInterface $request): ?string
    {
        if (preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $m) !== 1) {
            return null;
        }

        return $this->tokens[$m[1]] ?? null;
    }

    private function searchEngine(RequestInterface $request, string $operation): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->seError(405, 'SEAPI-00006', 'Only POST is supported by this operation');
        }
        $mailbox = $this->mailboxOf($request);
        if ($mailbox === null) {
            return $this->seError(401, 'SEAPI-00017', 'No authorization');
        }

        $body = json_decode((string) $request->getBody(), true);
        $sender = \is_array($body) ? ($body['senderEda'] ?? null) : null;
        if (!\is_array($body) || !\is_string($sender) || $sender === '') {
            return $this->seError(400, 'SEAPI-00006', 'Missing mandatory parameter: senderEda');
        }
        if ($sender !== $mailbox) {
            return $this->seError(401, 'SEAPI-00017', 'No authorization');
        }

        return match ($operation) {
            'eda-confirmation' => $this->edaConfirmation($body, $sender),
            'eda-search' => $this->edaSearch($body),
            default => $this->baeSearch($body),
        };
    }

    /**
     * @param array<mixed> $body
     */
    private function edaConfirmation(array $body, string $sender): ResponseInterface
    {
        if (($body['recipientEda'] ?? null) !== $sender) {
            return $this->seError(400, 'SEAPI-00021', 'recipientEda must be equal to senderEda');
        }

        // Any mailbox may ask for its own data: unknown ones get the default holder.
        $entry = self::address($sender) ?? self::address(SyntheticData::MAILBOX) ?? [];
        $eda = ['recipientEda' => $sender] + self::only($entry['eda'] ?? [], ['isMainEda', 'assignmentDegree', 'edaStatus', 'designatedOperator', 'dateOfEnteringToBAE', 'dateOfRemovalFromBAE']);

        return $this->json(200, ['recipientEda' => $eda, 'recipient' => $entry['recipient'] ?? []]);
    }

    /**
     * @param array<mixed> $body
     */
    private function edaSearch(array $body): ResponseInterface
    {
        $requested = self::stringList($body['recipientEdas'] ?? null);
        if ($requested === [] || \count($requested) > 1000) {
            return $this->seError(400, 'SEAPI-00024', 'Number of recipientEdas must be between 1 and 1000');
        }

        $found = self::known($requested);
        $items = [];
        foreach (self::page($found, $body['offset'] ?? 0, $body['limit'] ?? 20) as $address) {
            $entry = self::address($address) ?? [];
            $items[] = [
                'recipientEda' => self::only($entry['eda'] ?? [], ['recipientEda', 'edaStatus', 'warning', 'designatedOperator']),
                'recipient' => array_diff_key($entry['recipient'] ?? [], ['address' => true]),
            ];
        }

        return $this->json(200, ['edaSearchResponses' => $items, 'totalResults' => \count($found)]);
    }

    /**
     * @param array<mixed> $body
     */
    private function baeSearch(array $body): ResponseInterface
    {
        if (($body['recipientEdasOnly'] ?? false) === true) {
            $requested = self::stringList($body['recipientEdas'] ?? null);
            if ($requested === [] || \count($requested) > 50) {
                return $this->seError(400, 'SEAPI-00024', 'Number of recipientEdas must be between 1 and 50');
            }
            $found = self::known($requested);
        } else {
            if (self::stringList($body['searchCategory'] ?? null) === []) {
                return $this->seError(400, 'SEAPI-00003', 'Invalid or missing search category');
            }
            $found = SyntheticData::CRITERIA_SEARCH_RESULT;
        }

        if ($found === []) {
            return $this->seError(404, 'SEAPI-00010', 'No results found. Please apply less strict criteria');
        }
        $offset = $body['offset'] ?? 0;
        if (\is_int($offset) && $offset >= \count($found)) {
            return $this->seError(404, 'SEAPI-00015', 'Offset must be lower than the number of results');
        }

        $items = [];
        foreach (self::page($found, $offset, $body['limit'] ?? 20) as $address) {
            $entry = self::address($address) ?? [];
            $items[] = ['recipientEda' => $entry['eda'] ?? [], 'baeSearchData' => $entry['baeSearchData'] ?? []];
        }

        return $this->json(200, ['baeSearchResponses' => $items, 'totalResults' => \count($found)]);
    }

    /**
     * @param list<string> $segments decoded path segments
     */
    private function userAgent(RequestInterface $request, array $segments, string $path): ResponseInterface
    {
        $receiving = $this->receiving($request, $segments);
        if ($receiving !== null) {
            return $receiving;
        }
        if ($request->getMethod() !== 'GET') {
            return $this->uaError(405, 'method_not_allowed', \sprintf(
                '%s %s: not supported; the SDK does not send or delete messages.',
                $request->getMethod(),
                $path,
            ));
        }

        $route = self::route($segments);
        if ($route === null) {
            return $this->uaError(404, 'not_found', 'No such endpoint: GET ' . $path);
        }
        [$name, $pathMailbox, $id] = $route;

        $mailbox = $this->mailboxOf($request);
        if ($mailbox === null) {
            return $this->uaError(401, 'unauthorized', 'Missing or unknown bearer token');
        }
        if ($pathMailbox !== null && $pathMailbox !== $mailbox) {
            return $this->uaError(403, 'forbidden', 'The access token was not issued for this mailbox');
        }

        parse_str($request->getUri()->getQuery(), $query);

        return match ($name) {
            'countries' => $this->json(200, SyntheticData::COUNTRIES),
            'directories' => $this->json(200, array_map(static fn(array $d): array => array_diff_key($d, ['numberOfUnreadMessages' => true, 'total' => true]), SyntheticData::DIRECTORIES)),
            'numberMessages' => $this->json(200, SyntheticData::DIRECTORIES),
            'settings' => $this->json(200, SyntheticData::SETTINGS),
            'messages' => $this->messages($query),
            'threads' => $this->json(200, ['total' => \count(SyntheticData::THREADS), 'Thread' => self::page(SyntheticData::THREADS, $query['offset'] ?? 0, $query['limit'] ?? null)]),
            'thread' => $this->thread((string) $id, $query),
            'messageEvidences' => self::message((string) $id) === null
                ? $this->uaError(400, 'INVALID_PARAMETER', 'Unknown message id')
                : $this->json(200, ['evidences' => self::evidences($query, (string) $id)]),
            'mailboxEvidences' => $this->json(200, ['evidences' => self::evidences($query, null)]),
            'evidence' => $this->evidenceMetadata((string) $id),
            'evidenceFile' => self::evidence((string) $id) === null
                ? $this->uaError(400, 'INVALID_PARAMETER', 'Unknown evidence id')
                : $this->evidenceFile((string) $id, (string) $id, $query),
            'hybridAcknowledgement' => self::message((string) $id) === null
                ? $this->uaError(400, 'INVALID_PARAMETER', 'Unknown message id')
                : $this->evidenceFile('H.EPO-' . $id, (string) $id, $query),
            default => self::message((string) $id) === null
                ? $this->uaError(400, 'INVALID_PARAMETER', 'Unknown message id')
                : $this->binary('application/zip', $id . '-technical-evidences.zip', self::zip(
                    'README.txt',
                    "Synthetic technical evidences of message {$id} served by FakeEdoreczenia.\nNot a real archive from e-Doręczenia.\n",
                )),
        };
    }

    // --- Receiving: opening messages and attachments ---

    /**
     * Handles GET messages/{id}, GET messages/{id}/attachments/{attachmentId} and POST attachments/download;
     * returns null for every other request.
     *
     * @param list<string> $segments decoded path segments
     */
    private function receiving(RequestInterface $request, array $segments): ?ResponseInterface
    {
        $n = \count($segments);
        $at = static fn(int $fromEnd): ?string => $segments[$n - $fromEnd] ?? null;
        $method = $request->getMethod();

        [$name, $pathMailbox] = match (true) {
            $method === 'POST' && $n >= 3 && $at(2) === 'attachments' && $at(1) === 'download' => ['downloadAttachment', $at(3)],
            $method === 'GET' && $n >= 5 && $at(4) === 'messages' && $at(2) === 'attachments' => ['attachment', $at(5)],
            $method === 'GET' && $n >= 3 && $at(2) === 'messages' && $at(1) !== 'evidences' => ['open', $at(3)],
            default => [null, null],
        };
        if ($name === null) {
            return null;
        }

        $mailbox = $this->mailboxOf($request);
        if ($mailbox === null) {
            return $this->uaError(401, 'unauthorized', 'Missing or unknown bearer token');
        }
        if ($pathMailbox !== $mailbox) {
            return $this->uaError(403, 'forbidden', 'The access token was not issued for this mailbox');
        }

        if ($name === 'open') {
            parse_str($request->getUri()->getQuery(), $query);

            return $this->openMessage((string) $at(1), $query['format'] ?? 'full');
        }
        if ($name === 'attachment') {
            $file = self::attachmentWithContent((string) $at(3), (string) $at(1));

            return $file === null ? $this->uaError(400, 'INVALID_PARAMETER', 'Unknown message or attachment id') : $this->json(200, $file);
        }

        $body = json_decode((string) $request->getBody(), true);
        $messageId = \is_array($body) ? ($body['messageId'] ?? null) : null;
        $attachmentId = \is_array($body) ? ($body['attachmentId'] ?? null) : null;
        if (!\is_string($messageId) || !\is_string($attachmentId)) {
            return $this->uaError(400, 'INVALID_PARAMETER', 'messageId and attachmentId are required');
        }
        $attachment = self::attachmentMetadata($messageId, $attachmentId);
        if ($attachment === null) {
            return $this->uaError(400, 'INVALID_PARAMETER', 'Unknown message or attachment id');
        }

        return $this->binary('application/octet-stream', (string) ($attachment['file']['fileMetadata']['filename'] ?? $attachmentId), SyntheticData::ATTACHMENT_FILES[$attachmentId] ?? '');
    }

    private function openMessage(string $id, mixed $format): ResponseInterface
    {
        $message = $id === SyntheticData::PENDING_MESSAGE_ID ? SyntheticData::PENDING_MESSAGE : self::message($id);
        if ($message === null) {
            return $this->uaError(400, 'INVALID_PARAMETER', 'Unknown message id');
        }
        if (!\in_array($format, ['fullExtended', 'full', 'metadata', 'minimal'], true)) {
            return $this->uaError(400, 'INVALID_PARAMETER', 'Unknown format');
        }
        if ($format === 'minimal') {
            $metadata = \is_array($message['messageMetadata'] ?? null) ? $message['messageMetadata'] : [];

            return $this->json(200, [['messageMetadata' => array_intersect_key($metadata, ['messageId' => true, 'shippingService' => true])]]);
        }
        if ($format === 'metadata') {
            return $this->json(200, [$this->withOpenedFlag($message)]);
        }

        if ($id === SyntheticData::PENDING_MESSAGE_ID && !isset($this->pendingAnswered[$id])) {
            $this->pendingAnswered[$id] = true;

            return $this->json(202, ['status' => 'PENDING']);
        }

        // Opening has legal effect in the real service; here it only flips the "opened" flag.
        $this->openedMessages[$id] = true;
        $content = SyntheticData::MESSAGE_CONTENTS[$id] ?? ['textBody' => null, 'attachments' => []];
        $attachments = $format === 'fullExtended'
            ? array_map(static fn(array $a): array => self::attachmentWithContent($id, $a['attachmentId']) ?? $a, $content['attachments'])
            : $content['attachments'];

        return $this->json(200, [$this->withOpenedFlag($message) + array_filter(['textBody' => $content['textBody'], 'attachments' => $attachments], static fn(mixed $v): bool => $v !== null && $v !== [])]);
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function withOpenedFlag(array $message): array
    {
        $id = \is_array($message['messageMetadata'] ?? null) ? ($message['messageMetadata']['messageId'] ?? null) : null;
        if (\is_string($id) && isset($this->openedMessages[$id]) && \is_array($message['messageControlData'] ?? null)) {
            $message['messageControlData']['opened'] = true;
        }

        return $message;
    }

    /**
     * @return array{order: int, attachmentId: string, file: array{fileMetadata: array<string, int|string>}}|null
     */
    private static function attachmentMetadata(string $messageId, string $attachmentId): ?array
    {
        foreach (SyntheticData::MESSAGE_CONTENTS[$messageId]['attachments'] ?? [] as $attachment) {
            if ($attachment['attachmentId'] === $attachmentId) {
                return $attachment;
            }
        }

        return null;
    }

    /**
     * The attachment with its Base64 content in "file.file".
     *
     * @return array<string, mixed>|null
     */
    private static function attachmentWithContent(string $messageId, string $attachmentId): ?array
    {
        $attachment = self::attachmentMetadata($messageId, $attachmentId);
        if ($attachment === null) {
            return null;
        }
        $attachment['file']['file'] = base64_encode(SyntheticData::ATTACHMENT_FILES[$attachmentId] ?? '');

        return $attachment;
    }

    // --- End of receiving ---

    /**
     * @param list<string> $s decoded path segments
     *
     * @return array{string, ?string, ?string}|null route name, mailbox from the path, id from the path
     */
    private static function route(array $s): ?array
    {
        $n = \count($s);
        $at = static fn(int $fromEnd): ?string => $s[$n - $fromEnd] ?? null;

        return match (true) {
            $at(2) === 'dictionary' && $at(1) === 'countries' => ['countries', null, null],
            $n >= 4 && $at(3) === 'messages' && $at(2) === 'evidences' => ['evidence', $at(4), $at(1)],
            $n >= 3 && $at(2) === 'messages' && $at(1) === 'evidences' => ['mailboxEvidences', $at(3), null],
            $n >= 4 && $at(3) === 'messages' && $at(1) === 'evidences' => ['messageEvidences', $at(4), $at(2)],
            $n >= 4 && $at(3) === 'evidences' && $at(2) === 'puhepo' => ['hybridAcknowledgement', $at(4), $at(1)],
            $n >= 4 && $at(3) === 'evidences' && \in_array($at(2), ['purde', 'puh'], true) => ['evidenceFile', $at(4), $at(1)],
            $n >= 4 && $at(3) === 'evidences' && $at(1) === 'technical-evidences-file' => ['technicalEvidences', $at(4), $at(2)],
            $n >= 3 && $at(2) === 'threads' => ['thread', $at(3), $at(1)],
            $n >= 2 && \in_array($at(1), self::MAILBOX_ROUTES, true) => [$at(1), $at(2), null],
            default => null,
        };
    }

    /**
     * @param array<mixed> $query
     */
    private function messages(array $query): ResponseInterface
    {
        $page = array_map($this->withOpenedFlag(...), self::page(SyntheticData::MESSAGES, $query['offset'] ?? 0, $query['limit'] ?? null));
        $electronic = array_filter(SyntheticData::MESSAGES, static fn(array $m): bool => $m['messageMetadata']['shippingService'] === 'electronic');

        return $this->json(200, [
            'messages' => $page,
            'resultSize' => \count($page),
            'total' => \count(SyntheticData::MESSAGES),
            'electronicCount' => \count($electronic),
        ]);
    }

    /**
     * @param array<mixed> $query
     */
    private function thread(string $id, array $query): ResponseInterface
    {
        $known = array_filter(SyntheticData::THREADS, static fn(array $t): bool => $t['threadId'] === $id);
        if ($known === []) {
            return $this->uaError(400, 'INVALID_PARAMETER', 'Unknown thread id');
        }

        $messages = array_values(array_filter(SyntheticData::MESSAGES, static fn(array $m): bool => ($m['messageMetadata']['threadId'] ?? null) === $id));
        $page = array_map($this->withOpenedFlag(...), self::page($messages, $query['offset'] ?? 0, $query['limit'] ?? null));

        return $this->json(200, ['threadId' => $id, 'messages' => ['messages' => $page, 'resultSize' => \count($page), 'total' => \count($messages)]]);
    }

    private function evidenceMetadata(string $id): ResponseInterface
    {
        $evidence = self::evidence($id);
        if ($evidence === null) {
            return $this->uaError(400, 'INVALID_PARAMETER', 'Unknown evidence id');
        }

        // The contract returns a one-element array of the Evidence schema here.
        return $this->json(200, [self::only($evidence, ['evidenceId', 'messageId', 'type', 'eventDate', 'downloaded', 'reasonId', 'reasonDetails'])]);
    }

    /**
     * @param array<mixed> $query
     */
    private function evidenceFile(string $fileName, string $id, array $query): ResponseInterface
    {
        if (($query['evidenceFormat'] ?? null) === 'PDF') {
            return $this->binary('application/pdf', $fileName . '.pdf', "%PDF-1.4\n% Synthetic placeholder served by FakeEdoreczenia, not an evidence.\n%%EOF\n");
        }

        $xml = \sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!-- Synthetic document served by FakeEdoreczenia; not a real, signed evidence. -->\n<Evidence id=\"%s\"/>\n",
            htmlspecialchars($id, \ENT_XML1 | \ENT_QUOTES, 'UTF-8'),
        );

        return $this->binary('application/xml', $fileName . '.xml', $xml);
    }

    /**
     * @param array<mixed> $query
     *
     * @return list<array<string, mixed>>
     */
    private static function evidences(array $query, ?string $messageId): array
    {
        // Evidence codes come in dotted ("E.1") and undotted ("E1") spellings; both select the same type.
        $type = \is_string($query['type'] ?? null) ? str_replace('.', '', $query['type']) : null;
        $downloaded = $query['downloaded'] ?? null;

        return array_values(array_filter(
            SyntheticData::EVIDENCES,
            static fn(array $e): bool => ($messageId === null || $e['messageId'] === $messageId)
                && ($type === null || str_replace('.', '', $e['type']) === $type)
                && ($downloaded === null || (($e['downloaded'] ?? false) ? 'true' : 'false') === $downloaded),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function evidence(string $id): ?array
    {
        foreach (SyntheticData::EVIDENCES as $evidence) {
            if ($evidence['evidenceId'] === $id) {
                return $evidence;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function message(string $id): ?array
    {
        foreach (SyntheticData::MESSAGES as $message) {
            if ($message['messageMetadata']['messageId'] === $id) {
                return $message;
            }
        }

        return null;
    }

    /**
     * @return array{eda: array<string, mixed>, recipient: array<string, mixed>, baeSearchData: list<array<string, mixed>>}|null
     */
    private static function address(string $address): ?array
    {
        return SyntheticData::ADDRESSES[$address] ?? null;
    }

    /**
     * Requested addresses present in the dataset, in request order, without duplicates.
     *
     * @param list<string> $requested
     *
     * @return list<string>
     */
    private static function known(array $requested): array
    {
        return array_values(array_unique(array_filter($requested, static fn(string $a): bool => self::address($a) !== null)));
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [];
    }

    /**
     * @template T
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    private static function page(array $items, mixed $offset, mixed $limit): array
    {
        $offset = is_numeric($offset) ? max(0, (int) $offset) : 0;
        $limit = is_numeric($limit) ? max(1, (int) $limit) : null;

        return \array_slice($items, $offset, $limit);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $keys
     *
     * @return array<string, mixed>
     */
    private static function only(array $data, array $keys): array
    {
        return array_intersect_key($data, array_flip($keys));
    }

    /**
     * Minimal ZIP archive with one stored (uncompressed) entry.
     */
    private static function zip(string $name, string $content): string
    {
        $crc = crc32($content);
        $size = \strlen($content);
        $nameLength = \strlen($name);
        $dosDate = (0 << 9) | (1 << 5) | 1; // 1980-01-01

        $local = pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, $dosDate, $crc, $size, $size, $nameLength, 0) . $name . $content;
        $central = pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 0, 0, $dosDate, $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, 0) . $name;

        return $local . $central . pack('VvvvvVVv', 0x06054B50, 0, 0, 1, 1, \strlen($central), \strlen($local), 0);
    }

    private function seError(int $status, string $code, string $message): ResponseInterface
    {
        $id = bin2hex(random_bytes(16));

        return $this->json($status, [
            'httpCode' => (string) $status,
            'id' => \sprintf('%s-%s-4%s-a%s-%s', substr($id, 0, 8), substr($id, 8, 4), substr($id, 13, 3), substr($id, 17, 3), substr($id, 20, 12)),
            'errorCodes' => [['errorCode' => $code, 'language' => 'GB', 'message' => $message]],
        ]);
    }

    private function uaError(int $status, string $error, string $description): ResponseInterface
    {
        return $this->json($status, [['error' => $error, 'error_description' => $description]]);
    }

    /**
     * @param array<mixed> $data
     */
    private function json(int $status, array $data): ResponseInterface
    {
        return $this->respond($status, 'application/json', json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    private function binary(string $contentType, string $fileName, string $content): ResponseInterface
    {
        return $this->respond(200, $contentType, $content)
            ->withHeader('Content-Disposition', \sprintf('attachment; filename="%s"', $fileName))
            ->withHeader('Content-Length', (string) \strlen($content));
    }

    private function respond(int $status, string $contentType, string $content): ResponseInterface
    {
        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', $contentType)
            ->withBody($this->streamFactory->createStream($content));
    }
}
