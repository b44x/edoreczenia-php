# e-Doręczenia – SDK dla PHP

Nieoficjalne, niezależne od frameworków SDK dla PHP do **e-Doręczeń** – publicznej usługi rejestrowanego doręczenia
elektronicznego. Implementuje udokumentowane **uwierzytelnienie system-system** (podpisany JWT, RFC 7523), **Search Engine API v4**
oraz część tylko-do-odczytu **User Agent API v3** (skrzynka).

🇬🇧 English version: [README.md](README.md)

> **Status: 0.2 (wersja przedpremierowa).** Zbudowane i przetestowane wyłącznie na podstawie oficjalnych specyfikacji;
> **jeszcze niezweryfikowane na żywym środowisku INT ani PROD**. Projekt nie jest powiązany z Ministerstwem Cyfryzacji,
> COI ani Pocztą Polską, nie jest przez nie zatwierdzony ani certyfikowany. Nie jest gotowy do produkcji.

## Co potrafi

| Obszar | Wsparcie |
|---|---|
| Uwierzytelnienie | OIDC `client_credentials` z asercją JWT podpisaną kluczem systemu (RS256), ponowne użycie tokenu zgodnie z instrukcją integracji, opcjonalne współdzielenie przez cache PSR-16 |
| Search Engine API v4 (kontrakt 4.0.5) | wyszukiwanie po danych podmiotu lub ADE (`bae_search`), masowa weryfikacja do 1000 ADE (`eda-search`), dane własnego adresu (`eda-confirmation`) |
| User Agent API v3 (kontrakt 3.0.8) | tylko odczyt: słownik krajów, foldery i liczniki, ustawienia, lista wiadomości (`minimal`/`metadata`), wątki, dowody (listy i strumieniowe pobieranie XML/PDF). Odbiór, wysyłka i usuwanie – w planie |
| Środowiska | produkcyjne i integracyjne (INT), wybierane jawnie – nie ma wartości domyślnej |
| HTTP | dowolny klient PSR-18 (Guzzle, Symfony HttpClient…); brak zależności od frameworka |

## Wymagania

- PHP 8.3, 8.4 lub 8.5 z `ext-json`, `ext-mbstring`, `ext-openssl`
- klient HTTP PSR-18 i fabryki PSR-17
- system dodany w module uprawnień skrzynki do e-Doręczeń (zob. *Przygotowanie*)

## Instalacja

```bash
composer require b44x/edoreczenia guzzlehttp/guzzle
```

## Szybki start

```php
use B4x\Edoreczenia\Auth\SystemCredentials;
use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\SearchEngine\V4\Enum\OfficialIdType;
use B4x\Edoreczenia\SearchEngine\V4\Enum\SearchCategory;
use B4x\Edoreczenia\SearchEngine\V4\Request\AdvancedSearch;

$factory = new GuzzleHttp\Psr7\HttpFactory();

$client = EdoreczeniaClient::withSystemCredentials(
    Environment::integration(),                    // albo Environment::production()
    SystemCredentials::fromPemFile(
        'AE:PL-12345-67890-ABCDE-12',              // adres do e-Doręczeń skrzynki (ADE)
        'EZD1',                                     // nazwa systemu nadana w module uprawnień
        '/run/secrets/edoreczenia.key',            // klucz prywatny RSA (PEM)
    ),
    new GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10]),
    $factory,
    $factory,
);

$wynik = $client->searchEngine()->search(
    AdvancedSearch::inCategories(SearchCategory::PublicInstitution, SearchCategory::Company)
        ->withOfficialId(OfficialIdType::Nip, '5250007313'),
);

foreach ($wynik->items as $pozycja) {
    echo $pozycja->address->address, ' ', $pozycja->current()?->displayName(), PHP_EOL;
}
```

Wyszukiwanie bez wyników kończy się wyjątkiem `NotFoundException` z kodem `SEAPI-00010`
(`$e->hasErrorCode(ErrorCode::NO_RESULTS)`) – tak definiuje to kontrakt SE API.

Reguły dotyczące wymaganych kombinacji atrybutów dla kategorii wyszukiwania (R.SEAPI.01) egzekwuje serwer;
SDK sprawdza przed wysłaniem ograniczenia z kontraktu (długości, liczności, rozmiar strony).

### Skrzynka (UA API v3, tylko odczyt)

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

Pobranie listy nie otwiera wiadomości. Otwarcie wiadomości (pobranie w trybie `full`) oznacza ją jako odczytaną,
a u adresatów niepublicznych uruchamia wystawienie dowodu doręczenia ze skutkiem prawnym – ta operacja dostanie
osobną, jednoznacznie nazwaną metodę.

## Status weryfikacji – szukamy pomocy

Całe SDK powstało na podstawie oficjalnych specyfikacji; **żadne wywołanie nie zostało jeszcze sprawdzone na INT ani PROD**.
Dostęp do środowiska INT przyznawany jest integratorom i organizacjom – zapytanie autora projektu czeka na odpowiedź.

Masz dostęp do INT? Uruchom bezpieczny test tylko-do-odczytu (`composer test:integration` ze zmiennymi `EDORECZENIA_INT_*`)
i zgłoś wynik przez szablon [INT verification report](https://github.com/b44x/edoreczenia-php/issues/new?template=int_report.md)
– wystarczą kody HTTP i kody błędów, bez danych osobowych.

## Ważne zasady

- **Timeouty, proxy i TLS** konfigurujesz w swoim kliencie PSR-18 (zalecane: 10 s na połączenie, 30 s łącznie).
  SDK nigdy nie wyłącza weryfikacji certyfikatów.
- **Token** ważny jest 300 s i jest używany ponownie do 30 s przed wygaśnięciem, zgodnie z zaleceniem instrukcji
  integracji („nie pobieraj nowego tokenu, gdy poprzedni jest ważny”). Między procesami PHP-FPM można go współdzielić
  przez cache PSR-16 (`tokenCache:`) – to aktywne poświadczenie, używaj prywatnego magazynu.
- **Logi** (PSR-3) nie zawierają nagłówków, query stringów, treści żądań i odpowiedzi, tokenów ani kluczy –
  tylko operację, ścieżkę, status, czasy, kody błędów i identyfikator zdarzenia (przydatny do zgłoszeń w ITSM Atmosfera).
- **Ponawianie** jest domyślnie wyłączone; po włączeniu (`RetryPolicy::exponential()`) obejmuje tylko operacje
  tylko-do-odczytu. Wysyłka i odczyt wiadomości w UA API (skutki prawne) nigdy nie będą ponawiane automatycznie.
- Nieznane SDK wartości enumów są zwracane jako `string`, więc nowe wartości po stronie serwera nie psują aplikacji.

## Przygotowanie (poza SDK)

1. Dostęp do środowiska INT: zgłoszenie na `test.edoreczenia@cyfra.gov.pl` podpisane kwalifikowanym podpisem lub
   podpisem zaufanym (zob. [Integracja z systemami klasy EZD](https://www.gov.pl/web/e-doreczenia/integracja-uslugi-e-doreczen-z-systemami-klasy-ezd)).
2. Klucz i CSR: `openssl genrsa -out ezd.key 2048`, `openssl req -new -key ezd.key -out ezd.csr`.
3. Administrator skrzynki dodaje system w module uprawnień i wgrywa plik CSR (opcja „żądanie certyfikatu”).
4. Na produkcji podmioty niepubliczne potrzebują certyfikatu od kwalifikowanego dostawcy usług zaufania.

## Rozwój

```bash
composer install
composer check            # walidacja composer.json, styl kodu, PHPStan (max), testy jednostkowe
composer specs:fetch      # pobranie oficjalnych kontraktów (z weryfikacją SHA-256) do build/specs/
composer test:contract    # walidacja fikstur i żądań względem oficjalnych kontraktów
```

## Licencja

MIT – zob. [LICENSE](LICENSE). Oficjalne specyfikacje nie są dołączone do repozytorium; `composer specs:fetch` pobiera je na potrzeby testów kontraktowych.
