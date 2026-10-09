<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Contract;

use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\UserAgent\V3\Enum\DirectoryType;
use B4x\Edoreczenia\UserAgent\V3\Enum\ListFormat;
use B4x\Edoreczenia\UserAgent\V3\Enum\MessageStatus;
use B4x\Edoreczenia\UserAgent\V3\Enum\MessageType;
use B4x\Edoreczenia\UserAgent\V3\Enum\ShippingService;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortColumn;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortDirection;
use B4x\Edoreczenia\UserAgent\V3\Request\MessageQuery;
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
        foreach (['/dictionary/countries', '/{eDeliveryAddress}/directories', '/{eDeliveryAddress}/numberMessages', '/{eDeliveryAddress}/settings', '/{eDeliveryAddress}/messages'] as $path) {
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
        $parameters = [];
        $declared = self::node('paths', '/{eDeliveryAddress}/messages', 'get', 'parameters');
        self::assertIsArray($declared);
        foreach ($declared as $parameter) {
            self::assertIsArray($parameter);
            if (($parameter['in'] ?? null) === 'query' && \is_string($parameter['name'] ?? null) && \is_array($parameter['schema'] ?? null)) {
                $parameters[$parameter['name']] = $parameter['schema'];
            }
        }

        $query = MessageQuery::create()
            ->withFormat(ListFormat::Metadata)->inFolder('INBOX')->withPagination(0, 2000)
            ->fromSender('A')->toRecipient('B')->withSubject('C')->onlyOpened()->withAttachments()
            ->withShippingService(ShippingService::Electronic)
            ->submittedBetween(new \DateTimeImmutable(), new \DateTimeImmutable())
            ->eventBetween(new \DateTimeImmutable(), new \DateTimeImmutable())
            ->receivedBetween(new \DateTimeImmutable(), new \DateTimeImmutable())
            ->sortBy(SortColumn::ReceiptDate)->groupMassMessages()->forMassMessage('m')
            ->toQuery();

        foreach ($query as $name => $value) {
            self::assertArrayHasKey($name, $parameters, \sprintf('Query parameter "%s" is not declared in the contract.', $name));
            $schema = $parameters[$name];
            if (isset($schema['enum']) && \is_array($schema['enum'])) {
                self::assertContains($value, $schema['enum'], $name);
            }
            if (isset($schema['maximum']) && \is_int($value)) {
                self::assertLessThanOrEqual($schema['maximum'], $value, $name);
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
     * @return iterable<string, array{class-string<\BackedEnum>, string}>
     */
    public static function parameterEnums(): iterable
    {
        yield 'format' => [ListFormat::class, 'format'];
        yield 'sortColumn' => [SortColumn::class, 'sortColumn'];
        yield 'sortDirection' => [SortDirection::class, 'sortDirection'];
        yield 'shippingService' => [ShippingService::class, 'shippingService'];
    }

    /**
     * @param class-string<\BackedEnum> $enum
     */
    #[DataProvider('parameterEnums')]
    public function testParameterEnumsAreInSyncWithContract(string $enum, string $parameter): void
    {
        $declared = self::node('paths', '/{eDeliveryAddress}/messages', 'get', 'parameters');
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
