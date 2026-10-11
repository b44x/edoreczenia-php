# e-Doręczenia PHP SDK

[![Latest version](https://img.shields.io/packagist/v/b44x/edoreczenia?label=packagist)](https://packagist.org/packages/b44x/edoreczenia)
[![PHP](https://img.shields.io/packagist/dependency-v/b44x/edoreczenia/php)](https://packagist.org/packages/b44x/edoreczenia)
[![CI](https://github.com/b44x/edoreczenia-php/actions/workflows/ci.yml/badge.svg)](https://github.com/b44x/edoreczenia-php/actions/workflows/ci.yml)
[![PHPStan level max](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](phpstan.neon.dist)
[![License: MIT](https://img.shields.io/packagist/l/b44x/edoreczenia)](LICENSE)

Unofficial, framework-agnostic PHP SDK for **e-Doręczenia**, Poland's public electronic registered delivery service.
It implements the documented **system-to-system authentication** (signed JWT, RFC 7523), the **Search Engine API v4**
and the **User Agent API v3** (mailbox: reading, receiving and sending — the last two experimental).

🇵🇱 Polska wersja: [README.pl.md](README.pl.md)

> **Status: 0.4 (pre-release).** Built and tested against the official specifications only; **not yet verified against
> the live INT or PROD environments**. Not affiliated with, endorsed or certified by the Ministry of Digital Affairs, COI or
> Poczta Polska. Not production-ready.

## What it does

| Area | Support |
|---|---|
| Authentication | OIDC `client_credentials` with signed JWT client assertion (RS256), token reuse per the official rules, optional PSR-16 sharing between processes |
| Search Engine API v4 (contract 4.0.5) | search by holder data or ADE (`bae_search`), bulk lookup of up to 1000 ADE (`eda-search`), own address data (`eda-confirmation`) |
| User Agent API v3 (contract 3.0.8) | countries, folders and counts, settings, message list, threads, evidences (lists and streamed XML/PDF downloads); **experimental:** opening messages, streamed attachment downloads, sending, send tasks and drafts. Hybrid (paper) shipments and deleting messages are planned |
| Environments | production and integration (INT), selected explicitly — there is no default |
| HTTP | any PSR-18 client (Guzzle, Symfony HttpClient, …), detected automatically when not passed; no framework dependency |
| Tooling | `vendor/bin/edoreczenia keygen` (key + CSR) and `doctor` (end-to-end configuration check); `FakeEdoreczenia` in-memory fake for your tests |

## Requirements

- PHP 8.3, 8.4 or 8.5 with `ext-json`, `ext-mbstring`, `ext-openssl`
- a PSR-18 HTTP client with PSR-17 factories — Guzzle or Symfony HttpClient is picked up automatically
- a system registered in an e-Doręczenia mailbox (see *Onboarding*)

## Installation

```bash
composer require b44x/edoreczenia
```

The SDK talks HTTP through PSR-18 and does not install an HTTP client itself. If your project already has Guzzle
(Laravel, most frameworks) or `symfony/http-client` with `nyholm/psr7` (Symfony), nothing else is needed. Otherwise add one:

```bash
composer require guzzlehttp/guzzle
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

$client = EdoreczeniaClient::withSystemCredentials(
    Environment::integration(),                       // or Environment::production()
    SystemCredentials::fromPemFile(
        'AE:PL-12345-67890-ABCDE-12',                 // your mailbox address (ADE)
        'EZD1',                                        // system name from the permission module
        '/run/secrets/edoreczenia.key',               // RSA private key (PEM)
    ),
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

The HTTP client is detected (Guzzle first, then Symfony HttpClient) with a 30 s total and 10 s connect timeout. To control
timeouts, proxies or TLS, pass your own client and factories:

```php
$factory = new GuzzleHttp\Psr7\HttpFactory();
$client = EdoreczeniaClient::withSystemCredentials($environment, $credentials,
    new GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10, 'proxy' => 'http://proxy:3128']), $factory, $factory);
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

### Mailbox (UA API v3)

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

Listing never opens messages. Opening, receiving attachments and sending are described below.

### Receiving and sending messages (experimental, legal effect)

> **Experimental — not yet verified against INT or PROD.** These operations have **legal effect**. They follow the
> official contract, are covered by unit and contract tests, and are never retried automatically, but no call has
> reached a real server yet. Try them on INT first and report the result.

**Opening a message** marks it as opened and, for non-public recipients, triggers its transfer and the E.1 delivery
evidence: the message counts as delivered. Use `listMessages()` to browse without this effect.

```php
use B4x\Edoreczenia\UserAgent\V3\Model\MessagePending;

$opened = $ua->openMessage($messageId);
if ($opened instanceof MessagePending) {
    return;                                     // HTTP 202: transfer in progress, open it again later
}

echo $opened->metadata->subject, PHP_EOL, $opened->textBody, PHP_EOL;
foreach ($opened->attachments as $attachment) {
    $ua->downloadAttachment($messageId, (string) $attachment->attachmentId)   // streamed, not buffered
        ->saveTo('/secure/dir/' . ($attachment->safeFileName() ?? $attachment->attachmentId));
}
```

The SDK opens one message per call, although the contract allows up to 50 ids at once, so a typo cannot deliver
dozens of messages.

**Sending** is asynchronous: the API accepts the message (HTTP 202) and returns a task to poll.

```php
use B4x\Edoreczenia\UserAgent\V3\Enum\MessageTaskState;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingAttachment;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingMessage;

$client->searchEngine()->findByAddresses(['AE:PL-98765-43210-SFVYC-19']);   // check recipients first

$task = $ua->sendMessage(
    OutgoingMessage::create('Wniosek o informację')
        ->withRecipients('AE:PL-98765-43210-SFVYC-19')
        ->withTextBody('Treść wiadomości')
        ->withAttachment(OutgoingAttachment::fromStream($pdfStream, 'wniosek.pdf', 'application/pdf')),
);

for ($i = 0; $task->messageTaskId !== null && $i < 30; ++$i) {
    if ($ua->messageTaskStatus($task->messageTaskId) === MessageTaskState::Finished) {
        foreach ($ua->messageTask($task->messageTaskId) as $result) {
            echo $result->recipientAddress, ': ', $result->isAccepted() ? $result->messageId : $result->error, PHP_EOL;
        }
        break;
    }
    sleep(2);
}
```

- `sendMessage()` and `sendDraft()` are **never retried**, even with a retry policy: the API has no idempotency key.
  After a timeout or a 5xx error, check the SENT folder before sending again.
- `FINISHED` means the server processed the task, not that the message was delivered; check each result.
- Inline attachments are limited to 15 MB. Larger files go into a draft with `uploadDraftAttachment()` (streamed).
- Drafts: `createDraft()`, `getDraft()`, `listDrafts()`, `updateDraft()`, `deleteDrafts()`, `addDraftAttachment()`,
  `uploadDraftAttachment()`, `deleteDraftAttachment()`, `deleteDraftAttachments()`, `sendDraft()`.
- Not supported yet: hybrid (paper) shipments, deleting received messages, archives.

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

- **Timeouts, proxies and TLS** belong to your PSR-18 client. Recommended: 10 s connect, 30 s total (the detected client
  uses these). The SDK never disables certificate verification.
- **Sharing tokens between PHP-FPM workers:** pass a PSR-16 cache as `tokenCache:`. It stores a live bearer token for up to
  300 s — use a private store.
- **Logging:** pass a PSR-3 logger. The SDK logs operation, endpoint path, status, timings, error codes and event ids — never
  headers, query strings, bodies, tokens or keys.
- **Retries** are off by default and, when enabled, apply only to read-only operations — never to opening, sending or
  changing drafts.

## Testing your integration

`FakeEdoreczenia` is an in-memory PSR-18 client that answers like the token endpoint, SE API v4 and UA API v3
(including opening, sending and drafts; `sentMessages()` returns what was sent), with **synthetic data** and without any network access:

```php
use B4x\Edoreczenia\Testing\FakeEdoreczenia;
use Nyholm\Psr7\Factory\Psr17Factory;

$factory = new Psr17Factory();                       // or any PSR-17 factory, e.g. GuzzleHttp\Psr7\HttpFactory
$fake    = new FakeEdoreczenia($factory, $factory);
$client  = $fake->createClient();                     // mailbox AE:PL-12345-67890-ABCDE-12, INT endpoints

$client->searchEngine()->getOwnAddressDetails();
$client->userAgent()->listMessages();

// Simulate one failure, e.g. an overloaded SE API:
$fake->failNext('search/eda-search', 504, ['httpCode' => '504', 'id' => 'x',
    'errorCodes' => [['errorCode' => 'SEAPI-00013', 'language' => 'GB', 'message' => 'Overloaded']]]);

$fake->requests();                                    // every request the SDK sent, for assertions
```

The real signed-JWT flow works against the fake too: `EdoreczeniaClient::withSystemCredentials(Environment::integration(), $credentials, $fake)`
(signatures are not verified). The fake follows the official contracts, but it is not the real service: verify on INT
before production.

Alternatively, use `StaticTokenProvider` with the `EdoreczeniaClient` constructor and any PSR-18 mock. `Environment` accepts
plain HTTP for `localhost`/`127.0.0.1`, so you can also point it at a local mock server.

## Onboarding (outside the SDK)

1. Get INT access: application to `test.edoreczenia@cyfra.gov.pl` (see the
   [EZD integration page](https://www.gov.pl/web/e-doreczenia/integracja-uslugi-e-doreczen-z-systemami-klasy-ezd)).
2. Generate an RSA 2048 key and a CSR:
   ```bash
   vendor/bin/edoreczenia keygen --out-dir=/secure/dir --state=Mazowieckie --locality=Warszawa \
     --organization="NAZWA SPOLKI" --common-name=ezd.example.pl
   ```
   The key is written with mode 0600 and never overwritten; the CSR is checked against the key. (Plain `openssl genrsa`
   and `openssl req -new` work as well.)
3. The mailbox administrator adds the system in the permission module and uploads the CSR (choose "żądanie certyfikatu").
4. Check the whole setup — key, client id, token, clock skew and one read-only call:
   ```bash
   vendor/bin/edoreczenia doctor --env=int --address=AE:PL-12345-67890-ABCDE-12 --system=EZD1 --key=/secure/dir/ezd.key
   ```
   On failure it prints an incident report (endpoint, HTTP status, error codes, event id) that contains no token or key and
   can be attached to a service desk ticket.
5. In PROD, non-public entities need a certificate from a qualified trust service provider.

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
