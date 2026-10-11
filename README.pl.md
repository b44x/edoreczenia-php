# e-Doręczenia – SDK dla PHP

[![Latest version](https://img.shields.io/packagist/v/b44x/edoreczenia?label=packagist)](https://packagist.org/packages/b44x/edoreczenia)
[![PHP](https://img.shields.io/packagist/dependency-v/b44x/edoreczenia/php)](https://packagist.org/packages/b44x/edoreczenia)
[![CI](https://github.com/b44x/edoreczenia-php/actions/workflows/ci.yml/badge.svg)](https://github.com/b44x/edoreczenia-php/actions/workflows/ci.yml)
[![PHPStan level max](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](phpstan.neon.dist)
[![License: MIT](https://img.shields.io/packagist/l/b44x/edoreczenia)](LICENSE)

Nieoficjalne, niezależne od frameworków SDK dla PHP do **e-Doręczeń** – publicznej usługi rejestrowanego doręczenia
elektronicznego. Implementuje udokumentowane **uwierzytelnienie system-system** (podpisany JWT, RFC 7523), **Search Engine API v4**
oraz **User Agent API v3** (skrzynka: odczyt, odbiór i wysyłka – dwa ostatnie eksperymentalnie).

🇬🇧 English version: [README.md](README.md)

> **Status: 0.5 (wersja przedpremierowa).** Zbudowane i przetestowane wyłącznie na podstawie oficjalnych specyfikacji;
> **jeszcze niezweryfikowane na żywym środowisku INT ani PROD**. Projekt nie jest powiązany z Ministerstwem Cyfryzacji,
> COI ani Pocztą Polską, nie jest przez nie zatwierdzony ani certyfikowany. Nie jest gotowy do produkcji.

## Co potrafi

| Obszar | Wsparcie |
|---|---|
| Uwierzytelnienie | OIDC `client_credentials` z asercją JWT podpisaną kluczem systemu (RS256), ponowne użycie tokenu zgodnie z instrukcją integracji, opcjonalne współdzielenie przez cache PSR-16 |
| Search Engine API v4 (kontrakt 4.0.5) | wyszukiwanie po danych podmiotu lub ADE (`bae_search`), masowa weryfikacja do 1000 ADE (`eda-search`), dane własnego adresu (`eda-confirmation`) |
| User Agent API v3 (kontrakt 3.0.8) | słownik krajów, foldery i liczniki, ustawienia, lista wiadomości, wątki, dowody (listy i strumieniowe pobieranie XML/PDF); **eksperymentalnie:** otwieranie wiadomości, strumieniowe pobieranie załączników, wysyłka (elektroniczna i hybrydowa/papierowa), zadania wysyłki, wersje robocze, archiwa i usuwanie wiadomości |
| Środowiska | produkcyjne i integracyjne (INT), wybierane jawnie – nie ma wartości domyślnej |
| HTTP | dowolny klient PSR-18 (Guzzle, Symfony HttpClient…), wykrywany automatycznie, jeśli go nie podasz; brak zależności od frameworka |
| Narzędzia | `vendor/bin/edoreczenia keygen` (klucz + CSR) i `doctor` (sprawdzenie konfiguracji od początku do końca); `FakeEdoreczenia` – atrapa usługi do testów |

## Wymagania

- PHP 8.3, 8.4 lub 8.5 z `ext-json`, `ext-mbstring`, `ext-openssl`
- klient HTTP PSR-18 z fabrykami PSR-17 – Guzzle lub Symfony HttpClient zostanie wykryty automatycznie
- system dodany w module uprawnień skrzynki do e-Doręczeń (zob. *Przygotowanie*)

## Instalacja

```bash
composer require b44x/edoreczenia
```

SDK komunikuje się przez PSR-18 i samo nie instaluje klienta HTTP. Jeśli projekt ma już Guzzle (Laravel, większość
frameworków) albo `symfony/http-client` z `nyholm/psr7` (Symfony), nic więcej nie trzeba. W przeciwnym razie dodaj:

```bash
composer require guzzlehttp/guzzle
```

## Szybki start

```php
use B4x\Edoreczenia\Auth\SystemCredentials;
use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\SearchEngine\V4\Enum\OfficialIdType;
use B4x\Edoreczenia\SearchEngine\V4\Enum\SearchCategory;
use B4x\Edoreczenia\SearchEngine\V4\Request\AdvancedSearch;

$client = EdoreczeniaClient::withSystemCredentials(
    Environment::integration(),                    // albo Environment::production()
    SystemCredentials::fromPemFile(
        'AE:PL-12345-67890-ABCDE-12',              // adres do e-Doręczeń skrzynki (ADE)
        'EZD1',                                     // nazwa systemu nadana w module uprawnień
        '/run/secrets/edoreczenia.key',            // klucz prywatny RSA (PEM)
    ),
);

$wynik = $client->searchEngine()->search(
    AdvancedSearch::inCategories(SearchCategory::PublicInstitution, SearchCategory::Company)
        ->withOfficialId(OfficialIdType::Nip, '5250007313'),
);

foreach ($wynik->items as $pozycja) {
    echo $pozycja->address->address, ' ', $pozycja->current()?->displayName(), PHP_EOL;
}
```

Klient HTTP zostanie wykryty (najpierw Guzzle, potem Symfony HttpClient) z limitem 30 s łącznie i 10 s na połączenie.
Żeby samodzielnie ustawić timeouty, proxy lub TLS, przekaż własnego klienta i fabryki:

```php
$factory = new GuzzleHttp\Psr7\HttpFactory();
$client = EdoreczeniaClient::withSystemCredentials($srodowisko, $poswiadczenia,
    new GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10, 'proxy' => 'http://proxy:3128']), $factory, $factory);
```

Wyszukiwanie bez wyników kończy się wyjątkiem `NotFoundException` z kodem `SEAPI-00010`
(`$e->hasErrorCode(ErrorCode::NO_RESULTS)`) – tak definiuje to kontrakt SE API.

Reguły dotyczące wymaganych kombinacji atrybutów dla kategorii wyszukiwania (R.SEAPI.01) egzekwuje serwer;
SDK sprawdza przed wysłaniem ograniczenia z kontraktu (długości, liczności, rozmiar strony).

### Skrzynka (UA API v3)

```php
use B4x\Edoreczenia\UserAgent\V3\Enum\ListFormat;
use B4x\Edoreczenia\UserAgent\V3\Request\MessageQuery;

$lista = $client->userAgent()->listMessages(
    MessageQuery::create()->withFormat(ListFormat::Metadata)->inFolder('INBOX')->onlyOpened(false),
);

foreach ($lista->messages as $wiadomosc) {
    echo $wiadomosc->messageId, ' ', $wiadomosc->subject, PHP_EOL;
}
```

Dowody (nadania, doręczenia, otrzymania) pobierasz jako strumienie – duże pliki nie trafiają w całości do pamięci:

```php
$plik = $client->userAgent()->downloadEvidence(EvidenceService::Purde, $dowod->evidenceId);
$plik->saveTo('/archiwum/' . $dowod->evidenceId . '.xml');   // oryginał XML archiwizuj bez zmian
```

Pobranie listy nie otwiera wiadomości. Otwieranie, pobieranie załączników i wysyłka – poniżej.

### Odbiór i wysyłka wiadomości (eksperymentalne, skutek prawny)

> **Eksperymentalne – jeszcze niezweryfikowane na INT ani PROD.** Te operacje wywołują **skutki prawne**. Są zgodne
> z oficjalnym kontraktem, pokryte testami jednostkowymi i kontraktowymi i nigdy nie są ponawiane automatycznie,
> ale żadne wywołanie nie trafiło jeszcze do prawdziwego serwera. Sprawdź je najpierw na INT i zgłoś wynik.

**Otwarcie wiadomości** oznacza ją jako otwartą, a u odbiorców niepublicznych uruchamia jej przekazanie i dowód
doręczenia E.1 – wiadomość uznaje się za doręczoną. Do przeglądania bez tego skutku służy `listMessages()`.

```php
use B4x\Edoreczenia\UserAgent\V3\Model\MessagePending;

$wiadomosc = $client->userAgent()->openMessage($messageId);
if ($wiadomosc instanceof MessagePending) {
    return;                                     // HTTP 202: trwa przekazanie, otwórz ponownie później
}

foreach ($wiadomosc->attachments as $zalacznik) {
    $client->userAgent()->downloadAttachment($messageId, (string) $zalacznik->attachmentId)   // strumieniowo
        ->saveTo('/bezpieczny/katalog/' . ($zalacznik->safeFileName() ?? $zalacznik->attachmentId));
}
```

SDK otwiera jedną wiadomość na wywołanie, choć kontrakt pozwala na 50 naraz – literówka nie „doręczy” kilkudziesięciu pism.

**Wysyłka** jest asynchroniczna: API przyjmuje wiadomość (HTTP 202) i zwraca zadanie, którego stan się odpytuje.

```php
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingAttachment;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingMessage;

$zadanie = $client->userAgent()->sendMessage(
    OutgoingMessage::create('Wniosek o informację')
        ->withRecipients('AE:PL-98765-43210-SFVYC-19')
        ->withTextBody('Treść wiadomości')
        ->withAttachment(OutgoingAttachment::fromStream($pdf, 'wniosek.pdf', 'application/pdf')),
);
// następnie messageTaskStatus($zadanie->messageTaskId) aż do MessageTaskState::Finished
// i messageTask($zadanie->messageTaskId) – wynik dla każdego adresata
```

- `sendMessage()` i `sendDraft()` **nigdy nie są ponawiane**, nawet przy włączonej polityce ponowień – API nie ma
  klucza idempotencji. Po przekroczeniu czasu lub błędzie 5xx sprawdź folder SENT, zanim wyślesz ponownie.
- Adresatów sprawdź wcześniej przez `searchEngine()->findByAddresses([...])`.
- `FINISHED` oznacza przetworzenie zadania, a nie doręczenie – sprawdź wynik każdego adresata.
- Załączniki w treści żądania: do 15 MB. Większe pliki dodaj do wersji roboczej przez `uploadDraftAttachment()` (strumieniowo).
- Wersje robocze: `createDraft()`, `getDraft()`, `listDrafts()`, `updateDraft()`, `deleteDrafts()`, `addDraftAttachment()`,
  `uploadDraftAttachment()`, `deleteDraftAttachment()`, `deleteDraftAttachments()`, `sendDraft()`.

Gotowe do uruchomienia: [`examples/receive.php`](examples/receive.php) i [`examples/send.php`](examples/send.php) działają
od razu na `FakeEdoreczenia`; ze zmienną `EDORECZENIA_ENV=int` łączą się z INT i pytają o zgodę przed każdą operacją ze skutkiem prawnym.

### Przesyłki hybrydowe (papierowe, eksperymentalnie)

Publiczna usługa hybrydowa (PUH) drukuje wiadomość i doręcza ją w postaci papierowej. Adresata wskazujesz adresem pocztowym:

```php
$przesylka = HybridShipment::create(
    ShippingMode::Registered, CategoryService::Economic, ProceedingType::Administrative,
    ReturnService::ReturnToSender, PrintingMode::Duplex, OverprintMode::Monochrome,
)->withProofOfDelivery()->withSendersInformation('Urząd Gminy');

$zadanie = $client->userAgent()->sendMessage(
    OutgoingMessage::create('Decyzja')
        ->withPaperRecipients(PaperRecipient::at(
            PaperAddress::create('PL', '05-569', 'Warszawa', 'Polna')->withBuildingNumber('12A'),
        )->withPerson('Adam', 'Malinowski'))
        ->withHybridShipment($przesylka)
        ->withAttachment(OutgoingAttachment::fromString($pdf, 'decyzja.pdf', 'application/pdf')),
);
```

SDK sprawdza pola wymagane przez kontrakt, limity długości i regułę dla `caseContent`; dozwolone kombinacje opcji weryfikuje serwer.

### Archiwa i usuwanie wiadomości (eksperymentalnie)

```php
$ua = $client->userAgent();
$taskId = $ua->createArchiveTask(ArchiveTaskRequest::forMessages($id1, $id2)->withDescription('Kwiecień 2026'));
for ($i = 0; $i < 60 && !($ua->archiveTask($taskId)?->isReadyToDownload() ?? false); ++$i) {
    sleep(30);
}
$ua->downloadArchive($taskId)->saveTo('/archiwum/kwiecien.zip');  // ZIP strumieniowo
$ua->finishArchiveTask($taskId);                                  // wiadomości zostają
// $ua->finishArchiveTaskAndDeleteMessages($taskId);              // NIEODWRACALNE

$ua->downloadMessageArchive($id1)->saveTo('/archiwum/jedna.zip'); // pojedyncza wiadomość
$ua->deleteMessages($id1, $id2);                                  // NIEODWRACALNE, do 50 id, bez ponawiania
```

Kontrakt nie określa, czy usunięcie jest trwałe, czy przenosi wiadomość do kosza, ani czy usunięcie lub archiwizacja
nieotwartej wiadomości ją otwiera. Przed usunięciem zarchiwizuj to, co musisz zachować.

## Status weryfikacji – szukamy pomocy

Całe SDK powstało na podstawie oficjalnych specyfikacji; **żadne wywołanie nie zostało jeszcze sprawdzone na INT ani PROD**.
Dostęp do środowiska INT przyznawany jest integratorom i organizacjom – zapytanie autora projektu czeka na odpowiedź.

Masz dostęp do INT? Uruchom bezpieczny test tylko-do-odczytu (`composer test:integration` ze zmiennymi `EDORECZENIA_INT_*`)
i zgłoś wynik przez szablon [INT verification report](https://github.com/b44x/edoreczenia-php/issues/new?template=int_report.md)
– wystarczą kody HTTP i kody błędów, bez danych osobowych.

## Ważne zasady

- **Timeouty, proxy i TLS** konfigurujesz w swoim kliencie PSR-18 (zalecane: 10 s na połączenie, 30 s łącznie –
  takich wartości używa klient wykryty automatycznie).
  SDK nigdy nie wyłącza weryfikacji certyfikatów.
- **Token** ważny jest 300 s i jest używany ponownie do 30 s przed wygaśnięciem, zgodnie z zaleceniem instrukcji
  integracji („nie pobieraj nowego tokenu, gdy poprzedni jest ważny”). Między procesami PHP-FPM można go współdzielić
  przez cache PSR-16 (`tokenCache:`) – to aktywne poświadczenie, używaj prywatnego magazynu.
- **Logi** (PSR-3) nie zawierają nagłówków, query stringów, treści żądań i odpowiedzi, tokenów ani kluczy –
  tylko operację, ścieżkę, status, czasy, kody błędów i identyfikator zdarzenia (przydatny do zgłoszeń w ITSM Atmosfera).
- **Ponawianie** jest domyślnie wyłączone; po włączeniu (`RetryPolicy::exponential()`) obejmuje tylko operacje
  tylko-do-odczytu. Otwieranie, wysyłka i usuwanie wiadomości, zmiany wersji roboczych i zadań archiwizacji nigdy nie są ponawiane automatycznie.
- Nieznane SDK wartości enumów są zwracane jako `string`, więc nowe wartości po stronie serwera nie psują aplikacji.

## Testowanie integracji

`FakeEdoreczenia` to działający w pamięci klient PSR-18, który odpowiada jak serwer tokenów, SE API v4 i UA API v3
(łącznie z otwieraniem, wysyłką i wersjami roboczymi; `sentMessages()` zwraca wysłane wiadomości) – na **danych syntetycznych**, bez dostępu do sieci:

```php
use B4x\Edoreczenia\Testing\FakeEdoreczenia;
use Nyholm\Psr7\Factory\Psr17Factory;

$factory = new Psr17Factory();                       // lub dowolna fabryka PSR-17, np. GuzzleHttp\Psr7\HttpFactory
$fake    = new FakeEdoreczenia($factory, $factory);
$client  = $fake->createClient();                     // skrzynka AE:PL-12345-67890-ABCDE-12, adresy INT

$client->searchEngine()->getOwnAddressDetails();
$client->userAgent()->listMessages();

// Jednorazowa symulacja błędu, np. przeciążonego SE API:
$fake->failNext('search/eda-search', 504, ['httpCode' => '504', 'id' => 'x',
    'errorCodes' => [['errorCode' => 'SEAPI-00013', 'language' => 'GB', 'message' => 'Overloaded']]]);

$fake->requests();                                    // wszystkie żądania wysłane przez SDK – do asercji
```

Atrapa obsługuje też pełny przepływ z podpisanym JWT: `EdoreczeniaClient::withSystemCredentials(Environment::integration(), $poswiadczenia, $fake)`
(podpisy nie są weryfikowane). Atrapa trzyma się oficjalnych kontraktów, ale nie jest prawdziwą usługą – przed produkcją
sprawdź integrację na INT.

## Przygotowanie (poza SDK)

1. Dostęp do środowiska INT: zgłoszenie na `test.edoreczenia@cyfra.gov.pl` podpisane kwalifikowanym podpisem lub
   podpisem zaufanym (zob. [Integracja z systemami klasy EZD](https://www.gov.pl/web/e-doreczenia/integracja-uslugi-e-doreczen-z-systemami-klasy-ezd)).
2. Klucz RSA 2048 i CSR:
   ```bash
   vendor/bin/edoreczenia keygen --out-dir=/bezpieczny/katalog --state=Mazowieckie --locality=Warszawa \
     --organization="NAZWA SPOLKI" --common-name=ezd.example.pl
   ```
   Klucz zapisywany jest z uprawnieniami 0600 i nigdy nie jest nadpisywany, a CSR jest sprawdzany względem klucza.
   (Można też użyć `openssl genrsa` i `openssl req -new`.)
3. Administrator skrzynki dodaje system w module uprawnień i wgrywa plik CSR (opcja „żądanie certyfikatu”).
4. Sprawdzenie całej konfiguracji – klucz, client id, token, przesunięcie zegara i jedno wywołanie tylko-do-odczytu:
   ```bash
   vendor/bin/edoreczenia doctor --env=int --address=AE:PL-12345-67890-ABCDE-12 --system=EZD1 --key=/bezpieczny/katalog/ezd.key
   ```
   W razie błędu wypisuje raport incydentu (endpoint, status HTTP, kody błędów, identyfikator zdarzenia) bez tokenu
   i klucza – można go dołączyć do zgłoszenia serwisowego.
5. Na produkcji podmioty niepubliczne potrzebują certyfikatu od kwalifikowanego dostawcy usług zaufania.

## Rozwój

```bash
composer install
composer check            # walidacja composer.json, styl kodu, PHPStan (max), testy jednostkowe
composer specs:fetch      # pobranie oficjalnych kontraktów (z weryfikacją SHA-256) do build/specs/
composer test:contract    # walidacja fikstur i żądań względem oficjalnych kontraktów
```

## Licencja

MIT – zob. [LICENSE](LICENSE). Oficjalne specyfikacje nie są dołączone do repozytorium; `composer specs:fetch` pobiera je na potrzeby testów kontraktowych.
