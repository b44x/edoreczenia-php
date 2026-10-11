<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\Testing;

use B4x\Edoreczenia\Auth\AccessToken;
use B4x\Edoreczenia\Auth\PrivateKey;
use B4x\Edoreczenia\Auth\StaticTokenProvider;
use B4x\Edoreczenia\Auth\SystemCredentials;
use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\Exception\AuthenticationException;
use B4x\Edoreczenia\Exception\AuthorizationException;
use B4x\Edoreczenia\Exception\BadRequestException;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\Exception\NotFoundException;
use B4x\Edoreczenia\Exception\ServerException;
use B4x\Edoreczenia\SearchEngine\V4\Enum\EdaStatus;
use B4x\Edoreczenia\SearchEngine\V4\Enum\SearchCategory;
use B4x\Edoreczenia\SearchEngine\V4\ErrorCode;
use B4x\Edoreczenia\SearchEngine\V4\Request\AdvancedSearch;
use B4x\Edoreczenia\Testing\FakeEdoreczenia;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\Tests\Support\TestKeys;
use B4x\Edoreczenia\UserAgent\V3\Enum\EvidenceService;
use B4x\Edoreczenia\UserAgent\V3\Enum\EvidenceType;
use B4x\Edoreczenia\UserAgent\V3\Enum\ShippingService;
use B4x\Edoreczenia\UserAgent\V3\Request\EvidenceQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\MessageQuery;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

final class FakeEdoreczeniaTest extends TestCase
{
    private const MAILBOX = 'AE:PL-12345-67890-ABCDE-12';
    private const MESSAGE_ID = 'D41568F4-7175-42BB-9503-DAA282180D70';

    private Psr17Factory $factory;
    private FakeEdoreczenia $fake;

    protected function setUp(): void
    {
        $this->factory = Http::factory();
        $this->fake = new FakeEdoreczenia($this->factory, $this->factory);
    }

    public function testFullSignedJwtFlow(): void
    {
        $credentials = new SystemCredentials(self::MAILBOX, 'EZD', PrivateKey::fromPem(TestKeys::rsa()['private']));
        $client = EdoreczeniaClient::withSystemCredentials(Environment::integration(), $credentials, $this->fake, $this->factory, $this->factory);

        $own = $client->searchEngine()->getOwnAddressDetails();
        $directories = $client->userAgent()->directories();

        self::assertSame(self::MAILBOX, $own->address->address);
        self::assertSame('NAZWA PODMIOTU', $own->holder?->entityName);
        self::assertCount(2, $directories);

        $requests = $this->fake->requests();
        self::assertCount(3, $requests, 'one token request, reused for both calls');
        self::assertStringEndsWith('/protocol/openid-connect/token', $requests[0]->getUri()->getPath());
        self::assertSame($requests[1]->getHeaderLine('Authorization'), $requests[2]->getHeaderLine('Authorization'));
        self::assertMatchesRegularExpression('/^Bearer fake-[0-9a-f]+$/', $requests[1]->getHeaderLine('Authorization'));
    }

    public function testSystemCredentialsWithOnlyAClientDetectFactories(): void
    {
        $credentials = new SystemCredentials(self::MAILBOX, 'EZD', PrivateKey::fromPem(TestKeys::rsa()['private']));
        $client = EdoreczeniaClient::withSystemCredentials(Environment::integration(), $credentials, $this->fake);

        self::assertSame(self::MAILBOX, $client->searchEngine()->getOwnAddressDetails()->address->address);
        self::assertCount(2, $this->fake->requests());
    }

    public function testTokenEndpointRespondsLikeKeycloak(): void
    {
        $credentials = new SystemCredentials(self::MAILBOX, 'EZD', PrivateKey::fromPem(TestKeys::rsa()['private']));
        $client = EdoreczeniaClient::withSystemCredentials(Environment::production(), $credentials, $this->fake, $this->factory, $this->factory);
        $client->userAgent()->countries();

        $tokenRequest = $this->fake->requests()[0];
        $response = $this->fake->sendRequest($tokenRequest);
        $body = $this->decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(300, $body['expires_in']);
        self::assertSame(0, $body['refresh_expires_in']);
        self::assertSame('Bearer', $body['token_type']);
        self::assertSame('system-attributes', $body['scope']);
    }

    public function testTokenRequestViolationsGetOAuthErrors(): void
    {
        $assertion = 'eyJhbGciOiJSUzI1NiJ9.' . rtrim(strtr(base64_encode('{"iss":"AE:PL-1.SYSTEM.EZD","sub":"AE:PL-1.SYSTEM.EZD"}'), '+/', '-_'), '=') . '.c2ln';
        $valid = ['grant_type' => 'client_credentials', 'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', 'client_assertion' => $assertion];
        $endpoint = 'https://int-ow.edoreczenia.gov.pl/auth/realms/EDOR/protocol/openid-connect/token';

        self::assertSame(200, $this->postForm($endpoint . '?login_hint=ADE.AE%3APL-1', $valid)->getStatusCode());

        $cases = [
            'no login_hint' => [$endpoint, $valid],
            'login_hint without ADE.' => [$endpoint . '?login_hint=AE%3APL-1', $valid],
            'wrong grant' => [$endpoint . '?login_hint=ADE.AE%3APL-1', ['grant_type' => 'password'] + $valid],
            'wrong assertion type' => [$endpoint . '?login_hint=ADE.AE%3APL-1', ['client_assertion_type' => 'x'] + $valid],
            'not a JWT' => [$endpoint . '?login_hint=ADE.AE%3APL-1', ['client_assertion' => 'abc'] + $valid],
            'assertion for another mailbox' => [$endpoint . '?login_hint=ADE.AE%3APL-2', $valid],
        ];
        foreach ($cases as $label => [$uri, $form]) {
            $response = $this->postForm($uri, $form);
            self::assertSame(400, $response->getStatusCode(), $label);
            self::assertSame('invalid_request', $this->decode($response)['error'], $label);
        }
    }

    public function testCreateClientUsesIntegrationByDefault(): void
    {
        $client = $this->fake->createClient();

        self::assertSame('integration', $client->environment()->name);
        self::assertSame(self::MAILBOX, $client->deliveryAddress());
        self::assertSame(['POLSKA', 'NIEMCY', 'CZECHY'], $client->userAgent()->countries());
        self::assertSame('https://uaapi-int-ow.poczta-polska.pl/api/v3/dictionary/countries', (string) $this->fake->requests()[0]->getUri());
    }

    public function testCreateClientWorksWithProductionAndCustomEnvironments(): void
    {
        $custom = new Environment('local', 'http://localhost/realms/x/protocol/openid-connect/token', 'http://localhost/realms/x', 'http://localhost/se/', 'http://localhost/ua/');

        self::assertCount(2, $this->fake->createClient(environment: Environment::production())->userAgent()->directories());
        self::assertSame(ShippingService::Electronic, $this->fake->createClient(environment: $custom)->userAgent()->listMessages()->messages[0]->shippingService);
        self::assertSame('http://localhost/ua/' . self::MAILBOX . '/messages', (string) $this->fake->requests()[1]->getUri());
    }

    public function testCreateClientRequiresARequestFactory(): void
    {
        $fake = new FakeEdoreczenia(
            new class implements ResponseFactoryInterface {
                public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
                {
                    return Http::factory()->createResponse($code, $reasonPhrase);
                }
            },
            new class implements StreamFactoryInterface {
                public function createStream(string $content = ''): StreamInterface
                {
                    return Http::factory()->createStream($content);
                }

                public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
                {
                    return Http::factory()->createStreamFromFile($filename, $mode);
                }

                public function createStreamFromResource($resource): StreamInterface
                {
                    return Http::factory()->createStreamFromResource($resource);
                }
            },
        );

        $this->expectException(InvalidArgumentException::class);
        $fake->createClient();
    }

    public function testSearchByCriteriaReturnsDefaultResult(): void
    {
        $result = $this->fake->createClient()->searchEngine()->search(AdvancedSearch::inCategories(SearchCategory::PublicInstitution)->withEntityName('MINISTERSTWO'));

        self::assertSame(1, $result->totalResults);
        self::assertSame('AE:PL-98765-43210-SFVYC-19', $result->items[0]->address->address);
        self::assertSame(EdaStatus::Active, $result->items[0]->address->status);
        self::assertCount(2, $result->items[0]->records);
        self::assertSame(self::MAILBOX, Http::jsonBody($this->fake->requests()[0])['senderEda']);
    }

    public function testSearchByAddressesReturnsKnownAddressesOnly(): void
    {
        $result = $this->fake->createClient()->searchEngine()->search(AdvancedSearch::byDeliveryAddresses('AE:PL-12345-67890-ABCDE-10', 'AE:PL-00000-00000-UNKWN-00'));

        self::assertSame(1, $result->totalResults);
        self::assertSame('AE:PL-12345-67890-ABCDE-10', $result->items[0]->address->address);
    }

    public function testSearchWithoutResultsIsNotFoundWithSeapi00010(): void
    {
        try {
            $this->fake->createClient()->searchEngine()->search(AdvancedSearch::byDeliveryAddresses('AE:PL-00000-00000-UNKWN-00'));
            self::fail('NotFoundException expected');
        } catch (NotFoundException $e) {
            self::assertTrue($e->hasErrorCode(ErrorCode::NO_RESULTS));
            self::assertNotNull($e->getEventId());
        }
    }

    public function testSearchPaginationBeyondResultsIsRejected(): void
    {
        $this->expectException(NotFoundException::class);

        $this->fake->createClient()->searchEngine()->search(AdvancedSearch::inCategories(SearchCategory::Company)->withEntityName('X Y')->withPagination(5));
    }

    public function testEdaSearchFiltersAndPagesRequestedAddresses(): void
    {
        $searchEngine = $this->fake->createClient()->searchEngine();
        $addresses = ['AE:PL-12345-67890-ABCDE-10', 'AE:PL-00000-00000-UNKWN-00', 'AE:PL-12345-67890-ABCDE-11'];

        $all = $searchEngine->findByAddresses($addresses);
        self::assertSame(2, $all->totalResults);
        self::assertSame(['AE:PL-12345-67890-ABCDE-10', 'AE:PL-12345-67890-ABCDE-11'], array_map(static fn($i): string => $i->address->address, $all->items));
        self::assertSame('MINISTERSTWO POCZT I TELEGRAFÓW', $all->items[0]->holder?->entityName);
        self::assertSame(EdaStatus::ClosedRecoverable, $all->items[1]->address->status);
        self::assertNotNull($all->items[1]->address->warning);

        $second = $searchEngine->findByAddresses($addresses, 1, 1);
        self::assertSame(2, $second->totalResults);
        self::assertCount(1, $second->items);
        self::assertSame('AE:PL-12345-67890-ABCDE-11', $second->items[0]->address->address);

        $none = $searchEngine->findByAddresses(['AE:PL-00000-00000-UNKWN-00']);
        self::assertSame(0, $none->totalResults);
        self::assertSame([], $none->items);
    }

    public function testOwnAddressDetailsForAnyMailbox(): void
    {
        $own = $this->fake->createClient('AE:PL-55555-55555-OTHER-55')->searchEngine()->getOwnAddressDetails();

        self::assertSame('AE:PL-55555-55555-OTHER-55', $own->address->address);
    }

    public function testSearchEngineEnforcesSenderEda(): void
    {
        $request = static fn(FakeEdoreczenia $fake, string $token, array $body): ResponseInterface => $fake->sendRequest(
            Http::factory()->createRequest('POST', 'https://int-ow.edoreczenia.gov.pl/api/se/v4/search/eda-confirmation')
                ->withHeader('Authorization', 'Bearer ' . $token)
                ->withBody(Http::factory()->createStream(json_encode($body, \JSON_THROW_ON_ERROR))),
        );
        $this->fake->acceptToken('t', self::MAILBOX);

        $missing = $request($this->fake, 't', ['recipientEda' => self::MAILBOX]);
        self::assertSame(400, $missing->getStatusCode());
        self::assertSame('SEAPI-00006', $this->seCode($missing));

        $foreign = $request($this->fake, 't', ['recipientEda' => 'AE:PL-1', 'senderEda' => 'AE:PL-1']);
        self::assertSame(401, $foreign->getStatusCode());
        self::assertSame('SEAPI-00017', $this->seCode($foreign));

        $mismatch = $request($this->fake, 't', ['recipientEda' => 'AE:PL-1', 'senderEda' => self::MAILBOX]);
        self::assertSame(400, $mismatch->getStatusCode());
        self::assertSame('SEAPI-00021', $this->seCode($mismatch));
    }

    public function testUserAgentReadOperations(): void
    {
        $ua = $this->fake->createClient()->userAgent();

        self::assertSame('INBOX', $ua->directories()[0]->label);
        self::assertNull($ua->directories()[0]->totalMessages);
        self::assertSame(1, $ua->directoriesWithCounts()[0]->totalMessages);
        self::assertSame('skrzynka@example.test', $ua->settings()->email);

        $messages = $ua->listMessages();
        self::assertSame(2, $messages->total);
        self::assertSame(self::MESSAGE_ID, $messages->messages[0]->messageId);
        self::assertCount(1, $ua->listMessages(MessageQuery::create()->withPagination(1, 1))->messages);

        $threads = $ua->listThreads();
        self::assertSame(1, $threads->total);
        self::assertSame('T-1', $threads->threads[0]->threadId);

        $thread = $ua->getThread('T-1');
        self::assertSame('T-1', $thread->threadId);
        self::assertSame(self::MESSAGE_ID, $thread->messages->messages[0]->messageId);

        self::assertCount(2, $ua->messageEvidences(self::MESSAGE_ID)->evidences);
        self::assertCount(3, $ua->mailboxEvidences()->evidences);
        self::assertCount(1, $ua->mailboxEvidences(EvidenceQuery::create()->ofType(EvidenceType::E1))->evidences);
        self::assertCount(1, $ua->mailboxEvidences(EvidenceQuery::create()->downloaded(true))->evidences);

        $evidence = $ua->evidence('EV-1');
        self::assertCount(1, $evidence);
        self::assertSame(self::MESSAGE_ID, $evidence[0]->messageId);

        $requests = $this->fake->requests();
        $last = array_pop($requests);
        self::assertNotNull($last);
        self::assertStringEndsWith('/AE:PL-12345-67890-ABCDE-12/messages/evidences/EV-1', (string) $last->getUri());
    }

    public function testDownloads(): void
    {
        $ua = $this->fake->createClient()->userAgent();

        $xml = $ua->downloadEvidence(EvidenceService::Purde, 'EV-1');
        self::assertSame('application/xml', $xml->contentType);
        self::assertSame('EV-1.xml', $xml->fileName);
        self::assertStringContainsString('<Evidence id="EV-1"/>', (string) $xml->stream);
        self::assertSame(\strlen((string) $xml->stream), $xml->size);

        $pdf = $ua->downloadEvidence(EvidenceService::Puh, 'EV-1', true);
        self::assertSame('application/pdf', $pdf->contentType);
        self::assertSame('EV-1.pdf', $pdf->fileName);

        $ack = $ua->downloadHybridAcknowledgement(self::MESSAGE_ID);
        self::assertSame('H.EPO-' . self::MESSAGE_ID . '.xml', $ack->fileName);

        $zip = $ua->downloadTechnicalEvidences(self::MESSAGE_ID);
        self::assertSame('application/zip', $zip->contentType);
        if (!class_exists(\ZipArchive::class)) {
            $bytes = (string) $zip->stream;
            self::assertStringStartsWith("PK\x03\x04", $bytes);
            self::assertStringContainsString('README.txt', $bytes);

            return;
        }
        $path = tempnam(sys_get_temp_dir(), 'fake-zip');
        self::assertIsString($path);
        try {
            $zip->saveTo($path);
            $archive = new \ZipArchive();
            self::assertTrue($archive->open($path));
            self::assertSame(1, $archive->numFiles);
            self::assertStringContainsString('Synthetic', (string) $archive->getFromName('README.txt'));
            $archive->close();
        } finally {
            unlink($path);
        }
    }

    public function testUnknownIdsAreBadRequests(): void
    {
        $ua = $this->fake->createClient()->userAgent();
        $calls = [
            'thread' => static fn() => $ua->getThread('T-404'),
            'message evidences' => static fn() => $ua->messageEvidences('nope'),
            'evidence' => static fn() => $ua->evidence('EV-404'),
            'evidence file' => static fn() => $ua->downloadEvidence(EvidenceService::Purde, 'EV-404'),
            'hybrid acknowledgement' => static fn() => $ua->downloadHybridAcknowledgement('nope'),
            'technical evidences' => static fn() => $ua->downloadTechnicalEvidences('nope'),
        ];

        foreach ($calls as $label => $call) {
            try {
                $call();
                self::fail($label . ': BadRequestException expected');
            } catch (BadRequestException $e) {
                self::assertTrue($e->hasErrorCode('INVALID_PARAMETER'), $label);
            }
        }
    }

    public function testRequestsWithoutValidTokenAreUnauthorized(): void
    {
        $se = $this->fake->sendRequest(Http::factory()->createRequest('POST', 'https://ow.edoreczenia.gov.pl/api/se/v4/search/eda-search'));
        self::assertSame(401, $se->getStatusCode());
        self::assertSame('SEAPI-00017', $this->seCode($se));
        self::assertSame('No authorization', $this->seField($se, 'message'));

        $ua = $this->fake->sendRequest(
            Http::factory()->createRequest('GET', 'https://uaapi-ow.poczta-polska.pl/api/v3/' . self::MAILBOX . '/messages')->withHeader('Authorization', 'Bearer forged'),
        );
        self::assertSame(401, $ua->getStatusCode());
        self::assertSame('unauthorized', $this->uaErrorCode($ua));
    }

    public function testMailboxInPathMustMatchToken(): void
    {
        $this->fake->acceptToken('t', 'AE:PL-55555-55555-OTHER-55');
        $client = new EdoreczeniaClient(
            Environment::integration(),
            self::MAILBOX,
            new StaticTokenProvider(new AccessToken('t', new \DateTimeImmutable('+1 hour'))),
            $this->fake,
            $this->factory,
            $this->factory,
        );

        $this->expectException(AuthorizationException::class);
        $client->userAgent()->directories();
    }

    public function testFailNextWithSearchEngineErrorBody(): void
    {
        $client = $this->fake->createClient();
        $this->fake->failNext('search/eda-search', 504, Http::fixtureBody('search-engine-v4/error.504.json'));

        try {
            $client->searchEngine()->findByAddresses(['AE:PL-12345-67890-ABCDE-10']);
            self::fail('ServerException expected');
        } catch (ServerException $e) {
            self::assertTrue($e->hasErrorCode(ErrorCode::TIMEOUT));
            self::assertSame('5b0b3c1e-61d8-4d9a-8f8e-7d0b0b2a4c55', $e->getEventId());
        }

        // Only once: the next call succeeds.
        self::assertSame(1, $client->searchEngine()->findByAddresses(['AE:PL-12345-67890-ABCDE-10'])->totalResults);
    }

    public function testFailNextWithUserAgentErrorBodyAndStatuses(): void
    {
        $ua = $this->fake->createClient()->userAgent();
        $this->fake
            ->failNext('/messages', 400, [['error' => 'INVALID_PARAMETER', 'error_description' => 'Invalid value of parameter limit']])
            ->failNext('/settings', 503, 'Service Unavailable')
            ->failNext('/threads', 404, []);

        try {
            $ua->listMessages();
            self::fail('BadRequestException expected');
        } catch (BadRequestException $e) {
            self::assertTrue($e->hasErrorCode('INVALID_PARAMETER'));
        }

        try {
            $ua->settings();
            self::fail('ServerException expected');
        } catch (ServerException $e) {
            self::assertSame(503, $e->getStatusCode());
        }

        $this->expectException(NotFoundException::class);
        $ua->listThreads();
    }

    public function testFailNextOnTokenEndpoint(): void
    {
        $this->fake->failNext('openid-connect/token', 401, ['error' => 'invalid_client', 'error_description' => 'Invalid client credentials']);
        $credentials = new SystemCredentials(self::MAILBOX, 'EZD', PrivateKey::fromPem(TestKeys::rsa()['private']));
        $client = EdoreczeniaClient::withSystemCredentials(Environment::integration(), $credentials, $this->fake, $this->factory, $this->factory);

        try {
            $client->userAgent()->countries();
            self::fail('AuthenticationException expected');
        } catch (AuthenticationException $e) {
            self::assertTrue($e->hasErrorCode('invalid_client'));
        }
        self::assertCount(1, $this->fake->requests(), 'no API call without a token');
    }

    public function testUnknownRouteIsNotFound(): void
    {
        $this->fake->acceptToken('t');
        $response = $this->fake->sendRequest(
            Http::factory()->createRequest('GET', 'https://uaapi-int-ow.poczta-polska.pl/api/v3/' . self::MAILBOX . '/unknown')->withHeader('Authorization', 'Bearer t'),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('not_found', $this->uaErrorCode($response));
    }

    public function testNonGetUserAgentRequestsAreRejected(): void
    {
        $this->fake->acceptToken('t');
        foreach (['POST' => '/messages/' . self::MESSAGE_ID, 'PUT' => '/messages/' . self::MESSAGE_ID, 'PATCH' => '/messages/' . self::MESSAGE_ID, 'DELETE' => '/messages/' . self::MESSAGE_ID] as $method => $path) {
            $response = $this->fake->sendRequest(
                Http::factory()->createRequest($method, 'https://uaapi-int-ow.poczta-polska.pl/api/v3/' . self::MAILBOX . $path)->withHeader('Authorization', 'Bearer t'),
            );

            self::assertSame(405, $response->getStatusCode(), $method);
            self::assertSame('method_not_allowed', $this->uaErrorCode($response));
        }
    }

    public function testRecordsEveryRequestAndNeverLeavesTheProcess(): void
    {
        // The fake is the only PSR-18 client the SDK receives: every request lands here.
        $client = $this->fake->createClient(environment: Environment::production());
        $client->userAgent()->countries();
        $client->searchEngine()->getOwnAddressDetails();

        $requests = $this->fake->requests();
        self::assertCount(2, $requests);
        self::assertSame(['GET', 'POST'], array_map(static fn($r): string => $r->getMethod(), $requests));
        self::assertSame('https://uaapi-ow.poczta-polska.pl/api/v3/dictionary/countries', (string) $requests[0]->getUri());
        self::assertSame('https://ow.edoreczenia.gov.pl/api/se/v4/search/eda-confirmation', (string) $requests[1]->getUri());
    }

    /**
     * @param array<string, string> $form
     */
    private function postForm(string $uri, array $form): ResponseInterface
    {
        return $this->fake->sendRequest(
            Http::factory()->createRequest('POST', $uri)->withBody(Http::factory()->createStream(http_build_query($form))),
        );
    }

    /**
     * @return array<mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 64, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    private function seCode(ResponseInterface $response): mixed
    {
        return $this->seField($response, 'errorCode');
    }

    private function seField(ResponseInterface $response, string $field): mixed
    {
        $codes = $this->decode($response)['errorCodes'] ?? null;
        $first = \is_array($codes) ? ($codes[0] ?? null) : null;

        return \is_array($first) ? ($first[$field] ?? null) : null;
    }

    private function uaErrorCode(ResponseInterface $response): mixed
    {
        $first = $this->decode($response)[0] ?? null;

        return \is_array($first) ? ($first['error'] ?? null) : null;
    }
}
