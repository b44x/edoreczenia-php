<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\UserAgent\V3;

use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\Tests\Support\MockHttpClient;
use B4x\Edoreczenia\Tests\Support\SpyTokenProvider;
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

final class HybridShipmentTest extends TestCase
{
    private const SENDER = 'AE:PL-12345-67890-ABCDE-12';
    private const RECIPIENT = 'AE:PL-98765-43210-SFVYC-19';
    private const BASE = 'https://uaapi-int-ow.poczta-polska.pl/api/v3/';

    public function testMinimalShipmentHasOnlyRequiredFields(): void
    {
        self::assertSame([
            'procedingType' => 'general',
            'categoryService' => 'economic',
            'printingMode' => 'simplex',
            'overprintMode' => 'monochrome',
            'returnService' => 'returntosender',
            'shippingMode' => 'notRegistered',
        ], self::shipment()->toPayload());
    }

    public function testFullShipment(): void
    {
        $shipment = HybridShipment::create(ShippingMode::Registered, CategoryService::Priority, ProceedingType::Administrative, ReturnService::Destroy, PrintingMode::Duplex, OverprintMode::Color)
            ->withSendersInformation(str_repeat('Ż', HybridShipment::MAX_SENDERS_INFORMATION_LENGTH))
            ->withFormat(FormatService::M)
            ->withProofOfDelivery()
            ->withPosteRestante(false)
            ->withCaseContent('Decyzja w sprawie ZNAK.1.2026');

        self::assertSame([
            'procedingType' => 'administrative',
            'sendersInformation' => str_repeat('Ż', 30),
            'categoryService' => 'priority',
            'formatService' => 'M',
            'printingMode' => 'duplex',
            'overprintMode' => 'color',
            'returnService' => 'destroy',
            'proofOfDelivery' => true,
            'posteRestante' => false,
            'caseContent' => 'Decyzja w sprawie ZNAK.1.2026',
            'shippingMode' => 'registered',
        ], $shipment->toPayload());
        self::assertSame(ShippingMode::Registered, $shipment->shippingMode());
        self::assertSame(CategoryService::Priority, $shipment->category());
        self::assertSame(ProceedingType::Administrative, $shipment->proceedingType());
        self::assertSame(ReturnService::Destroy, $shipment->returnService());
        self::assertSame(PrintingMode::Duplex, $shipment->printingMode());
        self::assertSame(OverprintMode::Color, $shipment->overprintMode());
        self::assertSame(FormatService::M, $shipment->format());
        self::assertTrue($shipment->proofOfDelivery());
        self::assertFalse($shipment->posteRestante());
        self::assertSame('Decyzja w sprawie ZNAK.1.2026', $shipment->caseContent());
        self::assertSame(str_repeat('Ż', 30), $shipment->sendersInformation());
    }

    public function testBuildersAreImmutable(): void
    {
        $shipment = self::shipment();
        $shipment->withFormat(FormatService::XS)->withSendersInformation('Urząd');
        self::assertNull($shipment->format());
        self::assertNull($shipment->sendersInformation());

        $address = self::address();
        $address->withHouseNumber('4');
        self::assertNull($address->houseNumber());

        $recipient = PaperRecipient::at($address);
        $recipient->withPerson('Adam', 'Malinowski');
        self::assertNull($recipient->firstName());

        $message = OutgoingMessage::create('Pismo');
        $message->withHybridShipment($shipment)->withPaperRecipients($recipient);
        self::assertNull($message->hybridShipment());
        self::assertSame([], $message->paperRecipients());
        self::assertSame(ShippingService::Electronic, $message->shippingService());
    }

    /**
     * @return iterable<string, array{callable(): mixed, string}>
     */
    public static function invalidShipments(): iterable
    {
        yield 'senders information too long' => [static fn() => self::shipment()->withSendersInformation(str_repeat('a', 31)), 'sendersInformation must be at most 30'];
        yield 'blank senders information' => [static fn() => self::shipment()->withSendersInformation(' '), 'sendersInformation must not be empty'];
        yield 'case content for an ordinary letter' => [static fn() => self::shipment(ShippingMode::NotRegistered, ProceedingType::Tax)->withCaseContent('x'), 'caseContent is only allowed'];
        yield 'case content for general proceedings' => [static fn() => self::shipment(ShippingMode::Registered, ProceedingType::General)->withCaseContent('x'), 'caseContent is only allowed'];
        yield 'case content too long' => [static fn() => self::shipment(ShippingMode::Registered, ProceedingType::CourtCivil)->withCaseContent(str_repeat('a', 256)), 'caseContent must be at most 255'];
        yield 'blank country' => [static fn() => PaperAddress::create('', '05-569', 'Warszawa', 'Polna'), 'country must not be empty'];
        yield 'blank postal code' => [static fn() => PaperAddress::create('PL', ' ', 'Warszawa', 'Polna'), 'postalCode must not be empty'];
        yield 'blank city' => [static fn() => PaperAddress::create('PL', '05-569', '', 'Polna'), 'city must not be empty'];
        yield 'blank street' => [static fn() => PaperAddress::create('PL', '05-569', 'Warszawa', "\t"), 'street must not be empty'];
        yield 'invalid UTF-8' => [static fn() => PaperAddress::create('PL', '05-569', "\xFF", 'Polna'), 'city must be valid UTF-8'];
        yield 'blank building number' => [static fn() => self::address()->withBuildingNumber(''), 'buildingNumber must not be empty'];
        yield 'blank flat number' => [static fn() => self::address()->withHouseNumber(''), 'houseNumber must not be empty'];
        yield 'blank last name' => [static fn() => PaperRecipient::at(self::address())->withPerson('Adam', ''), 'lastName must not be empty'];
        yield 'company name too long' => [static fn() => PaperRecipient::at(self::address())->withCompany(str_repeat('a', 51)), 'companyName must be at most 50'];
        yield 'company name continuation too long' => [static fn() => PaperRecipient::at(self::address())->withCompany('Firma', str_repeat('a', 101)), 'companyNameContinued must be at most 100'];
    }

    /**
     * @param callable(): mixed $build
     */
    #[DataProvider('invalidShipments')]
    public function testInvalidValuesAreRejectedLocally(callable $build, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $build();
    }

    public function testPaperRecipient(): void
    {
        $recipient = PaperRecipient::at(self::address()->withBuildingNumber('12A')->withHouseNumber('4'))
            ->withPerson('Adam', 'Malinowski')
            ->withCompany(str_repeat('F', PaperRecipient::MAX_COMPANY_NAME_LENGTH), str_repeat('c', PaperRecipient::MAX_COMPANY_NAME_CONTINUED_LENGTH));

        self::assertSame([
            'address' => ['country' => 'PL', 'street' => 'Polna', 'postalCode' => '05-569', 'city' => 'Warszawa', 'buildingNumber' => '12A', 'houseNumber' => '4'],
            'contributor' => ['firstName' => 'Adam', 'lastName' => 'Malinowski', 'companyName' => str_repeat('F', 50), 'companyNameContinued' => str_repeat('c', 100)],
        ], $recipient->toPayload());
        self::assertSame('12A', $recipient->address()->buildingNumber());
        self::assertSame('Malinowski', $recipient->lastName());
        self::assertSame(str_repeat('c', 100), $recipient->companyNameContinued());
        self::assertSame(['address' => ['country' => 'PL', 'street' => 'Polna', 'postalCode' => '05-569', 'city' => 'Warszawa']], PaperRecipient::at(self::address())->toPayload());
        self::assertSame(['companyName' => 'Firma'], PaperRecipient::at(self::address())->withCompany('Firma')->toPayload()['contributor'] ?? null);
    }

    public function testHybridMessagePayload(): void
    {
        $recipient = PaperRecipient::at(self::address()->withBuildingNumber('12A'))->withPerson('Adam', 'Malinowski');
        $message = OutgoingMessage::create('Decyzja')
            ->withRecipients(self::RECIPIENT)
            ->withPaperRecipients($recipient)
            ->withHybridShipment(self::shipment())
            ->withCaseId('ZNAK.1.2026')
            ->withAttachment(OutgoingAttachment::fromString('%PDF-1.4 synthetic', 'decyzja.pdf', 'application/pdf')->withFileId('F-1'));

        self::assertSame(ShippingService::Hybrid, $message->shippingService());
        self::assertSame([$recipient], $message->paperRecipients());
        self::assertSame([self::RECIPIENT], $message->recipients());
        $payload = $message->toPayload(self::SENDER);
        self::assertSame([
            'from' => ['eDeliveryAddress' => self::SENDER],
            'to' => [
                ['eDeliveryAddress' => self::RECIPIENT],
                ['address' => ['country' => 'PL', 'street' => 'Polna', 'postalCode' => '05-569', 'city' => 'Warszawa', 'buildingNumber' => '12A'], 'contributor' => ['firstName' => 'Adam', 'lastName' => 'Malinowski']],
            ],
            'hybridShipment' => self::shipment()->toPayload(),
            'subject' => 'Decyzja',
            'caseId' => 'ZNAK.1.2026',
            'shippingService' => 'hybrid',
        ], $payload['messageMetadata']);
        self::assertArrayHasKey('attachments', $payload);
    }

    public function testHybridDraftWithoutRecipients(): void
    {
        self::assertSame([
            'messageMetadata' => [
                'from' => ['eDeliveryAddress' => self::SENDER],
                'hybridShipment' => self::shipment()->toPayload(),
                'subject' => 'Szkic',
                'shippingService' => 'hybrid',
            ],
        ], OutgoingMessage::create('Szkic')->withHybridShipment(self::shipment())->toPayload(self::SENDER));
    }

    public function testSettingTheShipmentAgainReplacesIt(): void
    {
        $other = self::shipment()->withFormat(FormatService::L);
        $message = OutgoingMessage::create('S')->withHybridShipment(self::shipment())->withHybridShipment($other);

        self::assertSame($other, $message->hybridShipment());
    }

    public function testPaperRecipientsNeedAHybridShipment(): void
    {
        $message = OutgoingMessage::create('S')->withPaperRecipients(PaperRecipient::at(self::address()));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('need a hybrid shipment');

        $message->toPayload(self::SENDER);
    }

    public function testDuplicatePaperRecipientsAreRejected(): void
    {
        $recipient = PaperRecipient::at(self::address())->withPerson('Adam', 'Malinowski');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only once');

        OutgoingMessage::create('S')->withPaperRecipients($recipient)->withPaperRecipients(PaperRecipient::at(self::address())->withPerson('Adam', 'Malinowski'));
    }

    public function testSameAddressWithDifferentNamesIsAllowed(): void
    {
        $message = OutgoingMessage::create('S')->withPaperRecipients(
            PaperRecipient::at(self::address())->withPerson('Adam', 'Malinowski'),
            PaperRecipient::at(self::address())->withPerson('Ewa', 'Malinowska'),
        );

        self::assertCount(2, $message->paperRecipients());
    }

    public function testSendMessagePostsAHybridMessageToPaperRecipientsOnly(): void
    {
        $http = new MockHttpClient();
        $http->queue(Http::fixture(202, 'user-agent-v3/send.202.json'));
        $client = new EdoreczeniaClient(Environment::integration(), self::SENDER, new SpyTokenProvider(), $http, Http::factory(), Http::factory());

        $client->userAgent()->sendMessage(
            OutgoingMessage::create('Decyzja')->withPaperRecipients(PaperRecipient::at(self::address()))->withHybridShipment(self::shipment()),
        );

        $request = $http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE . self::SENDER . '/messages', (string) $request->getUri());
        $body = Http::jsonBody($request);
        self::assertIsArray($body['messageMetadata'] ?? null);
        self::assertSame('hybrid', $body['messageMetadata']['shippingService'] ?? null);
        self::assertSame([['address' => self::address()->toPayload()]], $body['messageMetadata']['to'] ?? null);
    }

    public function testSendMessageRejectsPaperRecipientsWithoutHybridShipmentBeforeAnyRequest(): void
    {
        $http = new MockHttpClient();
        $client = new EdoreczeniaClient(Environment::integration(), self::SENDER, new SpyTokenProvider(), $http, Http::factory(), Http::factory());

        try {
            $client->userAgent()->sendMessage(OutgoingMessage::create('S')->withPaperRecipients(PaperRecipient::at(self::address())));
            self::fail('Expected exception');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('hybrid shipment', $e->getMessage());
        }
        self::assertSame([], $http->requests);
    }

    public function testHybridMessageWithoutRecipientsIsNotSent(): void
    {
        $http = new MockHttpClient();
        $client = new EdoreczeniaClient(Environment::integration(), self::SENDER, new SpyTokenProvider(), $http, Http::factory(), Http::factory());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one recipient');

        $client->userAgent()->sendMessage(OutgoingMessage::create('S')->withHybridShipment(self::shipment()));
    }

    private static function shipment(ShippingMode $mode = ShippingMode::NotRegistered, ProceedingType $type = ProceedingType::General): HybridShipment
    {
        return HybridShipment::create($mode, CategoryService::Economic, $type, ReturnService::ReturnToSender, PrintingMode::Simplex, OverprintMode::Monochrome);
    }

    private static function address(): PaperAddress
    {
        return PaperAddress::create('PL', '05-569', 'Warszawa', 'Polna');
    }
}
