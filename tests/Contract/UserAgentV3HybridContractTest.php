<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Contract;

use B4x\Edoreczenia\Testing\FakeEdoreczenia;
use B4x\Edoreczenia\Testing\SyntheticData;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\UserAgent\V3\Enum\CategoryService;
use B4x\Edoreczenia\UserAgent\V3\Enum\FormatService;
use B4x\Edoreczenia\UserAgent\V3\Enum\OverprintMode;
use B4x\Edoreczenia\UserAgent\V3\Enum\PrintingMode;
use B4x\Edoreczenia\UserAgent\V3\Enum\ProceedingType;
use B4x\Edoreczenia\UserAgent\V3\Enum\ReturnService;
use B4x\Edoreczenia\UserAgent\V3\Enum\ShippingMode;
use B4x\Edoreczenia\UserAgent\V3\Enum\ShippingService;
use B4x\Edoreczenia\UserAgent\V3\Request\HybridShipment;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingAttachment;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingMessage;
use B4x\Edoreczenia\UserAgent\V3\Request\PaperAddress;
use B4x\Edoreczenia\UserAgent\V3\Request\PaperRecipient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks the hybrid (paper, PUH) request bodies the SDK builds, its enums and limits, and the testing kit
 * against the official UA API 3.0.8 contract.
 *
 * Requires `composer specs:fetch`; skipped otherwise.
 */
final class UserAgentV3HybridContractTest extends TestCase
{
    private const SPEC = __DIR__ . '/../../build/specs/uaapi_3.0.8.2.yaml';
    private const MAILBOX = 'AE:PL-12345-67890-ABCDE-12';
    private const UA = 'https://uaapi-int-ow.poczta-polska.pl/api/v3/';

    /** @var array<mixed>|null */
    private static ?array $spec = null;

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function enums(): iterable
    {
        yield 'procedingType' => ['procedingType', array_map(static fn(ProceedingType $c): string => $c->value, ProceedingType::cases())];
        yield 'categoryService' => ['categoryService', array_map(static fn(CategoryService $c): string => $c->value, CategoryService::cases())];
        yield 'formatService' => ['formatService', array_map(static fn(FormatService $c): string => $c->value, FormatService::cases())];
        yield 'printingMode' => ['printingMode', array_map(static fn(PrintingMode $c): string => $c->value, PrintingMode::cases())];
        yield 'overprintMode' => ['overprintMode', array_map(static fn(OverprintMode $c): string => $c->value, OverprintMode::cases())];
        yield 'returnService' => ['returnService', array_map(static fn(ReturnService $c): string => $c->value, ReturnService::cases())];
        yield 'shippingMode' => ['shippingMode', array_map(static fn(ShippingMode $c): string => $c->value, ShippingMode::cases())];
    }

    /**
     * @param list<string> $values
     */
    #[DataProvider('enums')]
    public function testEnumsAreInSyncWithContract(string $property, array $values): void
    {
        self::assertEqualsCanonicalizing(self::node('components', 'schemas', 'HybridShipment', 'properties', $property, 'enum'), $values);
    }

    public function testShippingServiceIsInSyncWithMessageMetadata(): void
    {
        self::assertEqualsCanonicalizing(
            self::node('components', 'schemas', 'MessageMetadata', 'properties', 'shippingService', 'enum'),
            array_map(static fn(ShippingService $s): string => $s->value, ShippingService::cases()),
        );
        self::assertSame('#/components/schemas/HybridShipment', self::node('components', 'schemas', 'MessageMetadata', 'properties', 'hybridShipment', '$ref'));
    }

    /**
     * Every field the contract requires is a parameter of {@see HybridShipment::create()}, so a minimal shipment has exactly them.
     */
    public function testMinimalShipmentHasExactlyTheRequiredFields(): void
    {
        $required = self::node('components', 'schemas', 'HybridShipment', 'required');
        self::assertIsArray($required);

        self::assertEqualsCanonicalizing($required, array_keys(self::shipment()->toPayload()));
    }

    public function testAddressRequiredFieldsAreTheFactoryParameters(): void
    {
        $required = self::node('components', 'schemas', 'Address', 'required');
        self::assertIsArray($required);

        self::assertEqualsCanonicalizing($required, array_keys(PaperAddress::create('PL', '05-569', 'Warszawa', 'Polna')->toPayload()));
    }

    public function testLimitsEqualContract(): void
    {
        self::assertSame(HybridShipment::MAX_SENDERS_INFORMATION_LENGTH, self::node('components', 'schemas', 'HybridShipment', 'properties', 'sendersInformation', 'maxLength'));
        self::assertSame(HybridShipment::MAX_CASE_CONTENT_LENGTH, self::node('components', 'schemas', 'HybridShipment', 'properties', 'caseContent', 'maxLength'));

        // The contributor limits for hybrid shipments are given in the descriptions only.
        $companyName = self::node('components', 'schemas', 'Contributor', 'properties', 'companyName', 'description');
        $continued = self::node('components', 'schemas', 'Contributor', 'properties', 'companyNameContinued', 'description');
        self::assertIsString($companyName);
        self::assertIsString($continued);
        self::assertStringContainsString('PUH - max ' . PaperRecipient::MAX_COMPANY_NAME_LENGTH . ' znaków', $companyName);
        self::assertStringContainsString('PUH - max ' . PaperRecipient::MAX_COMPANY_NAME_CONTINUED_LENGTH . ' znaków', $continued);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function requestBodies(): iterable
    {
        $address = PaperAddress::create('PL', '05-569', 'Warszawa', 'Polna');
        $full = HybridShipment::create(ShippingMode::Registered, CategoryService::Priority, ProceedingType::CourtCriminal, ReturnService::Destroy, PrintingMode::Duplex, OverprintMode::Color)
            ->withSendersInformation(str_repeat('ś', HybridShipment::MAX_SENDERS_INFORMATION_LENGTH))
            ->withFormat(FormatService::XS)
            ->withProofOfDelivery()
            ->withPosteRestante()
            ->withCaseContent(str_repeat('ą', HybridShipment::MAX_CASE_CONTENT_LENGTH));

        yield 'minimal hybrid message' => [OutgoingMessage::create('S')->withPaperRecipients(PaperRecipient::at($address))->withHybridShipment(self::shipment())->toPayload(self::MAILBOX)];
        yield 'hybrid draft without recipients' => [OutgoingMessage::create('S')->withHybridShipment(self::shipment())->toPayload(self::MAILBOX)];
        yield 'full hybrid message' => [OutgoingMessage::create('Decyzja')
            ->withRecipients(SyntheticData::ACTIVE_RECIPIENT)
            ->withPaperRecipients(
                PaperRecipient::at($address->withBuildingNumber('12A')->withHouseNumber('4'))->withPerson('Adam', 'Malinowski'),
                PaperRecipient::at($address)->withCompany(str_repeat('F', PaperRecipient::MAX_COMPANY_NAME_LENGTH), str_repeat('c', PaperRecipient::MAX_COMPANY_NAME_CONTINUED_LENGTH)),
            )
            ->withHybridShipment($full)
            ->withTextBody('Treść')
            ->inThread('T-1')
            ->referringTo(SyntheticData::MESSAGE_ID)
            ->withCaseId('ZNAK.1.2026')
            ->withAdditionalData('k', 'v')
            ->withAttachment(OutgoingAttachment::fromString('%PDF-1.4 synthetic', 'decyzja.pdf', 'application/pdf'))
            ->toPayload(self::MAILBOX)];
    }

    /**
     * Valid against the "Message" schema, and every property the SDK sends is declared by it.
     *
     * @param array<mixed> $body
     */
    #[DataProvider('requestBodies')]
    public function testRequestBodiesMatchContract(array $body): void
    {
        $decoded = json_decode(json_encode($body, \JSON_THROW_ON_ERROR), true, 64, \JSON_THROW_ON_ERROR);

        self::assertSame([], (new OpenApiSchemaValidator(self::spec()))->validate($decoded, 'Message'));
        self::assertSame([], $this->undeclared($decoded, ['$ref' => '#/components/schemas/Message'], '$'));
    }

    public function testFakeTaskForAHybridMessageMatchesContract(): void
    {
        $fake = new FakeEdoreczenia(Http::factory(), Http::factory());
        $task = $fake->createClient()->userAgent()->sendMessage(OutgoingMessage::create('S')
            ->withPaperRecipients(PaperRecipient::at(PaperAddress::create('PL', '05-569', 'Warszawa', 'Polna'))->withPerson('Adam', 'Malinowski'))
            ->withHybridShipment(self::shipment()))->messageTaskId;
        $fake->acceptToken('t', self::MAILBOX);

        $response = $fake->sendRequest(Http::factory()->createRequest('GET', self::UA . self::MAILBOX . '/messages/tasks/' . $task)->withHeader('Authorization', 'Bearer t'));
        $items = json_decode((string) $response->getBody(), true, 64, \JSON_THROW_ON_ERROR);
        self::assertIsArray($items);
        self::assertCount(1, $items);
        $validator = new OpenApiSchemaValidator(self::spec());
        foreach ($items as $item) {
            // "addresseeAde" and "status" are required by the contract without being defined (see the sending contract test).
            $violations = array_diff($validator->validate($item, 'MessageOperationResponseWrapperStatus'), ['$: missing required "addresseeAde"', '$: missing required "status"']);
            self::assertSame([], array_values($violations));
        }
    }

    private static function shipment(): HybridShipment
    {
        return HybridShipment::create(ShippingMode::NotRegistered, CategoryService::Economic, ProceedingType::General, ReturnService::ReturnToSender, PrintingMode::Simplex, OverprintMode::Monochrome);
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

    private static function node(string|int ...$path): mixed
    {
        $current = self::spec();
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
