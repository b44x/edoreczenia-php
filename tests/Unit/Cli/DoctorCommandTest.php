<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\Cli;

use B4x\Edoreczenia\Cli\Application;
use B4x\Edoreczenia\Http\HttpStack;
use B4x\Edoreczenia\Tests\Support\FrozenClock;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\Tests\Support\MockHttpClient;
use B4x\Edoreczenia\Tests\Support\NetworkError;
use B4x\Edoreczenia\Tests\Support\TestKeys;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class DoctorCommandTest extends TestCase
{
    private const ADDRESS = 'AE:PL-12345-67890-ABCDE-12';
    private const TOKEN = 'secret-access-token-value';
    private const TOKEN_ENDPOINT = 'https://int-ow.edoreczenia.gov.pl/auth/realms/EDOR/protocol/openid-connect/token';
    private const SERVER_DATE = 'Thu, 01 Oct 2026 12:00:02 GMT';

    private MockHttpClient $http;
    private string $keyPath;
    /** @var list<int> */
    private array $timeouts = [];

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->keyPath = (string) tempnam(sys_get_temp_dir(), 'edoreczenia-doctor-');
        file_put_contents($this->keyPath, TestKeys::rsa()['private']);
        $this->timeouts = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->keyPath);
    }

    public function testHappyPathRunsAllChecks(): void
    {
        $this->http->queue(
            Http::json(200, self::tokenBody(), ['Date' => self::SERVER_DATE]),
            Http::fixture(200, 'search-engine-v4/eda-confirmation.200.json'),
        );

        [$code, $out, $err] = $this->doctor();

        self::assertSame(0, $code, $out . $err);
        self::assertSame('', $err);
        self::assertStringContainsString('environment: integration', $out);
        self::assertStringContainsString('[OK]   Private key loaded: RSA 2048 bits', $out);
        self::assertStringContainsString('[OK]   Client id: ' . self::ADDRESS . '.SYSTEM.EZD1', $out);
        self::assertStringContainsString('[OK]   Access token obtained, expires_in=300 s', $out);
        self::assertStringContainsString('[OK]   Clock: local time differs from the server Date header by -2 s (local 2026-10-01 12:00:00 UTC)', $out);
        self::assertStringContainsString('[OK]   Address status: ACTIVE, main address', $out);
        self::assertStringContainsString('All checks passed.', $out);
        self::assertStringNotContainsString('Incident report', $out);
        self::assertSame([30], $this->timeouts);

        self::assertCount(2, $this->http->requests);
        self::assertStringStartsWith(self::TOKEN_ENDPOINT . '?login_hint=', (string) $this->http->requests[0]->getUri());
        $call = $this->http->requests[1];
        self::assertSame('POST', $call->getMethod());
        self::assertSame('https://int-ow.edoreczenia.gov.pl/api/se/v4/search/eda-confirmation', (string) $call->getUri());
        self::assertSame('Bearer ' . self::TOKEN, $call->getHeaderLine('Authorization'));
        $this->assertNoSecrets($out);
    }

    public function testWarnsAboutClockSkew(): void
    {
        $this->http->queue(
            Http::json(200, self::tokenBody(), ['Date' => 'Thu, 01 Oct 2026 12:01:00 GMT']),
            Http::fixture(200, 'search-engine-v4/eda-confirmation.200.json'),
        );

        [$code, $out] = $this->doctor();

        self::assertSame(0, $code);
        self::assertStringContainsString('[WARN] Clock: local time differs from the server Date header by -60 s', $out);
        self::assertStringContainsString('NTP', $out);
    }

    public function testReminderWithoutDateHeader(): void
    {
        $this->http->queue(Http::tokenResponse(self::TOKEN), Http::fixture(200, 'search-engine-v4/eda-confirmation.200.json'));

        [$code, $out] = $this->doctor();

        self::assertSame(0, $code);
        self::assertStringContainsString('[WARN] Clock: no server Date header to compare with; local time is 2026-10-01 12:00:00 UTC', $out);
    }

    public function testWarnsAboutInactiveAddress(): void
    {
        $body = str_replace('"ACTIVE"', '"CLOSED_RECOVERABLE"', Http::fixtureBody('search-engine-v4/eda-confirmation.200.json'));
        $this->http->queue(Http::tokenResponse(self::TOKEN), Http::json(200, $body));

        [$code, $out] = $this->doctor();

        self::assertSame(0, $code);
        self::assertStringContainsString('[WARN] Address status: CLOSED_RECOVERABLE', $out);
    }

    public function testRejectedTokenRequestPrintsIncidentReport(): void
    {
        $this->http->queue(Http::json(
            401,
            ['error' => 'invalid_client', 'error_description' => 'Invalid client or Invalid client credentials'],
            ['Date' => self::SERVER_DATE],
        ));

        [$code, $out] = $this->doctor();

        self::assertSame(1, $code);
        self::assertStringContainsString('[FAIL] token request:', $out);
        self::assertStringContainsString('Incident report', $out);
        self::assertStringContainsString('  Time (UTC):  2026-10-01 12:00:00', $out);
        self::assertStringContainsString('  Environment: integration', $out);
        self::assertStringContainsString('  Operation:   token request', $out);
        self::assertStringContainsString('  Endpoint:    ' . self::TOKEN_ENDPOINT . "\n", $out);
        self::assertStringContainsString('  HTTP status: 401', $out);
        self::assertStringContainsString('  Error codes: invalid_client', $out);
        self::assertStringContainsString('  Event id:    -', $out);
        self::assertStringContainsString('  Exception:   B4x\Edoreczenia\Exception\AuthenticationException', $out);
        self::assertStringContainsString('[OK]   Clock:', $out, 'clock is checked even when the token is rejected');
        self::assertCount(1, $this->http->requests, 'no API call after a failed token request');
        $this->assertNoSecrets($out);
    }

    public function testApiErrorReportsEventId(): void
    {
        $this->http->queue(Http::tokenResponse(self::TOKEN), Http::fixture(400, 'search-engine-v4/error.400.json'));

        [$code, $out] = $this->doctor();

        self::assertSame(1, $code);
        self::assertStringContainsString('[OK]   Access token obtained', $out);
        self::assertStringContainsString('[FAIL] SE API v4 eda-confirmation:', $out);
        self::assertStringContainsString('  Endpoint:    https://int-ow.edoreczenia.gov.pl/api/se/v4/search/eda-confirmation', $out);
        self::assertStringContainsString('  HTTP status: 400', $out);
        self::assertStringContainsString('  Error codes: SEAPI-00007', $out);
        self::assertStringContainsString('  Event id:    7770c1c4-e07b-479d-a496-a69eb9488366', $out);
        self::assertStringContainsString('  Exception:   B4x\Edoreczenia\Exception\BadRequestException', $out);
        $this->assertNoSecrets($out);
    }

    public function testNetworkErrorReportDropsQueryString(): void
    {
        $uri = self::TOKEN_ENDPOINT . '?login_hint=ADE.AE%3APL-12345-67890-ABCDE-12';
        $this->http->queue(new NetworkError(new Request('POST', $uri), 'cURL error 28: Operation timed out for ' . $uri));

        [$code, $out] = $this->doctor();

        self::assertSame(1, $code);
        self::assertStringContainsString('  Endpoint:    ' . self::TOKEN_ENDPOINT . "\n", $out);
        self::assertStringContainsString('  HTTP status: -', $out);
        self::assertStringContainsString('  Exception:   B4x\Edoreczenia\Exception\TransportException', $out);
        self::assertStringContainsString('Operation timed out for ' . self::TOKEN_ENDPOINT, $out);
        self::assertStringNotContainsString('login_hint', $out);
        $this->assertNoSecrets($out);
    }

    public function testUnreadableKeyFailsBeforeAnyRequest(): void
    {
        file_put_contents($this->keyPath, 'not a key');

        [$code, $out] = $this->doctor();

        self::assertSame(1, $code);
        self::assertStringContainsString('[FAIL] load private key:', $out);
        self::assertStringContainsString('--passphrase-env', $out);
        self::assertStringContainsString('  Endpoint:    -', $out);
        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->timeouts);
    }

    public function testEncryptedKeyWithPassphraseFromEnvironment(): void
    {
        file_put_contents($this->keyPath, TestKeys::rsa(2048, 'key-pass')['private']);
        $this->http->queue(Http::tokenResponse(self::TOKEN), Http::fixture(200, 'search-engine-v4/eda-confirmation.200.json'));

        [$code, $out] = $this->doctor(['--passphrase-env=EZD_KEY_PASS'], static fn(string $name): ?string => $name === 'EZD_KEY_PASS' ? 'key-pass' : null);

        self::assertSame(0, $code, $out);
        self::assertStringNotContainsString('key-pass', $out);
    }

    public function testEncryptedKeyWithoutPassphraseFails(): void
    {
        file_put_contents($this->keyPath, TestKeys::rsa(2048, 'key-pass')['private']);

        [$code, $out] = $this->doctor();

        self::assertSame(1, $code);
        self::assertStringContainsString('wrong passphrase', $out);
        self::assertSame([], $this->http->requests);
    }

    public function testInvalidSystemNameFails(): void
    {
        [$code, $out] = $this->doctor(['--system=EZD 1'], replace: true);

        self::assertSame(1, $code);
        self::assertStringContainsString('[FAIL] build system credentials: System name must be a non-empty string without whitespace.', $out);
    }

    public function testProductionEnvironmentAndTimeout(): void
    {
        $this->http->queue(Http::tokenResponse(self::TOKEN), Http::fixture(200, 'search-engine-v4/eda-confirmation.200.json'));

        [$code, $out] = $this->doctor(['--env=prod', '--timeout=5'], replace: true);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('environment: production', $out);
        self::assertSame([5], $this->timeouts);
        self::assertStringStartsWith('https://ow.edoreczenia.gov.pl/auth/realms/EDOR/', (string) $this->http->requests[0]->getUri());
    }

    public function testMissingHttpClientFails(): void
    {
        [$code, $out] = $this->doctor(httpStackFactory: static fn(int $timeout): HttpStack => throw new \RuntimeException('No PSR-18 HTTP client found. Install one, e.g.: composer require guzzlehttp/guzzle'));

        self::assertSame(1, $code);
        self::assertStringContainsString('[FAIL] No PSR-18 HTTP client found', $out);
        self::assertStringContainsString('composer require guzzlehttp/guzzle', $out);
    }

    public function testRequiresEnvironmentWithoutDefault(): void
    {
        [$code, $out, $err] = $this->execute(['edoreczenia', 'doctor', '--address=' . self::ADDRESS, '--system=EZD1', '--key=' . $this->keyPath]);

        self::assertSame(2, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('Option --env is required', $err);
    }

    public function testRejectsUnknownEnvironmentAndTimeout(): void
    {
        self::assertSame(2, $this->doctor(['--env=test'], replace: true)[0]);
        self::assertSame(2, $this->doctor(['--timeout=0'], replace: true)[0]);
        self::assertSame(2, $this->doctor(['--timeout=abc'], replace: true)[0]);
        self::assertSame([], $this->http->requests);
    }

    private function assertNoSecrets(string $output): void
    {
        self::assertStringNotContainsString(self::TOKEN, $output);
        self::assertStringNotContainsString('PRIVATE KEY', $output);
        self::assertStringNotContainsString('client_assertion', $output);

        foreach ($this->http->requests as $request) {
            parse_str((string) $request->getBody(), $form);
            if (isset($form['client_assertion']) && \is_string($form['client_assertion'])) {
                self::assertStringNotContainsString($form['client_assertion'], $output);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function tokenBody(): array
    {
        return ['access_token' => self::TOKEN, 'expires_in' => 300, 'refresh_expires_in' => 0, 'token_type' => 'Bearer'];
    }

    /**
     * @param list<string>                     $options extra options, or replacements of the defaults when $replace is true
     * @param (\Closure(string): ?string)|null $env
     * @param (\Closure(int): HttpStack)|null  $httpStackFactory
     *
     * @return array{int, string, string}
     */
    private function doctor(array $options = [], ?\Closure $env = null, bool $replace = false, ?\Closure $httpStackFactory = null): array
    {
        $defaults = ['--env=int', '--address=' . self::ADDRESS, '--system=EZD1', '--key=' . $this->keyPath];
        if ($replace) {
            foreach ($options as $option) {
                $name = substr($option, 0, (int) strpos($option, '=') + 1);
                $defaults = array_values(array_filter($defaults, static fn(string $d): bool => !str_starts_with($d, $name)));
            }
        }

        return $this->execute(['edoreczenia', 'doctor', ...$defaults, ...$options], $env, $httpStackFactory);
    }

    /**
     * @param list<string>                     $argv
     * @param (\Closure(string): ?string)|null $env
     * @param (\Closure(int): HttpStack)|null  $httpStackFactory
     *
     * @return array{int, string, string}
     */
    private function execute(array $argv, ?\Closure $env = null, ?\Closure $httpStackFactory = null): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $httpStackFactory ??= function (int $timeout): HttpStack {
            $this->timeouts[] = $timeout;

            return new HttpStack($this->http, Http::factory(), Http::factory());
        };

        $app = new Application($stdout, $stderr, $httpStackFactory, new FrozenClock(), $env ?? static fn(string $name): ?string => null);
        $code = $app->run($argv);

        rewind($stdout);
        rewind($stderr);

        return [$code, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }
}
