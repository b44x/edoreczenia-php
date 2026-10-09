<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Contract;

use B4x\Edoreczenia\SearchEngine\V4\Enum\AddressType;
use B4x\Edoreczenia\SearchEngine\V4\Enum\AssignmentDegree;
use B4x\Edoreczenia\SearchEngine\V4\Enum\EdaStatus;
use B4x\Edoreczenia\SearchEngine\V4\Enum\OfficialIdType;
use B4x\Edoreczenia\SearchEngine\V4\Enum\SearchCategory;
use B4x\Edoreczenia\SearchEngine\V4\Enum\WarningType;
use B4x\Edoreczenia\SearchEngine\V4\Request\AdvancedSearch;
use B4x\Edoreczenia\SearchEngine\V4\Request\PostalAddress;
use B4x\Edoreczenia\Tests\Support\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks fixtures, request payloads and enums against the official SE API 4.0.5 contract.
 *
 * Requires `composer specs:fetch`; skipped otherwise (the contract is not redistributed with the SDK).
 */
final class SearchEngineV4ContractTest extends TestCase
{
    private const SPEC = __DIR__ . '/../../build/specs/se-api_v.4.0.5.yaml';

    /** @var array<mixed>|null */
    private static ?array $spec = null;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fixtures(): iterable
    {
        yield 'bae_search' => ['search-engine-v4/bae_search.200.json', 'SearchResponse'];
        yield 'eda-confirmation' => ['search-engine-v4/eda-confirmation.200.json', 'EdaConfirmationResponse'];
        yield 'eda-search' => ['search-engine-v4/eda-search.200.json', 'EdaSearchResponses'];
        yield 'error 400' => ['search-engine-v4/error.400.json', 'SearchEngineError'];
        yield 'error 404' => ['search-engine-v4/error.404.no-results.json', 'SearchEngineError'];
        yield 'error 504' => ['search-engine-v4/error.504.json', 'SearchEngineError'];
    }

    #[DataProvider('fixtures')]
    public function testFixtureMatchesContract(string $fixture, string $schema): void
    {
        $data = json_decode(Http::fixtureBody($fixture), true, 64, \JSON_THROW_ON_ERROR);

        self::assertSame([], $this->validator()->validate($data, $schema));
    }

    public function testContractVersionIsTheOneThisSdkTargets(): void
    {
        $info = self::spec()['info'] ?? null;

        self::assertIsArray($info);
        self::assertSame('4.0.5', $info['version'] ?? null);
    }

    public function testDocumentedPathsExist(): void
    {
        $paths = self::spec()['paths'] ?? null;

        self::assertIsArray($paths);
        foreach (['/search/bae_search', '/search/eda-confirmation', '/search/eda-search'] as $path) {
            self::assertArrayHasKey($path, $paths);
            self::assertIsArray($paths[$path]);
            self::assertArrayHasKey('post', $paths[$path]);
        }
    }

    public function testRequestPayloadsMatchContract(): void
    {
        $validator = $this->validator();
        $sender = 'AE:PL-12345-67890-ABCDE-12';

        $payloads = [
            AdvancedSearch::byDeliveryAddresses('AE:PL-12345-67890-ABCDE-10')->withPagination(0, 50)->toPayload($sender),
            AdvancedSearch::inCategories(SearchCategory::Individual)
                ->withName('ADAM')->withSurname('MALINOWSKI')
                ->withPostalAddress(new PostalAddress('PL', 'WARSZAWA', 'POLNA', '12AA', '123A', '05-569', [AddressType::Correspondence]))
                ->toPayload($sender),
            AdvancedSearch::inCategories(SearchCategory::PublicInstitution, SearchCategory::Company, SearchCategory::Organisation)
                ->withOfficialId(OfficialIdType::Nip, '5232958825')->withOfficialId(OfficialIdType::Regon, '123456785')
                ->withEntityName('MINISTERSTWO')->onlySubordinateUnits()
                ->toPayload($sender),
        ];

        foreach ($payloads as $payload) {
            self::assertSame([], $validator->validate($payload, 'BaeSearch'));
        }

        self::assertSame([], $validator->validate(['recipientEdas' => [$sender . '0'], 'senderEda' => $sender, 'offset' => 0, 'limit' => 1000], 'EdaSearch'));
        self::assertSame([], $validator->validate(['recipientEda' => $sender, 'senderEda' => $sender], 'EdaConfirmation'));
    }

    /**
     * Fails when the publisher adds or removes enum values, so the SDK enums are reviewed.
     *
     * @return iterable<string, array{class-string<\BackedEnum>, string, list<string>}>
     */
    public static function enums(): iterable
    {
        yield 'SearchCategoryEnum' => [SearchCategory::class, 'SearchCategoryEnum', []];
        yield 'EdaStatusEnum' => [EdaStatus::class, 'EdaStatusEnum', ['RESERVED']];
        yield 'AssignmentDegreeEnum' => [AssignmentDegree::class, 'AssignmentDegreeEnum', []];
        yield 'ReferenceRegistryEnum' => [OfficialIdType::class, 'ReferenceRegistryEnum', []];
        yield 'AddressTypeEnum' => [AddressType::class, 'AddressTypeEnum', []];
    }

    /**
     * @param class-string<\BackedEnum> $enum
     * @param list<string>              $documentedOutsideContract
     */
    #[DataProvider('enums')]
    public function testEnumsAreInSyncWithContract(string $enum, string $schema, array $documentedOutsideContract): void
    {
        $node = self::node('components', 'schemas', $schema, 'enum');
        self::assertIsArray($node);

        $contract = array_map(static fn(mixed $v): string => \is_scalar($v) ? (string) $v : '', $node);
        $sdk = array_map(static fn(\BackedEnum $case): string => (string) $case->value, $enum::cases());

        sort($contract);
        $expected = array_values(array_diff($sdk, $documentedOutsideContract));
        sort($expected);

        self::assertSame($contract, $expected);
    }

    public function testWarningTypeEnumMatchesInlineContractEnum(): void
    {
        $node = self::node('components', 'schemas', 'Warning', 'properties', 'type', 'enum');
        self::assertIsArray($node);

        self::assertEqualsCanonicalizing($node, array_map(static fn(WarningType $t): string => $t->value, WarningType::cases()));
    }

    private static function node(string ...$path): mixed
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

    private function validator(): OpenApiSchemaValidator
    {
        return new OpenApiSchemaValidator(self::spec());
    }

    /**
     * @return array<mixed>
     */
    private static function spec(): array
    {
        if (self::$spec === null) {
            if (!is_file(self::SPEC)) {
                self::markTestSkipped('Official SE API contract not available; run "composer specs:fetch".');
            }
            $parsed = Yaml::parseFile(self::SPEC);
            self::assertIsArray($parsed);
            self::$spec = $parsed;
        }

        return self::$spec;
    }
}
