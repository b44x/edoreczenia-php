# e-Doręczenia PHP SDK

[![CI](https://github.com/b44x/edoreczenia-php/actions/workflows/ci.yml/badge.svg)](https://github.com/b44x/edoreczenia-php/actions/workflows/ci.yml)

Unofficial, framework-agnostic PHP SDK for **e-Doręczenia**, Poland's public electronic registered delivery service.
It implements the documented **system-to-system authentication** (signed JWT, RFC 7523), the **Search Engine API v4**
and the read-only part of the **User Agent API v3** (mailbox).

🇵🇱 Polska wersja: [README.pl.md](README.pl.md)

> **Status: 0.2 (pre-release).** Built and tested against the official specifications only; **not yet verified against
> the live INT or PROD environments**. Not affiliated with, endorsed or certified by the Ministry of Digital Affairs, COI or
> Poczta Polska. Not production-ready.

## What it does

| Area | Support |
|---|---|
| Authentication | OIDC `client_credentials` with signed JWT client assertion (RS256), token reuse per the official rules, optional PSR-16 sharing between processes |
| Search Engine API v4 (contract 4.0.5) | search by holder data or ADE (`bae_search`), bulk lookup of up to 1000 ADE (`eda-search`), own address data (`eda-confirmation`) |
| User Agent API v3 (contract 3.0.8) | read-only: countries, folders and counts, settings, message list (`minimal`/`metadata`), threads, evidences (lists and streamed XML/PDF downloads). Reading, sending and deleting messages are planned |
| Environments | production and integration (INT), selected explicitly — there is no default |
| HTTP | any PSR-18 client (Guzzle, Symfony HttpClient, …); no framework dependency |

## Requirements

- PHP 8.3, 8.4 or 8.5 with `ext-json`, `ext-mbstring`, `ext-openssl`
- a PSR-18 HTTP client and PSR-17 factories
- a system registered in an e-Doręczenia mailbox (see *Onboarding*)

## Installation

```bash
composer require b44x/edoreczenia guzzlehttp/guzzle
```

## Quick start

```php
use B4x\Edoreczenia\Auth\SystemCredentials;
use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\Http\RetryPolicy;
use B4x\Edoreczenia\SearchEngine\V4\Enum\OfficialIdType;
use B4x\Edoreczenia\SearchEngine\V4\Enum\SearchCategory;
use B4x\Edoreczenia\SearchEngine\V4\Request\AdvancedSearch;

$factory = new GuzzleHttp\Psr7\HttpFactory();

$client = EdoreczeniaClient::withSystemCredentials(
    Environment::integration(),                       // or Environment::production()
    SystemCredentials::fromPemFile(
        'AE:PL-12345-67890-ABCDE-12',                 // your mailbox address (ADE)
        'EZD1',                                        // system name from the permission module
        '/run/secrets/edoreczenia.key',               // RSA private key (PEM)
    ),
    new GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10]),
    $factory,
    $factory,
    retryPolicy: RetryPolicy::exponential(maxRetries: 2), // optional; SE queries are read-only
);

$result = $client->searchEngine()->search(
    AdvancedSearch::inCategories(SearchCategory::PublicInstitution, SearchCategory::Company)
        ->withOfficialId(OfficialIdType::Nip, '5250007313'),
);

foreach ($result->items as $item) {
    echo $item->address->address, ' ', $item->current()?->displayName(), PHP_EOL;
    if ($item->address->hasBlockingWarning()) {
        // e.g. SEAPI_INFO-00004: the address is inactive, a message cannot be sent
    }
}
```

More: [`examples/search-engine.php`](examples/search-engine.php).

### Other lookups

```php
$se = $client->searchEngine();

$se->search(AdvancedSearch::byDeliveryAddresses('AE:PL-…', 'AE:PL-…'));       // known addresses only
$se->search(
    AdvancedSearch::inCategories(SearchCategory::Individual)
        ->withName('ADAM')->withSurname('MALINOWSKI')
        ->withPostalAddress(new PostalAddress('PL', 'WARSZAWA', street: 'POLNA', buildingNumber: '12AA'))
        ->withPagination(offset: 0, limit: 50),
);
$se->findByAddresses($listOfUpTo1000Addresses, limit: 1000);                   // bulk validation
$se->getOwnAddressDetails();                                                    // data of your own ADE
```

Which attribute combinations each category requires (rule R.SEAPI.01) is enforced by the server; the SDK validates
contract-level constraints (lengths, counts, page size) before sending.

### Mailbox (UA API v3, read-only)

```php
use B4x\Edoreczenia\UserAgent\V3\Enum\ListFormat;
use B4x\Edoreczenia\UserAgent\V3\Request\MessageQuery;

$ua = $client->userAgent();

foreach ($ua->directoriesWithCounts() as $folder) {
    echo $folder->label, ': ', $folder->unreadMessages, '/', $folder->totalMessages, PHP_EOL;
}

$list = $ua->listMessages(
    MessageQuery::create()
        ->withFormat(ListFormat::Metadata)
        ->inFolder('INBOX')
        ->onlyOpened(false)
        ->withPagination(offset: 0, limit: 50),
);

foreach ($list->messages as $message) {
    echo $message->messageId, ' ', $message->subject, ' ', $message->from?->displayName(), PHP_EOL;
}
```

Threads work the same way: `$ua->listThreads(ThreadQuery::create()->withCaseId('…'))` and
`$ua->getThread($threadId)` return messages as a list, without opening them.

Evidences (proof of submission, delivery, receipt) are listed and downloaded as streams, so large files never
sit in memory:

```php
use B4x\Edoreczenia\UserAgent\V3\Enum\EvidenceService;
use B4x\Edoreczenia\UserAgent\V3\Enum\EvidenceType;
use B4x\Edoreczenia\UserAgent\V3\Request\EvidenceQuery;

foreach ($ua->messageEvidences($messageId, EvidenceQuery::create()->ofType(EvidenceType::ReceiptConfirmationXml))->evidences as $evidence) {
    $file = $ua->downloadEvidence(EvidenceService::Purde, $evidence->evidenceId);
    $file->saveTo('/archive/' . $evidence->evidenceId . '.xml');   // keep the original XML unchanged
}
```

Listing never opens messages. Opening a message (fetching it in `full` mode) marks it as read and, for non-public
recipients, triggers delivery evidence with legal effect — that operation will get its own, explicitly named method.

## Error handling

```php
use B4x\Edoreczenia\Exception\{EdoreczeniaException, NotFoundException, BadRequestException, TransportException};
use B4x\Edoreczenia\SearchEngine\V4\ErrorCode;

try {
    $result = $client->searchEngine()->search($criteria);
} catch (NotFoundException $e) {
    if ($e->hasErrorCode(ErrorCode::NO_RESULTS)) { /* nothing found */ }
} catch (BadRequestException $e) {
    // $e->getErrors() → list of ApiError(code, message, language), $e->getEventId() for incident reports
} catch (TransportException $e) {
    // network / TLS / timeout
} catch (EdoreczeniaException $e) {
    // anything else from the SDK
}
```

| Exception | When |
|---|---|
| `InvalidArgumentException` | invalid input, detected before any request |
| `TransportException` | no HTTP response (DNS, connection, TLS, timeout) |
| `BadRequestException`, `AuthenticationException`, `AuthorizationException`, `NotFoundException`, `RateLimitException`, `ServerException` (all `ApiException`) | HTTP 400/401/403/404/429/5xx with parsed error codes |
| `UnexpectedResponseException` | 2xx with a body that does not match the contract |

Enum-typed properties hold a raw `string` when the server returns a value this SDK version does not know yet,
so new values never break your code: `if ($address->status === EdaStatus::Active)`.

## Verification status — help wanted

Everything in this SDK is built from the official specifications; **no call has been verified against INT or PROD yet**.
Access to INT is granted to integrators and organisations, and the maintainer's request is pending.

If you have INT access, please run the read-only smoke test and open an
[INT verification report](https://github.com/b44x/edoreczenia-php/issues/new?template=int_report.md)
(status codes and error codes only — no personal data):

```bash
EDORECZENIA_INT_ADDRESS=AE:PL-… EDORECZENIA_INT_SYSTEM=EZD1 EDORECZENIA_INT_KEY_PATH=/path/key.pem \
  composer test:integration
```

## Configuration notes

- **Timeouts, proxies and TLS** belong to your PSR-18 client. Recommended: 10 s connect, 30 s total. The SDK never disables
  certificate verification.
- **Sharing tokens between PHP-FPM workers:** pass a PSR-16 cache as `tokenCache:`. It stores a live bearer token for up to
  300 s — use a private store.
- **Logging:** pass a PSR-3 logger. The SDK logs operation, endpoint path, status, timings, error codes and event ids — never
  headers, query strings, bodies, tokens or keys.
- **Retries** are off by default and, when enabled, apply only to read-only operations.

## Testing your integration

- Unit-test your code with any PSR-18 mock; or use `StaticTokenProvider` with the `EdoreczeniaClient` constructor to skip
  authentication.
- `Environment` accepts plain HTTP only for `localhost`/`127.0.0.1`, so you can point it at a local mock server.

## Onboarding (outside the SDK)

1. Get INT access: application to `test.edoreczenia@cyfra.gov.pl` (see the
   [EZD integration page](https://www.gov.pl/web/e-doreczenia/integracja-uslugi-e-doreczen-z-systemami-klasy-ezd)).
2. Generate an RSA 2048 key and a CSR (`openssl genrsa -out ezd.key 2048`, `openssl req -new -key ezd.key -out ezd.csr`).
3. The mailbox administrator adds the system in the permission module and uploads the CSR (choose "żądanie certyfikatu").
4. In PROD, non-public entities need a certificate from a qualified trust service provider.

## Development

```bash
composer install
composer check            # validate, coding style, PHPStan (max), unit tests
composer specs:fetch      # download official contracts (hash-verified) into build/specs/
composer test:contract    # validate fixtures and payloads against the official contracts
```

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## License

MIT — see [LICENSE](LICENSE). The official specifications are not included in this repository; `composer specs:fetch` downloads them for the contract tests.
