<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\UserAgent\V3;

use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\Exception\BadRequestException;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\Http\RetryPolicy;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\Tests\Support\MockHttpClient;
use B4x\Edoreczenia\Tests\Support\NetworkError;
use B4x\Edoreczenia\Tests\Support\RecordingLogger;
use B4x\Edoreczenia\Tests\Support\SpyTokenProvider;
use B4x\Edoreczenia\UserAgent\V3\Enum\EvidenceService;
use B4x\Edoreczenia\UserAgent\V3\Enum\EvidenceType;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortDirection;
use B4x\Edoreczenia\UserAgent\V3\Request\EvidenceQuery;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvidencesTest extends TestCase
{
    private const MAILBOX = 'AE:PL-12345-67890-ABCDE-12';
    private const PREFIX = '/api/v3/' . self::MAILBOX . '/';

    private MockHttpClient $http;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->logger = new RecordingLogger();
    }

    public function testMessageEvidencesWithFilters(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/evidences.200.json'));

        $list = $this->client()->userAgent()->messageEvidences(
            'D41568F4-7175-42BB-9503-DAA282180D70',
            EvidenceQuery::create()->ofType(EvidenceType::ReceiptConfirmationPdf)->downloaded(false)->sortByEventDate(SortDirection::Ascending),
        );

        $uri = $this->http->lastRequest()->getUri();
        self::assertSame(self::PREFIX . 'messages/D41568F4-7175-42BB-9503-DAA282180D70/evidences', $uri->getPath());
        parse_str($uri->getQuery(), $query);
        self::assertSame(['type' => 'BP.OP', 'downloaded' => 'false', 'sortColumn' => 'eventDate', 'sortDirection' => 'asc'], $query);

        self::assertCount(3, $list->evidences);
        [$receipt, $delivery, $rejection] = $list->evidences;
        self::assertSame(EvidenceType::ReceiptConfirmationPdf, $receipt->type);
        self::assertFalse($receipt->downloaded);
        self::assertSame('AE:PL-98765-43210-SFVYC-19', $receipt->to?->deliveryAddress);
        self::assertSame(EvidenceType::E1, $delivery->type, 'undotted contract spelling "E1" is normalised');
        self::assertSame(EvidenceType::A2, $rejection->type);
        self::assertSame(['Nieprawidłowy format pliku', 'Invalid file format'], $rejection->reasonDetails);
    }

    public function testMailboxEvidencesAndSingleEvidence(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/evidences.200.json'), Http::fixture(200, 'user-agent-v3/evidence.200.json'));
        $ua = $this->client()->userAgent();

        self::assertCount(3, $ua->mailboxEvidences()->evidences);
        self::assertSame(self::PREFIX . 'messages/evidences', $this->http->lastRequest()->getUri()->getPath());

        $evidence = $ua->evidence('EV-1');
        self::assertSame(self::PREFIX . 'messages/evidences/EV-1', $this->http->lastRequest()->getUri()->getPath());
        self::assertSame('EV-1', $evidence[0]->evidenceId);
    }

    public function testUnknownEvidenceCodesArePreserved(): void
    {
        $this->http->queue(Http::json(200, ['evidences' => [['evidenceId' => 'x', 'messageId' => 'm', 'type' => 'C2']]]));

        self::assertSame('C2', $this->client()->userAgent()->mailboxEvidences()->evidences[0]->type);
    }

    public function testEvidenceTypeSpellings(): void
    {
        self::assertSame(EvidenceType::ReceiptConfirmationXml, EvidenceType::fromWire('BPOX'));
        self::assertSame(EvidenceType::HybridDelivered, EvidenceType::fromWire('HEPO'));
        self::assertSame(EvidenceType::HybridDelivered, EvidenceType::fromWire('H.EPO'));
        self::assertTrue(EvidenceType::HybridSubmitted->isHybrid());
        self::assertFalse(EvidenceType::E1->isHybrid());
        self::assertNull(EvidenceType::fromWire('D'));
    }

    public function testDownloadEvidenceStreamsBinaryBody(): void
    {
        $this->http->queue(new Response(200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => '9',
            'Content-Disposition' => 'attachment; filename="BP.OP_EV-1.pdf"',
        ], '%PDF-1.7.'));

        $download = $this->client()->userAgent()->downloadEvidence(EvidenceService::Purde, 'EV-1');

        $request = $this->http->lastRequest();
        self::assertSame(self::PREFIX . 'evidences/purde/EV-1', $request->getUri()->getPath());
        self::assertSame('', $request->getUri()->getQuery());
        self::assertSame('*/*', $request->getHeaderLine('Accept'));
        self::assertSame('application/pdf', $download->contentType);
        self::assertSame('BP.OP_EV-1.pdf', $download->fileName);
        self::assertSame(9, $download->size);

        $path = tempnam(sys_get_temp_dir(), 'ev');
        self::assertIsString($path);
        try {
            self::assertSame(9, $download->saveTo($path));
            self::assertSame('%PDF-1.7.', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function testVisualisationRequestsPdfFormat(): void
    {
        $this->http->queue(new Response(200, ['Content-Type' => 'application/pdf'], 'x'), new Response(200, ['Content-Type' => 'application/pdf'], 'y'));
        $ua = $this->client()->userAgent();

        $ua->downloadEvidence(EvidenceService::Puh, 'EV-9', visualisationPdf: true);
        self::assertSame(self::PREFIX . 'evidences/puh/EV-9', $this->http->lastRequest()->getUri()->getPath());
        self::assertSame('evidenceFormat=PDF', $this->http->lastRequest()->getUri()->getQuery());

        $ua->downloadHybridAcknowledgement('MSG-1', visualisationPdf: true);
        self::assertSame(self::PREFIX . 'evidences/puhepo/MSG-1', $this->http->lastRequest()->getUri()->getPath());
        self::assertSame('evidenceFormat=PDF', $this->http->lastRequest()->getUri()->getQuery());
    }

    public function testTechnicalEvidencesZip(): void
    {
        $this->http->queue(new Response(200, ['Content-Type' => 'application/zip'], 'PK'));

        $download = $this->client()->userAgent()->downloadTechnicalEvidences('MSG-1');

        self::assertSame(self::PREFIX . 'evidences/MSG-1/technical-evidences-file', $this->http->lastRequest()->getUri()->getPath());
        self::assertSame('application/zip', $download->contentType);
        self::assertNull($download->fileName);
        self::assertNull($download->size);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function dispositions(): iterable
    {
        yield 'quoted' => ['attachment; filename="dowod.xml"', 'dowod.xml'];
        yield 'token' => ['attachment; filename=dowod.xml', 'dowod.xml'];
        yield 'rfc 6266 utf-8' => ["attachment; filename*=UTF-8''dow%C3%B3d.xml", 'dowód.xml'];
        yield 'path traversal' => ['attachment; filename="../../etc/passwd"', 'passwd'];
        yield 'windows path' => ['attachment; filename="C:\\temp\\x.pdf"', 'x.pdf'];
        yield 'dot dot only' => ['attachment; filename=".."', null];
        yield 'no filename' => ['inline', null];
    }

    #[DataProvider('dispositions')]
    public function testFileNameFromContentDispositionIsSanitised(string $disposition, ?string $expected): void
    {
        $this->http->queue(new Response(200, ['Content-Type' => 'application/xml', 'Content-Disposition' => $disposition], '<x/>'));

        self::assertSame($expected, $this->client()->userAgent()->downloadEvidence(EvidenceService::Purde, 'EV-1')->fileName);
    }

    public function testDownloadErrorsAreMappedLikeJsonCalls(): void
    {
        $this->http->queue(Http::fixture(400, 'user-agent-v3/error.400.json'));

        $this->expectException(BadRequestException::class);
        $this->client()->userAgent()->downloadEvidence(EvidenceService::Purde, 'EV-1');
    }

    public function testDownloadsAreRetriedOnNetworkErrors(): void
    {
        $this->http->queue(new NetworkError(new Request('GET', 'https://x.test')), new Response(200, ['Content-Type' => 'application/pdf'], 'ok'));
        $client = new EdoreczeniaClient(Environment::integration(), self::MAILBOX, new SpyTokenProvider(), $this->http, Http::factory(), Http::factory(), null, RetryPolicy::exponential(1, 0, 0));

        self::assertSame('ok', (string) $client->userAgent()->downloadEvidence(EvidenceService::Purde, 'EV-1')->stream);
        self::assertCount(2, $this->http->requests);
    }

    public function testIdsAreValidatedLocally(): void
    {
        $ua = $this->client()->userAgent();
        foreach ([fn() => $ua->messageEvidences(' '), fn() => $ua->evidence(''), fn() => $ua->downloadEvidence(EvidenceService::Puh, 'a b'), fn() => $ua->downloadHybridAcknowledgement(''), fn() => $ua->downloadTechnicalEvidences(' ')] as $call) {
            try {
                $call();
                self::fail('Expected exception');
            } catch (InvalidArgumentException) {
            }
        }

        self::assertSame([], $this->http->requests);
    }

    public function testMailboxAddressIsMaskedInDownloadLogs(): void
    {
        $this->http->queue(new Response(200, ['Content-Type' => 'application/pdf'], 'x'));

        $this->client()->userAgent()->downloadEvidence(EvidenceService::Purde, 'EV-1');

        self::assertStringNotContainsString(self::MAILBOX, $this->logger->dump());
        self::assertStringContainsString('{masked}/evidences/purde/EV-1', $this->logger->dump());
    }

    private function client(): EdoreczeniaClient
    {
        return new EdoreczeniaClient(Environment::integration(), self::MAILBOX, new SpyTokenProvider(), $this->http, Http::factory(), Http::factory(), $this->logger);
    }
}
