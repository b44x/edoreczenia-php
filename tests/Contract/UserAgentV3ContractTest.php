<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Contract;

use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\UserAgent\V3\Enum\DirectoryType;
use B4x\Edoreczenia\UserAgent\V3\Enum\EvidenceService;
use B4x\Edoreczenia\UserAgent\V3\Enum\EvidenceType;
use B4x\Edoreczenia\UserAgent\V3\Enum\ListFormat;
use B4x\Edoreczenia\UserAgent\V3\Enum\MessageStatus;
use B4x\Edoreczenia\UserAgent\V3\Enum\MessageType;
use B4x\Edoreczenia\UserAgent\V3\Enum\ShippingService;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortColumn;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortDirection;
use B4x\Edoreczenia\UserAgent\V3\Enum\ThreadMessageSortColumn;
use B4x\Edoreczenia\UserAgent\V3\Enum\ThreadSortColumn;
use B4x\Edoreczenia\UserAgent\V3\Request\EvidenceQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\MessageQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\ThreadMessageQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\ThreadQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks UA API v3 fixtures, query parameters and enums against the official 3.0.8 contract.
 *
 * Requires `composer specs:fetch`; skipped otherwise.
 */
final class UserAgentV3ContractTest extends TestCase
{
    private const SPEC = __DIR__ . '/../../build/specs/uaapi_3.0.8.2.yaml';

    /** @var array<mixed>|null */
    private static ?array $spec = null;

    public function testContractVersion(): void
    {
        self::assertSame('3.0.8', self::node('info', 'version'));
    }

    public function testUsedPathsExistAsGetOperations(): void
    {
        foreach (['/dictionary/countries', '/{eDeliveryAddress}/directories', '/{eDeliveryAddress}/numberMessages', '/{eDeliveryAddress}/settings', '/{eDeliveryAddress}/messages', '/{eDeliveryAddress}/threads', '/{eDeliveryAddress}/threads/{threadId}', '/{eDeliveryAddress}/messages/{messageId}/evidences', '/{eDeliveryAddress}/messages/evidences', '/{eDeliveryAddress}/messages/evidences/{evidenceId}', '/{eDeliveryAddress}/evidences/{messageType}/{evidenceId}', '/{eDeliveryAddress}/evidences/puhepo/{messageId}', '/{eDeliveryAddress}/evidences/{messageId}/technical-evidences-file'] as $path) {
            self::assertIsArray(self::node('paths', $path, 'get'), $path);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function listFixtures(): iterable
    {
        yield 'directories' => ['user-agent-v3/directories.200.json', 'Directory'];
        yield 'numberMessages' => ['user-agent-v3/numberMessages.200.json', 'DirectoryExtended'];
        yield 'error 400' => ['user-agent-v3/error.400.json', 'ErrorInfo'];
        yield 'evidence by id' => ['user-agent-v3/evidence.200.json', 'Evidence'];
    }

    #[DataProvider('listFixtures')]
    public function testListFixtureItemsMatchContract(string $fixture, string $schema): void
    {
        $items = json_decode(Http::fixtureBody($fixture), true, 64, \JSON_THROW_ON_ERROR);
        self::assertIsArray($items);
        self::assertNotSame([], $items);

        foreach ($items as $item) {
            self::assertSame([], $this->validator()->validate($item, $schema));
        }
    }

    public function testObjectFixturesMatchContract(): void
    {
        $validator = $this->validator();

        self::assertSame([], $validator->validate(json_decode(Http::fixtureBody('user-agent-v3/settings.200.json'), true, 64, \JSON_THROW_ON_ERROR), 'Settings'));
        self::assertSame([], $validator->validate(json_decode(Http::fixtureBody('user-agent-v3/messages.200.json'), true, 64, \JSON_THROW_ON_ERROR), 'MessagesWrapper'));
        self::assertSame([], $validator->validate(json_decode(Http::fixtureBody('user-agent-v3/threads.200.json'), true, 64, \JSON_THROW_ON_ERROR), 'Threads'));
        self::assertSame([], $validator->validate(json_decode(Http::fixtureBody('user-agent-v3/thread.200.json'), true, 64, \JSON_THROW_ON_ERROR), 'Thread'));
        self::assertSame([], $validator->validate(json_decode(Http::fixtureBody('user-agent-v3/evidences.200.json'), true, 64, \JSON_THROW_ON_ERROR), 'EvidenceWrapper'));
    }

    public function testCountriesFixtureIsAListOfStrings(): void
    {
        self::assertSame('string', self::node('paths', '/dictionary/countries', 'get', 'responses', 200, 'content', 'application/json', 'schema', 'items', 'type'));

        $countries = json_decode(Http::fixtureBody('user-agent-v3/countries.200.json'), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($countries);
        self::assertContainsOnlyString($countries);
    }

    public function testEveryQueryParameterExistsWithAllowedValues(): void
    {
        $query = MessageQuery::create()
            ->withFormat(ListFormat::Metadata)->inFolder('INBOX')->withPagination(0, 2000)
            ->fromSender('A')->toRecipient('B')->withSubject('C')->onlyOpened()->withAttachments()
            ->withShippingService(ShippingService::Electronic)
            ->submittedBetween(new \DateTimeImmutable(), new \DateTimeImmutable())
            ->eventBetween(new \DateTimeImmutable(), new \DateTimeImmutable())
            ->receivedBetween(new \DateTimeImmutable(), new \DateTimeImmutable())
            ->sortBy(SortColumn::ReceiptDate)->groupMassMessages()->forMassMessage('m')
            ->toQuery();
        $this->assertQueryMatchesOperation('/{eDeliveryAddress}/messages', $query);

        $threadFilters = static fn(ThreadQuery|ThreadMessageQuery $q): ThreadQuery|ThreadMessageQuery => $q
            ->fromSender('A')->toRecipient('B')->withCaseId('C')->withMessageTitle('D')
            ->submittedBetween(new \DateTimeImmutable(), new \DateTimeImmutable())
            ->eventBetween(new \DateTimeImmutable(), new \DateTimeImmutable());

        $this->assertQueryMatchesOperation('/{eDeliveryAddress}/threads', $threadFilters(ThreadQuery::create()->withPagination(0, ThreadQuery::MAX_LIMIT)->sortBy(ThreadSortColumn::FirstMessageTitle))->toQuery());
        $evidenceQuery = EvidenceQuery::create()->ofType(EvidenceType::E1)->downloaded(true)->sortByEventDate()->toQuery();
        $this->assertQueryMatchesOperation('/{eDeliveryAddress}/messages/{messageId}/evidences', $evidenceQuery);
        $this->assertQueryMatchesOperation('/{eDeliveryAddress}/messages/evidences', $evidenceQuery);
        $this->assertQueryMatchesOperation('/{eDeliveryAddress}/evidences/{messageType}/{evidenceId}', ['evidenceFormat' => 'PDF']);
        $this->assertQueryMatchesOperation('/{eDeliveryAddress}/evidences/puhepo/{messageId}', ['evidenceFormat' => 'PDF']);

        $this->assertQueryMatchesOperation('/{eDeliveryAddress}/threads/{threadId}', $threadFilters(ThreadMessageQuery::create()->withPagination(0, ThreadMessageQuery::MAX_LIMIT)->sortBy(ThreadMessageSortColumn::CaseId))->toQuery());
    }

    /**
     * @param array<string, string|int|bool> $query
     */
    private function assertQueryMatchesOperation(string $path, array $query): void
    {
        $parameters = [];
        $declared = self::node('paths', $path, 'get', 'parameters');
        self::assertIsArray($declared);
        foreach ($declared as $parameter) {
            self::assertIsArray($parameter);
            if (($parameter['in'] ?? null) === 'query' && \is_string($parameter['name'] ?? null) && \is_array($parameter['schema'] ?? null)) {
                $parameters[$parameter['name']] = $parameter['schema'];
            }
        }

        foreach ($query as $name => $value) {
            self::assertArrayHasKey($name, $parameters, \sprintf('Query parameter "%s" is not declared for %s.', $name, $path));
            $schema = $parameters[$name];
            if (isset($schema['enum']) && \is_array($schema['enum'])) {
                self::assertContains($value, $schema['enum'], $name);
            }
            if (isset($schema['maximum']) && \is_int($value)) {
                self::assertSame($schema['maximum'], $value, $name . ': SDK page-size limit must equal the contract maximum');
            }
        }
    }

    /**
     * @return iterable<string, array{class-string<\BackedEnum>, list<string|int>}>
     */
    public static function enums(): iterable
    {
        yield 'status' => [MessageStatus::class, ['components', 'schemas', 'MessageControlData', 'properties', 'status', 'enum']];
        yield 'messageType' => [MessageType::class, ['components', 'schemas', 'MessageControlData', 'properties', 'messageType', 'enum']];
        yield 'shippingService' => [ShippingService::class, ['components', 'schemas', 'MessageMetadata', 'properties', 'shippingService', 'enum']];
        yield 'directory type' => [DirectoryType::class, ['components', 'schemas', 'Directory', 'properties', 'type', 'enum']];
    }

    /**
     * @param class-string<\BackedEnum> $enum
     * @param list<string|int>          $path
     */
    #[DataProvider('enums')]
    public function testEnumsAreInSyncWithContract(string $enum, array $path): void
    {
        $values = self::node(...$path);
        self::assertIsArray($values);

        self::assertEqualsCanonicalizing($values, array_map(static fn(\BackedEnum $case): string|int => $case->value, $enum::cases()));
    }

    /**
     * @return iterable<string, array{class-string<\BackedEnum>, string, string}>
     */
    public static function parameterEnums(): iterable
    {
        yield 'messages format' => [ListFormat::class, 'format', '/{eDeliveryAddress}/messages'];
        yield 'messages sortColumn' => [SortColumn::class, 'sortColumn', '/{eDeliveryAddress}/messages'];
        yield 'messages sortDirection' => [SortDirection::class, 'sortDirection', '/{eDeliveryAddress}/messages'];
        yield 'messages shippingService' => [ShippingService::class, 'shippingService', '/{eDeliveryAddress}/messages'];
        yield 'threads sortColumn' => [ThreadSortColumn::class, 'sortColumn', '/{eDeliveryAddress}/threads'];
        yield 'threads sortDirection' => [SortDirection::class, 'sortDirection', '/{eDeliveryAddress}/threads'];
        yield 'thread sortColumn' => [ThreadMessageSortColumn::class, 'sortColumn', '/{eDeliveryAddress}/threads/{threadId}'];
        yield 'thread sortDirection' => [SortDirection::class, 'sortDirection', '/{eDeliveryAddress}/threads/{threadId}'];
    }

    /**
     * @param class-string<\BackedEnum> $enum
     */
    #[DataProvider('parameterEnums')]
    public function testParameterEnumsAreInSyncWithContract(string $enum, string $parameter, string $path): void
    {
        $declared = self::node('paths', $path, 'get', 'parameters');
        self::assertIsArray($declared);
        $values = null;
        foreach ($declared as $candidate) {
            if (\is_array($candidate) && ($candidate['name'] ?? null) === $parameter && \is_array($candidate['schema'] ?? null)) {
                $values = $candidate['schema']['enum'] ?? null;
            }
        }
        self::assertIsArray($values);

        self::assertEqualsCanonicalizing($values, array_map(static fn(\BackedEnum $case): string|int => $case->value, $enum::cases()));
    }

    /**
     * Every contract evidence code is either mapped (dotted or undotted spelling) or a documented unknown,
     * and every SDK case exists in the contract.
     */
    public function testEvidenceTypesCoverTheContract(): void
    {
        $contract = self::node('components', 'schemas', 'Evidence', 'properties', 'type', 'enum');
        self::assertIsArray($contract);
        $values = array_map(static fn(mixed $v): string => \is_scalar($v) ? trim((string) $v) : '', $contract);

        $unmapped = array_values(array_filter($values, static fn(string $v): bool => EvidenceType::fromWire($v) === null));
        sort($unmapped);
        self::assertSame(['C2', 'D'], $unmapped, 'codes not mapped by EvidenceType (undocumented in the technical design)');

        foreach (EvidenceType::cases() as $case) {
            self::assertContains($case->value, $values);
        }
    }

    public function testEvidenceServiceMatchesPathEnum(): void
    {
        $declared = self::node('paths', '/{eDeliveryAddress}/evidences/{messageType}/{evidenceId}', 'get', 'parameters');
        self::assertIsArray($declared);
        $values = null;
        foreach ($declared as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === 'messageType' && \is_array($parameter['schema'] ?? null)) {
                $values = $parameter['schema']['enum'] ?? null;
            }
        }
        self::assertIsArray($values);

        self::assertEqualsCanonicalizing($values, array_map(static fn(EvidenceService $s): string => $s->value, EvidenceService::cases()));
    }

    private function validator(): OpenApiSchemaValidator
    {
        return new OpenApiSchemaValidator(self::spec());
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
