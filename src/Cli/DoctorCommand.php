<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Cli;

use B4x\Edoreczenia\Auth\PrivateKey;
use B4x\Edoreczenia\Auth\SignedJwtTokenProvider;
use B4x\Edoreczenia\Auth\SystemCredentials;
use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\Exception\ApiException;
use B4x\Edoreczenia\Http\HttpStack;
use B4x\Edoreczenia\Internal\Transport;
use B4x\Edoreczenia\SearchEngine\V4\Enum\EdaStatus;
use Psr\Clock\ClockInterface;

/**
 * Checks a system configuration end to end: key, client id, token, clock and one
 * read-only Search Engine call (eda-confirmation of the own address).
 *
 * The access token, the client assertion and the key are never printed. On failure an
 * "Incident report" block lists what the operator's service desk asks for, without
 * credentials or response bodies.
 *
 * @internal
 */
final class DoctorCommand
{
    public const OPTIONS = ['env', 'address', 'system', 'key', 'passphrase-env', 'timeout'];

    private const MAX_CLOCK_SKEW_SECONDS = 30;

    /**
     * @param \Closure(int): HttpStack  $httpStackFactory receives the timeout in seconds
     * @param \Closure(string): ?string $readEnv
     */
    public function __construct(
        private readonly Output $out,
        private readonly \Closure $httpStackFactory,
        private readonly ClockInterface $clock,
        private readonly \Closure $readEnv,
    ) {}

    public function run(Arguments $args): int
    {
        $args->assertOnly(self::OPTIONS);

        $environment = match ($args->required('env')) {
            'int' => Environment::integration(),
            'prod' => Environment::production(),
            default => throw new UsageException('Option --env must be "int" or "prod".'),
        };
        $address = $args->required('address');
        $system = $args->required('system');
        $keyPath = $args->required('key');
        $timeout = $args->optional('timeout', '30') ?? '30';
        if (preg_match('/^[1-9]\d{0,2}$/', $timeout) !== 1) {
            throw new UsageException('Option --timeout must be a number of seconds between 1 and 999.');
        }
        $passphrase = KeygenCommand::passphrase($args, $this->readEnv);

        $this->out->line(\sprintf('e-Doręczenia doctor, environment: %s', $environment->name));

        // (1) private key
        try {
            // An empty passphrase makes OpenSSL fail on an encrypted key instead of prompting on the terminal.
            $key = PrivateKey::fromFile($keyPath, $passphrase ?? '');
        } catch (\Throwable $e) {
            $hint = $passphrase === null && is_file($keyPath) ? 'If the key is encrypted, pass its passphrase with --passphrase-env=VAR.' : null;

            return $this->failed($environment, 'load private key', $e, null, $hint);
        }
        $this->ok(\sprintf('Private key loaded: RSA %d bits', $key->bits()));

        // (2) credentials
        try {
            $credentials = new SystemCredentials($address, $system, $key);
        } catch (\Throwable $e) {
            return $this->failed($environment, 'build system credentials', $e, null);
        }
        $this->ok('Client id: ' . $credentials->clientId());

        // (3) token
        try {
            $stack = ($this->httpStackFactory)((int) $timeout);
        } catch (\RuntimeException $e) {
            $this->out->line('[FAIL] ' . $e->getMessage());

            return Application::EXIT_FAILURE;
        }

        $http = new ObservingHttpClient($stack->client, $this->clock);
        $tokens = new SignedJwtTokenProvider($credentials, $environment, $http, $stack->requestFactory, $stack->streamFactory, $this->clock);

        try {
            $requestedAt = $this->clock->now();
            $token = $tokens->getToken();
        } catch (\Throwable $e) {
            $this->clockCheck($http);

            return $this->failed($environment, 'token request', $e, $http);
        }
        $this->ok(\sprintf('Access token obtained, expires_in=%d s', $token->expiresAt->getTimestamp() - $requestedAt->getTimestamp()));
        unset($token);

        // (4) clock
        $this->clockCheck($http);

        // (5) read-only API call
        $client = new EdoreczeniaClient($environment, $credentials->deliveryAddress, $tokens, $http, $stack->requestFactory, $stack->streamFactory);
        try {
            $details = $client->searchEngine()->getOwnAddressDetails();
        } catch (\Throwable $e) {
            return $this->failed($environment, 'SE API v4 eda-confirmation', $e, $http);
        }

        $own = $details->address;
        $status = $own->status instanceof EdaStatus ? $own->status->value : $own->status;
        $line = \sprintf('Address status: %s%s', $status, $own->isMain === null ? '' : ($own->isMain ? ', main address' : ', not the main address'));
        if ($own->isActive()) {
            $this->ok($line);
        } else {
            $this->out->line('[WARN] ' . $line . ' (expected ACTIVE)');
        }
        if ($own->warning !== null) {
            $this->out->line('[WARN] The address has ' . ($own->hasBlockingWarning() ? 'a blocking warning' : 'a warning'));
        }

        $this->out->line();
        $this->out->line('All checks passed.');

        return Application::EXIT_OK;
    }

    private function clockCheck(ObservingHttpClient $http): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        if ($http->serverDate === null || $http->localDate === null) {
            $this->out->line(\sprintf(
                '[WARN] Clock: no server Date header to compare with; local time is %s UTC. Keep the clock synchronised (NTP): the client assertion carries iat/nbf/exp.',
                $now->format('Y-m-d H:i:s'),
            ));

            return;
        }

        $skew = $http->localDate->getTimestamp() - $http->serverDate->getTimestamp();
        $line = \sprintf('Clock: local time differs from the server Date header by %+d s (local %s UTC)', $skew, $now->format('Y-m-d H:i:s'));
        if (abs($skew) > self::MAX_CLOCK_SKEW_SECONDS) {
            $this->out->line('[WARN] ' . $line . '. Synchronise the clock (NTP): the client assertion carries iat/nbf/exp.');

            return;
        }

        $this->ok($line);
    }

    private function failed(Environment $environment, string $operation, \Throwable $e, ?ObservingHttpClient $http, ?string $hint = null): int
    {
        $this->out->line('[FAIL] ' . $operation . ': ' . self::sanitize($e->getMessage()));
        if ($hint !== null) {
            $this->out->line('       ' . $hint);
        }
        $this->out->line();
        $this->out->line('Incident report (safe to share: contains no token, key or assertion)');
        $this->out->line('  Time (UTC):  ' . $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        $this->out->line('  Environment: ' . $environment->name);
        $this->out->line('  Operation:   ' . $operation);
        $this->out->line('  Endpoint:    ' . ($http->lastEndpoint ?? '-'));

        $status = $e instanceof ApiException ? $e->getStatusCode() : $http?->lastStatus;
        $this->out->line('  HTTP status: ' . ($status !== null && $status > 0 ? (string) $status : '-'));

        $codes = $e instanceof ApiException ? array_map(static fn($error): string => $error->code, $e->getErrors()) : [];
        $this->out->line('  Error codes: ' . ($codes === [] ? '-' : implode(', ', $codes)));
        $this->out->line('  Event id:    ' . (($e instanceof ApiException ? $e->getEventId() : null) ?? '-'));
        $this->out->line('  Exception:   ' . $e::class);
        $this->out->line('  Message:     ' . self::sanitize($e->getMessage()));

        return Application::EXIT_FAILURE;
    }

    /**
     * Reduces every URL in the message to scheme, host and path: query strings such as
     * login_hint are dropped. Line breaks are flattened so the report stays one line per field.
     */
    private static function sanitize(string $message): string
    {
        $message = (string) preg_replace_callback(
            '~https?://[^\s"\'<>]+~i',
            static fn(array $m): string => Transport::redactUri($m[0]),
            $message,
        );

        return trim((string) preg_replace('/\s+/', ' ', $message));
    }

    private function ok(string $message): void
    {
        $this->out->line('[OK]   ' . $message);
    }
}
