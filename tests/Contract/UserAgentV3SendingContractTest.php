<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Contract;

use B4x\Edoreczenia\Testing\FakeEdoreczenia;
use B4x\Edoreczenia\Testing\SyntheticData;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\UserAgent\V3\Enum\DraftSortColumn;
use B4x\Edoreczenia\UserAgent\V3\Enum\MessageTaskState;
use B4x\Edoreczenia\UserAgent\V3\Enum\ShippingService;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortDirection;
use B4x\Edoreczenia\UserAgent\V3\Request\AttachmentUpload;
use B4x\Edoreczenia\UserAgent\V3\Request\DraftQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingAttachment;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingMessage;
use B4x\Edoreczenia\UserAgent\V3\UserAgentClient;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks the request bodies the SDK builds for sending and drafts, the response fixtures and the testing kit
 * against the official UA API 3.0.8 contract.
 *
 * Requires `composer specs:fetch`; skipped otherwise.
 */
final class UserAgentV3SendingContractTest extends TestCase
{
    private const SPEC = __DIR__ . '/../../build/specs/uaapi_3.0.8.2.yaml';
    private const MAILBOX = 'AE:PL-12345-67890-ABCDE-12';
    private const UA = 'https://uaapi-int-ow.poczta-polska.pl/api/v3/';

    /** @var array<mixed>|null */
    private static ?array $spec = null;

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function operations(): iterable
    {
        yield 'send' => ['/{eDeliveryAddress}/messages', 'post', 'Message'];
        yield 'create draft' => ['/{eDeliveryAddress}/drafts', 'post', 'Message'];
        yield 'update draft' => ['/{eDeliveryAddress}/drafts/{messageId}', 'put', 'Message'];
        yield 'send draft' => ['/{eDeliveryAddress}/drafts/send', 'post', 'SendDraft'];
        yield 'add attachment' => ['/{eDeliveryAddress}/drafts/{messageId}/attachments', 'post', 'FileData'];
    }

    #[DataProvider('operations')]
    public function testOperationsTakeTheExpectedRequestSchema(string $path, string $method, string $schema): void
    {
        $body = self::node('paths', $path, $method, 'requestBody');
        self::assertIsArray($body);
        if (isset($body['$ref']) && \is_string($body['$ref'])) {
            $body = self::node('components', 'requestBodies', basename($body['$ref']));
            self::assertIsArray($body);
        }
        self::assertSame('#/components/schemas/' . $schema, self::nodeIn($body, 'content', 'application/json', 'schema', '$ref'));
    }

    public function testUsedOperationsExist(): void
    {
        foreach ([
            ['/{eDeliveryAddress}/messages/tasks/{messageTaskId}', 'get'],
            ['/{eDeliveryAddress}/messages/tasks/{messageTaskId}/status', 'get'],
            ['/{eDeliveryAddress}/drafts', 'get'],
            ['/{eDeliveryAddress}/drafts/{messageId}', 'get'],
            ['/{eDeliveryAddress}/drafts/{messageId}', 'delete'],
            ['/{eDeliveryAddress}/drafts/{messageId}/attachments', 'delete'],
            ['/{eDeliveryAddress}/drafts/{messageId}/attachments/{attachmentId}', 'delete'],
            ['/{eDeliveryAddress}/{messageId}/attachments/upload', 'post'],
        ] as [$path, $method]) {
            self::assertIsArray(self::node('paths', $path, $method), $method . ' ' . $path);
        }
    }

    public function testSendOperationsAnswer202WithMessageInfo(): void
    {
        foreach (['/{eDeliveryAddress}/messages', '/{eDeliveryAddress}/drafts/send'] as $path) {
            self::assertSame('#/components/responses/MassMessageResponse', self::node('paths', $path, 'post', 'responses', 202, '$ref'));
        }
        self::assertSame('#/components/schemas/MessageInfo', self::node('components', 'responses', 'MassMessageResponse', 'content', 'application/json', 'schema', '$ref'));
    }

    public function testDeleteDraftsLimitEqualsContract(): void
    {
        $parameters = self::node('paths', '/{eDeliveryAddress}/drafts/{messageId}', 'delete', 'parameters');
        self::assertIsArray($parameters);
        $messageId = array_values(array_filter($parameters, static fn(mixed $p): bool => \is_array($p) && ($p['name'] ?? null) === 'messageId'))[0] ?? null;
        self::assertIsArray($messageId);
        self::assertSame('array', self::nodeIn($messageId, 'schema', 'type'));
        self::assertSame(UserAgentClient::MAX_DELETED_DRAFTS, self::nodeIn($messageId, 'schema', 'maxItems'));
    }

    public function testTextBodyLimitEqualsContract(): void
    {
        self::assertSame(OutgoingMessage::MAX_TEXT_BODY_LENGTH, self::node('components', 'schemas', 'Message', 'properties', 'textBody', 'maxLength'));
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function requestBodies(): iterable
    {
        $attachment = OutgoingAttachment::fromString('%PDF-1.4 synthetic', 'pismo.pdf', 'application/pdf')->withDescription('Pismo');
        $full = OutgoingMessage::create('Odpowiedź')
            ->withRecipients(SyntheticData::ACTIVE_RECIPIENT, 'AE:PL-12345-67890-ABCDE-10')
            ->withTextBody(str_repeat('ż', OutgoingMessage::MAX_TEXT_BODY_LENGTH))
            ->inThread('T-1')
            ->referringTo(SyntheticData::MESSAGE_ID)
            ->withCaseId('ZNAK.1.2026')
            ->withAdditionalData('k', 'v')
            ->withAttachment($attachment)
            ->withAttachment(OutgoingAttachment::fromString('a', 'a.txt', 'text/plain'));

        yield 'minimal message' => [OutgoingMessage::create('S')->withRecipients(SyntheticData::ACTIVE_RECIPIENT)->toPayload(self::MAILBOX), 'Message'];
        yield 'draft without recipients' => [OutgoingMessage::create('S')->toPayload(self::MAILBOX), 'Message'];
        yield 'full message' => [$full->toPayload(self::MAILBOX), 'Message'];
        yield 'file data' => [$attachment->toFileData(), 'FileData'];
        yield 'send draft' => [['messageId' => 'D-1'], 'SendDraft'];
    }

    /**
     * Valid against the schema, and every property the SDK sends is declared by it.
     *
     * @param array<mixed> $body
     */
    #[DataProvider('requestBodies')]
    public function testRequestBodiesMatchContract(array $body, string $schema): void
    {
        $decoded = json_decode(json_encode($body, \JSON_THROW_ON_ERROR), true, 64, \JSON_THROW_ON_ERROR);

        self::assertSame([], (new OpenApiSchemaValidator(self::spec()))->validate($decoded, $schema));
        self::assertSame([], $this->undeclared($decoded, ['$ref' => '#/components/schemas/' . $schema], '$'));
    }

    public function testMultipartFieldsAreDeclared(): void
    {
        $schema = self::node('components', 'schemas', 'AttachmentUploadRequestBody');
        self::assertIsArray($schema);
        self::assertSame('#/components/schemas/AttachmentUploadRequestBody', self::node('paths', '/{eDeliveryAddress}/{messageId}/attachments/upload', 'post', 'requestBody', 'content', 'multipart/form-data', 'schema', '$ref'));
        $declared = array_keys((array) ($schema['properties'] ?? []));

        $body = (string) AttachmentUpload::fromStream(Stream::create('x'), 'a.zip', 'application/zip')->withDescription('d')->toMultipart('B');
        preg_match_all('/form-data; name="([^"]+)"/', $body, $m);

        self::assertSame(['filename', 'contentType', 'size', 'description', 'file'], $m[1]);
        foreach ($m[1] as $field) {
            self::assertContains($field, $declared, $field);
        }
        foreach ((array) ($schema['required'] ?? []) as $required) {
            self::assertContains($required, $m[1]);
        }
        self::assertSame('string', self::nodeIn($schema, 'properties', 'size', 'type'), 'size is a string field');
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function fixtures(): iterable
    {
        yield 'send 202' => ['user-agent-v3/send.202.json', 'MessageInfo', false];
        yield 'task status' => ['user-agent-v3/message-task-status.200.json', 'MessageTaskStatus', false];
        yield 'draft' => ['user-agent-v3/draft.200.json', 'Message', false];
        yield 'drafts' => ['user-agent-v3/drafts.200.json', 'MessagesWrapper', false];
        yield 'draft saved' => ['user-agent-v3/draft-saved.200.json', 'MessageOperationResponseSingleWrapperPut', false];
        yield 'attachment added' => ['user-agent-v3/draft-attachment.200.json', 'FileWrapper', false];
        yield 'attachment deleted' => ['user-agent-v3/draft-attachment-deleted.200.json', 'FileMetadata', false];
        yield 'drafts deleted' => ['user-agent-v3/drafts-deleted.200.json', 'MessageOperationResponseSingleWrapper', true];
    }

    #[DataProvider('fixtures')]
    public function testFixturesMatchContract(string $fixture, string $schema, bool $list): void
    {
        $data = json_decode(Http::fixtureBody($fixture), true, 64, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        foreach ($list ? $data : [$data] as $item) {
            self::assertSame([], (new OpenApiSchemaValidator(self::spec()))->validate($item, $schema));
        }
    }

    /**
     * "MessageOperationResponseWrapperStatus" requires "addresseeAde" and "status" without defining them;
     * the fixture follows the defined properties, so exactly those two are reported missing.
     */
    public function testTaskFixtureDiffersOnlyByUndefinedRequiredFields(): void
    {
        $required = self::node('components', 'schemas', 'MessageOperationResponseWrapperStatus', 'required');
        $properties = self::node('components', 'schemas', 'MessageOperationResponseWrapperStatus', 'properties');
        self::assertIsArray($required);
        self::assertIsArray($properties);
        self::assertSame(['addresseeAde', 'status'], array_values(array_diff(array_filter($required, \is_string(...)), array_keys($properties))));

        $items = json_decode(Http::fixtureBody('user-agent-v3/message-task.200.json'), true, 64, \JSON_THROW_ON_ERROR);
        self::assertIsArray($items);
        foreach ($items as $item) {
            self::assertSame(
                ['$: missing required "addresseeAde"', '$: missing required "status"'],
                (new OpenApiSchemaValidator(self::spec()))->validate($item, 'MessageOperationResponseWrapperStatus'),
            );
        }
    }

    public function testEnumsAreInSyncWithContract(): void
    {
        self::assertEqualsCanonicalizing(self::node('components', 'schemas', 'MessageTaskStatus', 'properties', 'messageTaskStatus', 'enum'), array_map(static fn(MessageTaskState $s): string => $s->value, MessageTaskState::cases()));
        self::assertEqualsCanonicalizing(self::draftParameter('sortColumn')['enum'] ?? null, array_map(static fn(DraftSortColumn $c): string => $c->value, DraftSortColumn::cases()));
        self::assertEqualsCanonicalizing(self::draftParameter('sortDirection')['enum'] ?? null, array_map(static fn(SortDirection $d): string => $d->value, SortDirection::cases()));
        self::assertEqualsCanonicalizing(self::draftParameter('shippingService')['enum'] ?? null, array_map(static fn(ShippingService $s): string => $s->value, ShippingService::cases()));
    }

    public function testDraftQueryParametersAreDeclared(): void
    {
        $query = DraftQuery::create()->withPagination(0, DraftQuery::MAX_LIMIT)->toRecipient('A')->withSubject('B')->withAttachments()
            ->withLabel('C')->withShippingService(ShippingService::Electronic)->sortBy(DraftSortColumn::Recipient)->toQuery();

        foreach ($query as $name => $value) {
            $schema = self::draftParameter($name);
            self::assertNotSame([], $schema, $name);
            if (isset($schema['enum']) && \is_array($schema['enum'])) {
                self::assertContains($value, $schema['enum'], $name);
            }
            if (isset($schema['maximum'])) {
                self::assertSame($schema['maximum'], $value, $name);
            }
        }
    }

    public function testFakeResponsesMatchContract(): void
    {
        $fake = new FakeEdoreczenia(Http::factory(), Http::factory());
        $ua = $fake->createClient()->userAgent();
        $draft = $ua->createDraft(OutgoingMessage::create('S')->withRecipients(SyntheticData::ACTIVE_RECIPIENT)->withAttachment(OutgoingAttachment::fromString('x', 'a.txt', 'text/plain')))->messageId;
        $added = $ua->addDraftAttachment($draft, OutgoingAttachment::fromString('y', 'b.txt', 'text/plain'))->attachmentId;
        $task = $ua->sendMessage(OutgoingMessage::create('S')->withRecipients(SyntheticData::ACTIVE_RECIPIENT, SyntheticData::INACTIVE_RECIPIENT))->messageTaskId;
        $fake->acceptToken('t', self::MAILBOX);
        $validator = new OpenApiSchemaValidator(self::spec());

        $get = static function (string $path) use ($fake): mixed {
            $response = $fake->sendRequest(Http::factory()->createRequest('GET', self::UA . self::MAILBOX . '/' . $path)->withHeader('Authorization', 'Bearer t'));

            return json_decode((string) $response->getBody(), true, 64, \JSON_THROW_ON_ERROR);
        };

        self::assertSame([], $validator->validate($get('drafts'), 'MessagesWrapper'));
        self::assertSame([], $validator->validate($get('drafts/' . $draft), 'Message'));
        self::assertSame([], $validator->validate($get('drafts/' . $draft . '/attachments/' . $added), 'Attachment'));
        self::assertSame([], $validator->validate($get('messages/tasks/' . $task . '/status'), 'MessageTaskStatus'));
        $errors = $get('drafts/D-404');
        self::assertIsArray($errors);
        self::assertNotSame([], $errors);
        foreach ($errors as $error) {
            self::assertSame([], $validator->validate($error, 'ErrorInfo'));
        }

        $items = $get('messages/tasks/' . $task);
        self::assertIsArray($items);
        foreach ($items as $item) {
            $violations = array_diff($validator->validate($item, 'MessageOperationResponseWrapperStatus'), ['$: missing required "addresseeAde"', '$: missing required "status"']);
            self::assertSame([], array_values($violations));
        }
    }

    /**
     * Paths of properties present in $value but not declared by $schema (schemas without "properties" accept anything).
     *
     * @param array<mixed> $schema
     *
     * @return list<string>
     */
    private function undeclared(mixed $value, array $schema, string $path): array
    {
        while (isset($schema['$ref']) && \is_string($schema['$ref'])) {
            $resolved = self::node('components', 'schemas', basename($schema['$ref']));
            self::assertIsArray($resolved);
            $schema = $resolved;
        }
        if (!\is_array($value)) {
            return [];
        }
        if (array_is_list($value)) {
            $items = \is_array($schema['items'] ?? null) ? $schema['items'] : [];
            $found = [];
            foreach ($value as $i => $item) {
                $found = [...$found, ...$this->undeclared($item, $items, $path . '[' . $i . ']')];
            }

            return $found;
        }
        $properties = $schema['properties'] ?? null;
        if (!\is_array($properties)) {
            return [];
        }
        $found = [];
        foreach ($value as $name => $item) {
            if (!\is_array($properties[$name] ?? null)) {
                $found[] = $path . '.' . $name;
                continue;
            }
            $found = [...$found, ...$this->undeclared($item, $properties[$name], $path . '.' . $name)];
        }

        return $found;
    }

    /**
     * @return array<mixed>
     */
    private static function draftParameter(string $name): array
    {
        $parameters = self::node('paths', '/{eDeliveryAddress}/drafts', 'get', 'parameters');
        self::assertIsArray($parameters);
        foreach ($parameters as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === $name && ($parameter['in'] ?? null) === 'query' && \is_array($parameter['schema'] ?? null)) {
                return $parameter['schema'];
            }
        }

        return [];
    }

    private static function node(string|int ...$path): mixed
    {
        return self::nodeIn(self::spec(), ...$path);
    }

    /**
     * @param array<mixed> $node
     */
    private static function nodeIn(array $node, string|int ...$path): mixed
    {
        $current = $node;
        foreach ($path as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @return array<mixed>
     */
    private static function spec(): array
    {
        if (self::$spec === null) {
            if (!is_file(self::SPEC)) {
                self::markTestSkipped('Official UA API contract not available; run "composer specs:fetch".');
            }
            $parsed = Yaml::parseFile(self::SPEC);
            self::assertIsArray($parsed);
            self::$spec = $parsed;
        }

        return self::$spec;
    }
}
