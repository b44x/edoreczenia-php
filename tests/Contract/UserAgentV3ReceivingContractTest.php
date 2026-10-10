<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Contract;

use B4x\Edoreczenia\Testing\FakeEdoreczenia;
use B4x\Edoreczenia\Testing\SyntheticData;
use B4x\Edoreczenia\Tests\Support\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks opening messages and attachment downloads (fixtures, fake responses, request shapes)
 * against the official UA API 3.0.8 contract.
 *
 * Requires `composer specs:fetch`; skipped otherwise.
 */
final class UserAgentV3ReceivingContractTest extends TestCase
{
    private const SPEC = __DIR__ . '/../../build/specs/uaapi_3.0.8.2.yaml';
    private const UA = 'https://uaapi-int-ow.poczta-polska.pl/api/v3/';
    private const MAILBOX = SyntheticData::MAILBOX;

    /** @var array<mixed>|null */
    private static ?array $spec = null;

    public function testOpenMessageOperation(): void
    {
        $operation = '/{eDeliveryAddress}/messages/{messageId}';

        $schema = $this->parameter($operation, 'get', 'format')['schema'] ?? null;
        self::assertIsArray($schema);
        $formats = $schema['enum'] ?? null;
        self::assertIsArray($formats);
        self::assertContains('full', $formats);
        self::assertContains('fullExtended', $formats);
        self::assertSame('path', $this->parameter($operation, 'get', 'messageId')['in'] ?? null);

        self::assertSame('#/components/schemas/Message', self::node('paths', $operation, 'get', 'responses', 200, 'content', 'application/json', 'schema', 'items', '$ref'));
        self::assertSame('#/components/schemas/MessagePending', self::node('paths', $operation, 'get', 'responses', 202, 'content', 'application/json', 'schema', '$ref'));
    }

    public function testAttachmentOperations(): void
    {
        self::assertSame(
            '#/components/schemas/Attachment',
            self::node('paths', '/{eDeliveryAddress}/messages/{messageId}/attachments/{attachmentId}', 'get', 'responses', 200, 'content', 'application/json', 'schema', '$ref'),
        );

        $download = '/{eDeliveryAddress}/attachments/download';
        self::assertSame('#/components/schemas/DownloadAttachment', self::node('paths', $download, 'post', 'requestBody', 'content', 'application/json', 'schema', '$ref'));
        self::assertSame('binary', self::node('paths', $download, 'post', 'responses', 200, 'content', 'application/octet-stream', 'schema', 'format'));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function fixtures(): iterable
    {
        yield 'full' => ['user-agent-v3/message.full.200.json', 'Message', true];
        yield 'fullExtended' => ['user-agent-v3/message.fullExtended.200.json', 'Message', true];
        yield 'pending' => ['user-agent-v3/message.202.json', 'MessagePending', false];
        yield 'attachment' => ['user-agent-v3/attachment.200.json', 'Attachment', false];
    }

    #[DataProvider('fixtures')]
    public function testFixturesMatchContract(string $fixture, string $schema, bool $list): void
    {
        $data = json_decode(Http::fixtureBody($fixture), true, 64, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        $this->assertValid($list ? $data : [$data], $schema);
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function fakeResponses(): iterable
    {
        $message = '{m}/messages/' . SyntheticData::MESSAGE_ID;

        yield 'open full' => ['GET', $message . '?format=full', 'Message', true];
        yield 'open fullExtended' => ['GET', $message . '?format=fullExtended', 'Message', true];
        yield 'metadata' => ['GET', $message . '?format=metadata', 'Message', true];
        yield 'minimal' => ['GET', $message . '?format=minimal', 'Message', true];
        yield 'sent message' => ['GET', '{m}/messages/' . SyntheticData::SENT_MESSAGE_ID . '?format=full', 'Message', true];
        yield 'pending' => ['GET', '{m}/messages/' . SyntheticData::PENDING_MESSAGE_ID . '?format=full', 'MessagePending', false];
        yield 'attachment' => ['GET', $message . '/attachments/ATT-1', 'Attachment', false];
        yield 'unknown message' => ['GET', '{m}/messages/M-404?format=full', 'ErrorInfo', true];
        yield 'unknown attachment' => ['GET', $message . '/attachments/ATT-404', 'ErrorInfo', true];
        yield 'download without body' => ['POST', '{m}/attachments/download', 'ErrorInfo', true];
    }

    #[DataProvider('fakeResponses')]
    public function testFakeResponsesMatchContract(string $method, string $path, string $schema, bool $list): void
    {
        $fake = (new FakeEdoreczenia(Http::factory(), Http::factory()))->acceptToken('t', self::MAILBOX);
        $response = $fake->sendRequest(
            Http::factory()->createRequest($method, self::UA . str_replace('{m}', self::MAILBOX, $path))->withHeader('Authorization', 'Bearer t'),
        );
        $data = json_decode((string) $response->getBody(), true, 64, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertNotSame([], $data);

        $this->assertValid($list ? $data : [$data], $schema);
    }

    public function testDownloadRequestBodyMatchesContract(): void
    {
        $fake = new FakeEdoreczenia(Http::factory(), Http::factory());
        $fake->createClient()->userAgent()->downloadAttachment(SyntheticData::MESSAGE_ID, 'ATT-1');

        $requests = $fake->requests();
        $last = array_pop($requests);
        self::assertNotNull($last);
        $body = Http::jsonBody($last);
        $this->assertValid([$body], 'DownloadAttachment');

        $properties = self::node('components', 'schemas', 'DownloadAttachment', 'properties');
        self::assertIsArray($properties);
        self::assertSame([], array_diff(array_keys($body), array_keys($properties)), 'only declared properties are sent');
    }

    /**
     * @param array<mixed> $items
     */
    private function assertValid(array $items, string $schema): void
    {
        $validator = new OpenApiSchemaValidator(self::spec());
        foreach ($items as $item) {
            self::assertSame([], $validator->validate($item, $schema));
        }
    }

    /**
     * @return array<mixed>
     */
    private function parameter(string $path, string $method, string $name): array
    {
        $declared = self::node('paths', $path, $method, 'parameters');
        self::assertIsArray($declared);
        foreach ($declared as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === $name) {
                return $parameter;
            }
        }

        self::fail(\sprintf('Parameter "%s" is not declared for %s %s.', $name, $method, $path));
    }

    private static function node(string|int ...$path): mixed
    {
        $node = self::spec();
        foreach ($path as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
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
