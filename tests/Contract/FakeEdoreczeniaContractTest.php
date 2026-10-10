<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Contract;

use B4x\Edoreczenia\Testing\FakeEdoreczenia;
use B4x\Edoreczenia\Tests\Support\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates every JSON response of the testing kit against the official contracts, so integrators
 * testing with {@see FakeEdoreczenia} see the same shapes as the real services.
 *
 * Requires `composer specs:fetch`; skipped otherwise.
 */
final class FakeEdoreczeniaContractTest extends TestCase
{
    private const SE_SPEC = __DIR__ . '/../../build/specs/se-api_v.4.0.5.yaml';
    private const UA_SPEC = __DIR__ . '/../../build/specs/uaapi_3.0.8.2.yaml';
    private const MAILBOX = 'AE:PL-12345-67890-ABCDE-12';
    private const SE = 'https://int-ow.edoreczenia.gov.pl/api/se/v4/search/';
    private const UA = 'https://uaapi-int-ow.poczta-polska.pl/api/v3/';

    /** @var array<string, array<mixed>> */
    private static array $specs = [];

    /**
     * @return iterable<string, array{string, array<mixed>, string}>
     */
    public static function searchEngineResponses(): iterable
    {
        yield 'bae_search by criteria' => ['bae_search', ['recipientEdasOnly' => false, 'searchCategory' => ['PUBLIC_INSTITUTION'], 'entityName' => 'MINISTERSTWO'], 'SearchResponse'];
        yield 'bae_search by addresses' => ['bae_search', ['recipientEdasOnly' => true, 'recipientEdas' => ['AE:PL-12345-67890-ABCDE-10', 'AE:PL-12345-67890-ABCDE-11', self::MAILBOX, 'AE:PL-98765-43210-SFVYC-19']], 'SearchResponse'];
        yield 'bae_search no results' => ['bae_search', ['recipientEdasOnly' => true, 'recipientEdas' => ['AE:PL-0']], 'SearchEngineError'];
        yield 'eda-search' => ['eda-search', ['recipientEdas' => ['AE:PL-12345-67890-ABCDE-10', 'AE:PL-12345-67890-ABCDE-11', self::MAILBOX, 'AE:PL-98765-43210-SFVYC-19'], 'offset' => 0, 'limit' => 1000], 'EdaSearchResponses'];
        yield 'eda-confirmation' => ['eda-confirmation', ['recipientEda' => self::MAILBOX], 'EdaConfirmationResponse'];
        yield 'eda-confirmation mismatch' => ['eda-confirmation', ['recipientEda' => 'AE:PL-0'], 'SearchEngineError'];
    }

    /**
     * @param array<mixed> $body
     */
    #[DataProvider('searchEngineResponses')]
    public function testSearchEngineResponsesMatchContract(string $operation, array $body, string $schema): void
    {
        $fake = (new FakeEdoreczenia(Http::factory(), Http::factory()))->acceptToken('t', self::MAILBOX);
        $response = $fake->sendRequest(
            Http::factory()->createRequest('POST', self::SE . $operation)
                ->withHeader('Authorization', 'Bearer t')
                ->withHeader('Content-Type', 'application/json')
                ->withBody(Http::factory()->createStream(json_encode($body + ['senderEda' => self::MAILBOX], \JSON_THROW_ON_ERROR))),
        );

        self::assertSame([], (new OpenApiSchemaValidator(self::spec(self::SE_SPEC)))->validate($this->decode((string) $response->getBody()), $schema));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function userAgentResponses(): iterable
    {
        yield 'directories' => ['{m}/directories', 'Directory', true];
        yield 'numberMessages' => ['{m}/numberMessages', 'DirectoryExtended', true];
        yield 'settings' => ['{m}/settings', 'Settings', false];
        yield 'messages' => ['{m}/messages', 'MessagesWrapper', false];
        yield 'threads' => ['{m}/threads', 'Threads', false];
        yield 'thread' => ['{m}/threads/T-1', 'Thread', false];
        yield 'message evidences' => ['{m}/messages/D41568F4-7175-42BB-9503-DAA282180D70/evidences', 'EvidenceWrapper', false];
        yield 'mailbox evidences' => ['{m}/messages/evidences', 'EvidenceWrapper', false];
        yield 'evidence' => ['{m}/messages/evidences/EV-3', 'Evidence', true];
        yield 'unknown id' => ['{m}/threads/T-404', 'ErrorInfo', true];
        yield 'unknown route' => ['{m}/nothing', 'ErrorInfo', true];
    }

    #[DataProvider('userAgentResponses')]
    public function testUserAgentResponsesMatchContract(string $path, string $schema, bool $list): void
    {
        $fake = (new FakeEdoreczenia(Http::factory(), Http::factory()))->acceptToken('t', self::MAILBOX);
        $response = $fake->sendRequest(
            Http::factory()->createRequest('GET', self::UA . str_replace('{m}', self::MAILBOX, $path))->withHeader('Authorization', 'Bearer t'),
        );
        $data = $this->decode((string) $response->getBody());
        $validator = new OpenApiSchemaValidator(self::spec(self::UA_SPEC));

        foreach ($list ? $data : [$data] as $item) {
            self::assertSame([], $validator->validate($item, $schema));
        }
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $json): array
    {
        $data = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertNotSame([], $data);

        return $data;
    }

    /**
     * @return array<mixed>
     */
    private static function spec(string $file): array
    {
        if (!isset(self::$specs[$file])) {
            if (!is_file($file)) {
                self::markTestSkipped('Official contract not available; run "composer specs:fetch".');
            }
            $parsed = Yaml::parseFile($file);
            self::assertIsArray($parsed);
            self::$specs[$file] = $parsed;
        }

        return self::$specs[$file];
    }
}
