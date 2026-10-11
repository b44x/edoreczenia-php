<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\Testing;

use B4x\Edoreczenia\Testing\FakeEdoreczenia;
use B4x\Edoreczenia\Testing\SyntheticData;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\UserAgent\V3\Enum\CategoryService;
use B4x\Edoreczenia\UserAgent\V3\Enum\OverprintMode;
use B4x\Edoreczenia\UserAgent\V3\Enum\PrintingMode;
use B4x\Edoreczenia\UserAgent\V3\Enum\ProceedingType;
use B4x\Edoreczenia\UserAgent\V3\Enum\ReturnService;
use B4x\Edoreczenia\UserAgent\V3\Enum\ShippingMode;
use B4x\Edoreczenia\UserAgent\V3\Request\HybridShipment;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingMessage;
use B4x\Edoreczenia\UserAgent\V3\Request\PaperAddress;
use B4x\Edoreczenia\UserAgent\V3\Request\PaperRecipient;
use B4x\Edoreczenia\UserAgent\V3\UserAgentClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class FakeEdoreczeniaHybridTest extends TestCase
{
    private const UA = 'https://uaapi-int-ow.poczta-polska.pl/api/v3/';
    private const SHIPMENT = '{"procedingType":"general","categoryService":"economic","printingMode":"simplex","overprintMode":"monochrome","returnService":"returntosender","shippingMode":"notRegistered"}';

    private FakeEdoreczenia $fake;
    private UserAgentClient $ua;

    protected function setUp(): void
    {
        $this->fake = new FakeEdoreczenia(Http::factory(), Http::factory());
        $this->ua = $this->fake->createClient()->userAgent();
    }

    public function testHybridMessageIsAcceptedPerRecipient(): void
    {
        $message = OutgoingMessage::create('Decyzja')
            ->withRecipients(SyntheticData::ACTIVE_RECIPIENT, SyntheticData::INACTIVE_RECIPIENT)
            ->withPaperRecipients(PaperRecipient::at(PaperAddress::create('PL', '05-569', 'Warszawa', 'Polna')->withBuildingNumber('12A'))->withPerson('Adam', 'Malinowski'))
            ->withHybridShipment(self::shipment())
            ->withTextBody('Treść');

        $task = $this->ua->sendMessage($message);

        self::assertNotNull($task->messageTaskId);
        $items = $this->ua->messageTask($task->messageTaskId);
        self::assertCount(3, $items);
        self::assertTrue($items[0]->isAccepted());
        self::assertFalse($items[1]->isAccepted());
        self::assertTrue($items[2]->isAccepted());
        self::assertNull($items[2]->recipientAddress);
        self::assertSame('Malinowski', $items[2]->recipient?->lastName);

        self::assertSame([$message->toPayload(SyntheticData::MAILBOX)], $this->fake->sentMessages());
    }

    public function testIncompletePostalAddressGetsAPerRecipientError(): void
    {
        $body = '{"messageMetadata":{"shippingService":"hybrid","hybridShipment":' . self::SHIPMENT . ',"to":[{"address":{"country":"PL","city":"Warszawa"}}]}}';

        $response = $this->call('POST', 'messages', $body);
        self::assertSame(202, $response->getStatusCode());
        $task = json_decode((string) $response->getBody(), true, 64, \JSON_THROW_ON_ERROR);
        self::assertIsArray($task);
        self::assertIsString($task['messageTaskId'] ?? null);

        $items = $this->ua->messageTask($task['messageTaskId']);
        self::assertCount(1, $items);
        self::assertFalse($items[0]->isAccepted());
        self::assertSame('INVALID_RECIPIENT', $items[0]->error);
    }

    public function testHybridMessagesWithoutRequiredShipmentDataAreRejected(): void
    {
        $to = '"to":[{"address":{"country":"PL","postalCode":"05-569","city":"Warszawa","street":"Polna"}}]';
        foreach ([
            '{"messageMetadata":{"shippingService":"hybrid",' . $to . '}}',
            '{"messageMetadata":{"shippingService":"hybrid","hybridShipment":"x",' . $to . '}}',
            '{"messageMetadata":{"shippingService":"hybrid","hybridShipment":{"procedingType":"general"},' . $to . '}}',
            '{"messageMetadata":{"shippingService":"paper","hybridShipment":' . self::SHIPMENT . ',' . $to . '}}',
        ] as $body) {
            self::assertSame(400, $this->call('POST', 'messages', $body)->getStatusCode(), $body);
        }
        self::assertSame([], $this->fake->sentMessages());
    }

    public function testElectronicMessagesStillNeedAnElectronicAddress(): void
    {
        $body = '{"messageMetadata":{"shippingService":"electronic","to":[{"address":{"country":"PL","postalCode":"05-569","city":"Warszawa","street":"Polna"}}]}}';

        $task = json_decode((string) $this->call('POST', 'messages', $body)->getBody(), true, 64, \JSON_THROW_ON_ERROR);
        self::assertIsArray($task);
        self::assertIsString($task['messageTaskId'] ?? null);

        self::assertFalse($this->ua->messageTask($task['messageTaskId'])[0]->isAccepted());
    }

    public function testHybridDraftIsStoredAndSent(): void
    {
        $id = $this->ua->createDraft(OutgoingMessage::create('Szkic')->withHybridShipment(self::shipment()))->messageId;
        $this->ua->updateDraft($id, OutgoingMessage::create('Szkic')
            ->withPaperRecipients(PaperRecipient::at(PaperAddress::create('PL', '05-569', 'Warszawa', 'Polna'))->withCompany('Firma'))
            ->withHybridShipment(self::shipment()));

        $task = $this->ua->sendDraft($id);

        self::assertNotNull($task->messageTaskId);
        self::assertTrue($this->ua->messageTask($task->messageTaskId)[0]->isAccepted());
        $sent = $this->fake->sentMessages();
        self::assertCount(1, $sent);
        self::assertIsArray($sent[0]['messageMetadata'] ?? null);
        self::assertSame('hybrid', $sent[0]['messageMetadata']['shippingService'] ?? null);
        self::assertSame(self::shipment()->toPayload(), $sent[0]['messageMetadata']['hybridShipment'] ?? null);
    }

    private static function shipment(): HybridShipment
    {
        return HybridShipment::create(ShippingMode::NotRegistered, CategoryService::Economic, ProceedingType::General, ReturnService::ReturnToSender, PrintingMode::Simplex, OverprintMode::Monochrome);
    }

    private function call(string $method, string $path, string $body): ResponseInterface
    {
        $this->fake->acceptToken('t', SyntheticData::MAILBOX);

        return $this->fake->sendRequest(
            Http::factory()->createRequest($method, self::UA . SyntheticData::MAILBOX . '/' . $path)
                ->withHeader('Authorization', 'Bearer t')
                ->withHeader('Content-Type', 'application/json')
                ->withBody(Http::factory()->createStream($body)),
        );
    }
}
