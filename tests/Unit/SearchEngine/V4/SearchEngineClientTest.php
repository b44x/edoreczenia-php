<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\SearchEngine\V4;

use B4x\Edoreczenia\Auth\PrivateKey;
use B4x\Edoreczenia\Auth\SystemCredentials;
use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\Exception\NotFoundException;
use B4x\Edoreczenia\Exception\UnexpectedResponseException;
use B4x\Edoreczenia\SearchEngine\V4\Enum\AddressType;
use B4x\Edoreczenia\SearchEngine\V4\Enum\AssignmentDegree;
use B4x\Edoreczenia\SearchEngine\V4\Enum\EdaStatus;
use B4x\Edoreczenia\SearchEngine\V4\Enum\NipStatus;
use B4x\Edoreczenia\SearchEngine\V4\Enum\OfficialIdType;
use B4x\Edoreczenia\SearchEngine\V4\Enum\SearchCategory;
use B4x\Edoreczenia\SearchEngine\V4\Enum\WarningType;
use B4x\Edoreczenia\SearchEngine\V4\ErrorCode;
use B4x\Edoreczenia\SearchEngine\V4\Request\AdvancedSearch;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\Tests\Support\MockHttpClient;
use B4x\Edoreczenia\Tests\Support\SpyTokenProvider;
use B4x\Edoreczenia\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;

final class SearchEngineClientTest extends TestCase
{
    private const SENDER = 'AE:PL-12345-67890-ABCDE-12';

    private MockHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
    }

    public function testAdvancedSearchSendsContractPayloadAndMapsResult(): void
    {
        $this->http->queue(Http::fixture(200, 'search-engine-v4/bae_search.200.json'));

        $result = $this->client()->searchEngine()->search(
            AdvancedSearch::inCategories(SearchCategory::PublicInstitution)
                ->withOfficialId(OfficialIdType::Nip, '5232958825'),
        );

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://int-ow.edoreczenia.gov.pl/api/se/v4/search/bae_search', (string) $request->getUri());
        self::assertSame([
            'recipientEdasOnly' => false,
            'senderEda' => self::SENDER,
            'searchCategory' => ['PUBLIC_INSTITUTION'],
            'officialIds' => [['id' => '5232958825', 'referenceRegistry' => 'nip']],
            'offset' => 0,
            'limit' => 20,
        ], Http::jsonBody($request));

        self::assertSame(1, $result->totalResults);
        self::assertCount(1, $result->items);
        $item = $result->items[0];

        self::assertSame('AE:PL-98765-43210-SFVYC-19', $item->address->address);
        self::assertSame(EdaStatus::Active, $item->address->status);
        self::assertTrue($item->address->isActive());
        self::assertSame(AssignmentDegree::Disclosed, $item->address->assignmentDegree);
        self::assertTrue($item->address->designatedOperator);
        self::assertTrue($item->address->isMain);
        self::assertSame('2024-01-15', $item->address->enteredAt);
        self::assertSame('2025-02-01', $item->address->designatedOperatorService?->activationDate);
        self::assertSame(WarningType::NotBlocking, $item->address->warning?->type);
        self::assertFalse($item->address->hasBlockingWarning());

        self::assertCount(2, $item->records);
        $current = $item->current();
        self::assertNotNull($current);
        self::assertSame(1, $current->index, 'records are ordered from current to archived');
        self::assertSame('MINISTERSTWO POCZT I TELEGRAFÓW', $current->displayName());
        self::assertSame('5232958825', $current->officialId(OfficialIdType::Nip));
        self::assertNull($current->officialId(OfficialIdType::Krs));
        self::assertSame(NipStatus::Assigned, $current->nipStatus);
        self::assertTrue($current->addresses[0]->isOfType(AddressType::Headquarters));
        self::assertSame('HALINÓW', $current->addresses[0]->community);
        self::assertSame(2, $item->records[1]->index);
    }

    public function testAddressLookupUsesEdaSearchEndpoint(): void
    {
        $this->http->queue(Http::fixture(200, 'search-engine-v4/eda-search.200.json'));

        $result = $this->client()->searchEngine()->findByAddresses(['AE:PL-12345-67890-ABCDE-10', 'AE:PL-12345-67890-ABCDE-11'], 0, 100);

        $request = $this->http->lastRequest();
        self::assertSame('https://int-ow.edoreczenia.gov.pl/api/se/v4/search/eda-search', (string) $request->getUri());
        self::assertSame([
            'recipientEdas' => ['AE:PL-12345-67890-ABCDE-10', 'AE:PL-12345-67890-ABCDE-11'],
            'senderEda' => self::SENDER,
            'offset' => 0,
            'limit' => 100,
        ], Http::jsonBody($request));

        self::assertSame(2, $result->totalResults);
        self::assertNull($result->items[0]->address->assignmentDegree);
        self::assertTrue($result->items[1]->address->hasBlockingWarning());
        self::assertSame(EdaStatus::ClosedRecoverable, $result->items[1]->address->status);
        self::assertSame('NAZWA PODMIOTU', $result->items[1]->holder?->entityName);
    }

    public function testOwnAddressDetailsSendsSenderAsRecipient(): void
    {
        $this->http->queue(Http::fixture(200, 'search-engine-v4/eda-confirmation.200.json'));

        $details = $this->client()->searchEngine()->getOwnAddressDetails();

        $request = $this->http->lastRequest();
        self::assertSame('https://int-ow.edoreczenia.gov.pl/api/se/v4/search/eda-confirmation', (string) $request->getUri());
        self::assertSame(['recipientEda' => self::SENDER, 'senderEda' => self::SENDER], Http::jsonBody($request));
        self::assertSame(self::SENDER, $details->address->address);
        self::assertFalse($details->holder?->isPublic);
    }

    public function testNoResultsIsReportedAsNotFoundWithErrorCode(): void
    {
        $this->http->queue(Http::fixture(404, 'search-engine-v4/error.404.no-results.json'));

        try {
            $this->client()->searchEngine()->search(AdvancedSearch::byDeliveryAddresses('AE:PL-00000-00000-AAAAA-00'));
            self::fail('Expected exception');
        } catch (NotFoundException $e) {
            self::assertTrue($e->hasErrorCode(ErrorCode::NO_RESULTS));
            self::assertSame('0d8f2f4e-2b7f-4a3c-9a43-2f9a8d3c1b11', $e->getEventId());
            self::assertSame('GB', $e->getErrors()[0]->language);
        }
    }

    public function testUnknownEnumValuesArePreservedAsStrings(): void
    {
        $this->http->queue(Http::json(200, [
            'edaSearchResponses' => [[
                'recipientEda' => ['recipientEda' => 'AE:PL-1', 'edaStatus' => 'SUSPENDED_IN_FUTURE', 'designatedOperator' => false, 'futureField' => 1],
                'recipient' => ['isPublic' => true, 'nipStatus' => 'NEW_STATUS'],
            ]],
        ]));

        $item = $this->client()->searchEngine()->findByAddresses(['AE:PL-1'])->items[0];

        self::assertSame('SUSPENDED_IN_FUTURE', $item->address->status);
        self::assertFalse($item->address->isActive());
        self::assertSame('NEW_STATUS', $item->holder?->nipStatus);
        self::assertNull($item->address->assignmentDegree);
    }

    public function testNumericAssignmentDegreeIsAccepted(): void
    {
        $this->http->queue(Http::json(200, [
            'recipientEda' => ['recipientEda' => self::SENDER, 'edaStatus' => 'ACTIVE', 'designatedOperator' => true, 'assignmentDegree' => 2],
        ]));

        self::assertSame(AssignmentDegree::Undisclosed, $this->client()->searchEngine()->getOwnAddressDetails()->address->assignmentDegree);
    }

    public function testSchemaViolationNamesTheFieldButNotItsValue(): void
    {
        $this->http->queue(Http::json(200, [
            'baeSearchResponses' => [['recipientEda' => ['recipientEda' => 'AE:PL-1', 'edaStatus' => 'ACTIVE', 'designatedOperator' => 'JAN KOWALSKI']]],
        ]));

        try {
            $this->client()->searchEngine()->search(AdvancedSearch::byDeliveryAddresses('AE:PL-1'));
            self::fail('Expected exception');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('$.baeSearchResponses[0].recipientEda.designatedOperator', $e->getMessage());
            self::assertStringNotContainsString('KOWALSKI', $e->getMessage());
        }
    }

    public function testMissingRequiredFieldIsReported(): void
    {
        $this->http->queue(Http::json(200, ['baeSearchResponses' => [['baeSearchData' => []]]]));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('recipientEda');
        $this->client()->searchEngine()->search(AdvancedSearch::byDeliveryAddresses('AE:PL-1'));
    }

    public function testEmptyResultListIsValid(): void
    {
        $this->http->queue(Http::json(200, ['baeSearchResponses' => [], 'totalResults' => 0]));

        $result = $this->client()->searchEngine()->search(AdvancedSearch::byDeliveryAddresses('AE:PL-1'));

        self::assertSame([], $result->items);
        self::assertSame(0, $result->totalResults);
    }

    public function testAddressLookupValidatesLocallyWithoutNetwork(): void
    {
        $client = $this->client()->searchEngine();

        foreach ([fn() => $client->findByAddresses([]), fn() => $client->findByAddresses(array_fill(0, 1001, 'AE:PL-1')), fn() => $client->findByAddresses(['AE:PL-1'], -1), fn() => $client->findByAddresses(['AE:PL-1'], 0, 0), fn() => $client->findByAddresses([' '])] as $call) {
            try {
                $call();
                self::fail('Expected exception');
            } catch (InvalidArgumentException) {
            }
        }

        self::assertSame([], $this->http->requests);
    }

    public function testWithSystemCredentialsObtainsTokenFirst(): void
    {
        $this->http->queue(Http::tokenResponse('jwt-token'), Http::fixture(200, 'search-engine-v4/eda-confirmation.200.json'));
        $client = EdoreczeniaClient::withSystemCredentials(
            Environment::integration(),
            new SystemCredentials(self::SENDER, 'EZD1', PrivateKey::fromPem(TestKeys::rsa()['private'])),
            $this->http,
            Http::factory(),
            Http::factory(),
        );

        $client->searchEngine()->getOwnAddressDetails();

        self::assertCount(2, $this->http->requests);
        self::assertStringContainsString('/openid-connect/token', (string) $this->http->requests[0]->getUri());
        self::assertSame('Bearer jwt-token', $this->http->requests[1]->getHeaderLine('Authorization'));
        self::assertSame(self::SENDER, $client->deliveryAddress());
    }

    private function client(): EdoreczeniaClient
    {
        return new EdoreczeniaClient(Environment::integration(), self::SENDER, new SpyTokenProvider(), $this->http, Http::factory(), Http::factory());
    }
}
