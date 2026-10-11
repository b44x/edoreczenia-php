<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Contract;

use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\Testing\FakeEdoreczenia;
use B4x\Edoreczenia\Testing\SyntheticData;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\Tests\Support\MockHttpClient;
use B4x\Edoreczenia\Tests\Support\SpyTokenProvider;
use B4x\Edoreczenia\UserAgent\V3\Enum\ArchiveTaskSortColumn;
use B4x\Edoreczenia\UserAgent\V3\Enum\ArchiveTaskStatus;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortDirection;
use B4x\Edoreczenia\UserAgent\V3\Request\ArchiveTaskQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\ArchiveTaskRequest;
use B4x\Edoreczenia\UserAgent\V3\UserAgentClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks deleting messages and message archives (requests the SDK builds, response fixtures and the testing kit)
 * against the official UA API 3.0.8 contract.
 *
 * Requires `composer specs:fetch`; skipped otherwise.
 */
final class UserAgentV3ArchiveContractTest extends TestCase
{
    private const SPEC = __DIR__ . '/../../build/specs/uaapi_3.0.8.2.yaml';
    private const MAILBOX = 'AE:PL-12345-67890-ABCDE-12';
    private const UA = 'https://uaapi-int-ow.poczta-polska.pl/api/v3/';

    /** @var array<mixed>|null */
    private static ?array $spec = null;

    public function testUsedOperationsExist(): void
    {
        foreach ([
            ['/{eDeliveryAddress}/messages/{messageId}', 'delete'],
            ['/{eDeliveryAddress}/message-archive-task', 'post'],
            ['/{eDeliveryAddress}/message-archive-task', 'patch'],
            ['/{eDeliveryAddress}/message-archive-task-list', 'get'],
            ['/{eDeliveryAddress}/message-archive-task/{taskId}/download', 'get'],
            ['/{eDeliveryAddress}/messages/{messageId}/message-archive-file', 'get'],
        ] as [$path, $method]) {
            self::assertIsArray(self::node('paths', $path, $method), $method . ' ' . $path);
        }
    }

    public function testDeleteMessagesMatchesContract(): void
    {
        $operation = self::node('paths', '/{eDeliveryAddress}/messages/{messageId}', 'delete');
        self::assertIsArray($operation);
        self::assertArrayNotHasKey('requestBody', $operation);
        self::assertSame(['write_messages'], self::nodeIn($operation, 'security', 0, 'oAuthUserAgent'));

        $messageId = self::parameter($operation, 'messageId', 'path');
        self::assertSame('array', self::nodeIn($messageId, 'type'));
        self::assertSame(UserAgentClient::MAX_DELETED_MESSAGES, self::nodeIn($messageId, 'maxItems'));

        self::assertSame('array', self::nodeIn($operation, 'responses', 200, 'content', 'application/json', 'schema', 'type'));
        self::assertSame('#/components/schemas/MessageOperationResponseSingleWrapper', self::nodeIn($operation, 'responses', 200, 'content', 'application/json', 'schema', 'items', '$ref'));
        self::assertSame(['messageId'], array_keys((array) self::node('components', 'schemas', 'MessageOperationResponseSingleWrapper', 'properties')), 'DeletedMessage maps every declared field');
    }

    public function testArchiveOperationsTakeTheExpectedSchemas(): void
    {
        self::assertSame('#/components/schemas/MessageArchiveTaskPostBody', self::requestSchema('/{eDeliveryAddress}/message-archive-task', 'post'));
        self::assertSame('#/components/schemas/MessageArchiveTaskPatchBody', self::requestSchema('/{eDeliveryAddress}/message-archive-task', 'patch'));

        self::assertSame('#/components/responses/MessageArchiveTaskPostWrapper', self::node('paths', '/{eDeliveryAddress}/message-archive-task', 'post', 'responses', 202, '$ref'));
        self::assertSame('#/components/schemas/MessageArchiveTaskPostResponse', self::node('components', 'responses', 'MessageArchiveTaskPostWrapper', 'content', 'application/json', 'schema', '$ref'));
        self::assertSame('#/components/responses/MessageArchivingTaskWrapper', self::node('paths', '/{eDeliveryAddress}/message-archive-task', 'patch', 'responses', 202, '$ref'));
        self::assertSame('#/components/responses/MessageArchivingTaskWrapper', self::node('paths', '/{eDeliveryAddress}/message-archive-task-list', 'get', 'responses', 200, '$ref'));
        self::assertSame('#/components/schemas/MessageArchivingTaskResponse', self::node('components', 'responses', 'MessageArchivingTaskWrapper', 'content', 'application/json', 'schema', '$ref'));

        foreach (['/{eDeliveryAddress}/message-archive-task/{taskId}/download', '/{eDeliveryAddress}/messages/{messageId}/message-archive-file'] as $path) {
            self::assertSame('binary', self::node('paths', $path, 'get', 'responses', 200, 'content', 'application/zip', 'schema', 'format'), $path);
        }
        // The single-message archive takes exactly one id, not a list.
        $operation = self::node('paths', '/{eDeliveryAddress}/messages/{messageId}/message-archive-file', 'get');
        self::assertIsArray($operation);
        self::assertSame('string', self::nodeIn(self::parameter($operation, 'messageId', 'path'), 'type'));
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function requestBodies(): iterable
    {
        yield 'archive task, minimal' => [ArchiveTaskRequest::forMessages(SyntheticData::MESSAGE_ID)->toPayload(), 'MessageArchiveTaskPostBody'];
        yield 'archive task, full' => [ArchiveTaskRequest::forMessages(SyntheticData::MESSAGE_ID, SyntheticData::SENT_MESSAGE_ID)->withDescription(str_repeat('ż', ArchiveTaskRequest::MAX_DESCRIPTION_LENGTH))->toPayload(), 'MessageArchiveTaskPostBody'];
    }

    /**
     * Valid against the schema, and every property the SDK sends is declared by it.
     *
     * @param array<mixed> $body
     */
    #[DataProvider('requestBodies')]
    public function testRequestBodiesMatchContract(array $body, string $schema): void
    {
        $this->assertBodyMatches($body, $schema);
    }

    public function testPatchBodiesMatchContract(): void
    {
        $http = new MockHttpClient();
        $ua = (new EdoreczeniaClient(Environment::integration(), self::MAILBOX, new SpyTokenProvider(), $http, Http::factory(), Http::factory()))->userAgent();
        $answer = Http::fixtureBody('user-agent-v3/archive-tasks.200.json');
        $http->queue(Http::json(202, $answer), Http::json(202, $answer), Http::json(202, $answer));

        $ua->cancelArchiveTask('T-1');
        $ua->finishArchiveTask('T-1');
        $ua->finishArchiveTaskAndDeleteMessages('T-1');

        $sent = [];
        foreach ($http->requests as $request) {
            self::assertSame('PATCH', $request->getMethod());
            $body = Http::jsonBody($request);
            $this->assertBodyMatches($body, 'MessageArchiveTaskPatchBody');
            $sent[] = $body['changeStatus'] ?? null;
        }
        self::assertEqualsCanonicalizing(self::node('components', 'schemas', 'MessageArchiveTaskPatchBody', 'properties', 'changeStatus', 'enum'), $sent, 'every changeStatus value is reachable');
    }

    public function testDescriptionLimitEqualsContract(): void
    {
        self::assertSame(ArchiveTaskRequest::MAX_DESCRIPTION_LENGTH, self::node('components', 'schemas', 'MessageArchiveTaskPostBody', 'properties', 'taskDescription', 'maxLength'));
        self::assertNull(self::node('components', 'schemas', 'MessageArchiveTaskPostBody', 'properties', 'messageId', 'maxItems'), 'the contract sets no limit; the SDK sets none either');
    }

    public function testEnumsAreInSyncWithContract(): void
    {
        self::assertEqualsCanonicalizing(self::node('components', 'schemas', 'MessageArchivingTask', 'properties', 'taskStatus', 'enum'), array_map(static fn(ArchiveTaskStatus $s): string => $s->value, ArchiveTaskStatus::cases()));
        self::assertEqualsCanonicalizing(self::listParameter('sortColumn')['enum'] ?? null, array_map(static fn(ArchiveTaskSortColumn $c): string => $c->value, ArchiveTaskSortColumn::cases()));
        self::assertEqualsCanonicalizing(self::listParameter('sortDirection')['enum'] ?? null, array_map(static fn(SortDirection $d): string => $d->value, SortDirection::cases()));
    }

    public function testListQueryParametersAreDeclared(): void
    {
        $query = ArchiveTaskQuery::create()->withPagination(0, ArchiveTaskQuery::MAX_LIMIT)->forTask('T-1')->sortBy(ArchiveTaskSortColumn::CreationDate)->toQuery();

        self::assertSame(['offset', 'limit', 'taskId', 'sortColumn', 'sortDirection'], array_keys($query));
        foreach ($query as $name => $value) {
            $schema = self::listParameter($name);
            self::assertNotSame([], $schema, $name);
            if (isset($schema['enum']) && \is_array($schema['enum'])) {
                self::assertContains($value, $schema['enum'], $name);
            }
            if (isset($schema['maximum'])) {
                self::assertSame($schema['maximum'], $value, $name);
            }
        }
        self::assertSame(ArchiveTaskQuery::DEFAULT_LIMIT, self::listParameter('limit')['default'] ?? null);
    }

    public function testArchiveTaskModelCoversTheSchema(): void
    {
        // Every declared property of MessageArchivingTask is present in the fixture, so the model test reads all of them.
        $declared = array_keys((array) self::node('components', 'schemas', 'MessageArchivingTask', 'properties'));
        $data = json_decode(Http::fixtureBody('user-agent-v3/archive-tasks.200.json'), true, 64, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['tasks']);
        $present = [];
        foreach ($data['tasks'] as $task) {
            self::assertIsArray($task);
            $present = [...$present, ...array_keys($task)];
        }
        self::assertEqualsCanonicalizing($declared, array_values(array_unique($present)));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function fixtures(): iterable
    {
        yield 'messages deleted' => ['user-agent-v3/messages-deleted.200.json', 'MessageOperationResponseSingleWrapper', true];
        yield 'archive task created' => ['user-agent-v3/archive-task.202.json', 'MessageArchiveTaskPostResponse', false];
        yield 'archive tasks' => ['user-agent-v3/archive-tasks.200.json', 'MessageArchivingTaskResponse', false];
    }

    #[DataProvider('fixtures')]
    public function testFixturesMatchContract(string $fixture, string $schema, bool $list): void
    {
        $data = json_decode(Http::fixtureBody($fixture), true, 64, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        foreach ($list ? $data : [$data] as $item) {
            self::assertSame([], (new OpenApiSchemaValidator(self::spec()))->validate($item, $schema));
            self::assertSame([], $this->undeclared($item, ['$ref' => '#/components/schemas/' . $schema], '$'));
        }
    }

    public function testFakeResponsesMatchContract(): void
    {
        $fake = new FakeEdoreczenia(Http::factory(), Http::factory());
        $fake->acceptToken('t', self::MAILBOX);
        $validator = new OpenApiSchemaValidator(self::spec());
        $call = static function (string $method, string $path, ?array $body = null) use ($fake): array {
            $request = Http::factory()->createRequest($method, self::UA . self::MAILBOX . '/' . $path)->withHeader('Authorization', 'Bearer t');
            if ($body !== null) {
                $request = $request->withHeader('Content-Type', 'application/json')->withBody(Http::factory()->createStream(json_encode($body, \JSON_THROW_ON_ERROR)));
            }
            $response = $fake->sendRequest($request);
            $decoded = json_decode((string) $response->getBody(), true, 64, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);

            return [$response->getStatusCode(), $decoded];
        };

        [$status, $created] = $call('POST', 'message-archive-task', ArchiveTaskRequest::forMessages(SyntheticData::MESSAGE_ID)->withDescription('Opis')->toPayload());
        self::assertSame(202, $status);
        self::assertSame([], $validator->validate($created, 'MessageArchiveTaskPostResponse'));
        self::assertIsString($created['taskId'] ?? null);

        foreach (['in progress', 'ready'] as $state) {
            [$status, $list] = $call('GET', 'message-archive-task-list');
            self::assertSame(200, $status);
            self::assertSame([], $validator->validate($list, 'MessageArchivingTaskResponse'), $state);
            self::assertSame([], $this->undeclared($list, ['$ref' => '#/components/schemas/MessageArchivingTaskResponse'], '$'), $state);
        }

        [$status, $changed] = $call('PATCH', 'message-archive-task', ['taskId' => $created['taskId'], 'changeStatus' => 'FINISH_WITH_DELETING_MESSAGES']);
        self::assertSame(202, $status);
        self::assertSame([], $validator->validate($changed, 'MessageArchivingTaskResponse'));
        self::assertSame([], $this->undeclared($changed, ['$ref' => '#/components/schemas/MessageArchivingTaskResponse'], '$'));

        [$status, $deleted] = $call('DELETE', 'messages/' . SyntheticData::SENT_MESSAGE_ID);
        self::assertSame(200, $status);
        foreach ($deleted as $item) {
            self::assertSame([], $validator->validate($item, 'MessageOperationResponseSingleWrapper'));
        }

        [$status, $errors] = $call('DELETE', 'messages/' . SyntheticData::MESSAGE_ID);
        self::assertSame(400, $status, 'already deleted by the finished task');
        foreach ($errors as $error) {
            self::assertSame([], $validator->validate($error, 'ErrorInfo'));
            self::assertSame([], $this->undeclared($error, ['$ref' => '#/components/schemas/ErrorInfo'], '$'));
        }
    }

    /**
     * @param array<mixed> $body
     */
    private function assertBodyMatches(array $body, string $schema): void
    {
        $decoded = json_decode(json_encode($body, \JSON_THROW_ON_ERROR), true, 64, \JSON_THROW_ON_ERROR);

        self::assertSame([], (new OpenApiSchemaValidator(self::spec()))->validate($decoded, $schema));
        self::assertSame([], $this->undeclared($decoded, ['$ref' => '#/components/schemas/' . $schema], '$'));
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

    private static function requestSchema(string $path, string $method): mixed
    {
        $body = self::node('paths', $path, $method, 'requestBody');
        self::assertIsArray($body);
        if (isset($body['$ref']) && \is_string($body['$ref'])) {
            $body = self::node('components', 'requestBodies', basename($body['$ref']));
            self::assertIsArray($body);
        }

        return self::nodeIn($body, 'content', 'application/json', 'schema', '$ref');
    }

    /**
     * @param array<mixed> $operation
     *
     * @return array<mixed>
     */
    private static function parameter(array $operation, string $name, string $in): array
    {
        foreach ((array) ($operation['parameters'] ?? []) as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === $name && ($parameter['in'] ?? null) === $in && \is_array($parameter['schema'] ?? null)) {
                return $parameter['schema'];
            }
        }

        return [];
    }

    /**
     * @return array<mixed>
     */
    private static function listParameter(string $name): array
    {
        $operation = self::node('paths', '/{eDeliveryAddress}/message-archive-task-list', 'get');
        self::assertIsArray($operation);

        return self::parameter($operation, $name, 'query');
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
