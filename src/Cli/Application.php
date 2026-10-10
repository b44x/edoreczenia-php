<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Cli;

use B4x\Edoreczenia\Internal\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * Command line tool shipped as vendor/bin/edoreczenia: "keygen", "doctor" and "help".
 *
 * Everything with side effects outside the given streams is injectable, so the
 * commands can be tested without network access or real environment variables.
 */
final class Application
{
    public const EXIT_OK = 0;
    public const EXIT_FAILURE = 1;
    public const EXIT_USAGE = 2;

    private readonly Output $out;
    private readonly Output $err;
    /** @var \Closure(int): HttpStack */
    private readonly \Closure $httpStackFactory;
    private readonly ClockInterface $clock;
    /** @var \Closure(string): ?string */
    private readonly \Closure $readEnv;

    /**
     * @param resource                       $stdout
     * @param resource                       $stderr
     * @param (\Closure(int): HttpStack)|null $httpStackFactory builds the HTTP client for "doctor" from the timeout in seconds
     * @param (\Closure(string): ?string)|null $readEnv          reads an environment variable (null when unset)
     */
    public function __construct(
        $stdout,
        $stderr,
        ?\Closure $httpStackFactory = null,
        ?ClockInterface $clock = null,
        ?\Closure $readEnv = null,
    ) {
        $this->out = new Output($stdout);
        $this->err = new Output($stderr);
        $this->httpStackFactory = $httpStackFactory ?? HttpStack::detect(...);
        $this->clock = $clock ?? new SystemClock();
        $this->readEnv = $readEnv ?? static function (string $name): ?string {
            $value = getenv($name);

            return \is_string($value) ? $value : null;
        };
    }

    /**
     * @param list<string> $argv full argv, including the script name
     *
     * @return int process exit code
     */
    public function run(array $argv): int
    {
        try {
            $args = Arguments::parse(\array_slice($argv, 1));

            if ($args->help || $args->command === null || $args->command === 'help') {
                $this->usage($this->out);

                return self::EXIT_OK;
            }

            return match ($args->command) {
                'keygen' => (new KeygenCommand($this->out, $this->readEnv))->run($args),
                'doctor' => (new DoctorCommand($this->out, $this->httpStackFactory, $this->clock, $this->readEnv))->run($args),
                default => throw new UsageException(\sprintf('Unknown command "%s".', $args->command)),
            };
        } catch (UsageException $e) {
            $this->err->line('Error: ' . $e->getMessage());
            $this->err->line('Run "edoreczenia help" for usage.');

            return self::EXIT_USAGE;
        }
    }

    private function usage(Output $out): void
    {
        $out->line(<<<'TXT'
            edoreczenia - onboarding and diagnostics for the e-Doręczenia SDK

            Usage:
              edoreczenia keygen --out-dir=DIR --state=ST --locality=L --organization=O --common-name=CN [options]
              edoreczenia doctor --env=int|prod --address=ADE --system=NAME --key=PATH [options]
              edoreczenia help

            keygen  Generate an RSA 2048 private key (<name>.key, mode 0600) and a CSR (<name>.csr)
                    for the system registered in the mailbox permission module.
              --out-dir=DIR          existing directory for the files (required)
              --country=PL           two-letter country code (default PL)
              --state=ST             voivodeship, e.g. Mazowieckie (required)
              --locality=L           locality, e.g. Warszawa (required)
              --organization=O       full company name as in CEIDG/KRS, max 64 characters
                                     (a shortened name is allowed) (required)
              --unit=OU              organizational unit (optional)
              --common-name=CN       domain name, e.g. ezd.domena.pl or *.domena.pl (required)
              --email=ADDRESS        contact e-mail (optional)
              --name=ezd             base name of the files (default ezd)
              --passphrase-env=VAR   encrypt the key with the passphrase stored in environment variable VAR
              Subject values must not contain Polish diacritics or commas. Existing files are never overwritten.

            doctor  Check the configuration: key, client id, token, clock and one read-only
                    Search Engine call (eda-confirmation of the own address). Prints an incident
                    report without credentials when something fails.
              --env=int|prod         environment (required, no default)
              --address=ADE          mailbox address, e.g. AE:PL-12345-67890-ABCDE-12 (required)
              --system=NAME          system name from the permission module (required)
              --key=PATH             private key file (PEM) (required)
              --passphrase-env=VAR   read the key passphrase from environment variable VAR
              --timeout=30           HTTP timeout in seconds
              Requires a PSR-18 client: composer require guzzlehttp/guzzle

            Access to the INT (integration) environment requires a prior application to COI, see
            https://www.gov.pl/web/e-doreczenia/integracja-uslugi-e-doreczen-z-systemami-klasy-ezd

            Exit codes: 0 success, 1 failure, 2 usage error.
            TXT);
    }
}
