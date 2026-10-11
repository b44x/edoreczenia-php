<?php

declare(strict_types=1);

/*
 * Shared setup for examples/receive.php and examples/send.php.
 *
 * Without EDORECZENIA_ENV the examples run against FakeEdoreczenia: synthetic data, no network,
 * no legal effect. With EDORECZENIA_ENV=int they call the INTEGRATION environment with your system
 * credentials:
 *
 *   EDORECZENIA_ENV=int EDORECZENIA_ADDRESS=AE:PL-... EDORECZENIA_SYSTEM=EZD1 EDORECZENIA_KEY=/path/ezd.key
 *
 * Production is deliberately not offered here.
 */

use B4x\Edoreczenia\Auth\SystemCredentials;
use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\Testing\FakeEdoreczenia;

require __DIR__ . '/../vendor/autoload.php';

/**
 * @return array{EdoreczeniaClient, bool} the client and whether it talks to a real environment
 */
function exampleClient(): array
{
    $env = getenv('EDORECZENIA_ENV');

    if ($env === false || $env === '') {
        $factory = class_exists(GuzzleHttp\Psr7\HttpFactory::class) ? new GuzzleHttp\Psr7\HttpFactory() : new Nyholm\Psr7\Factory\Psr17Factory();
        fwrite(STDERR, "[fake] FakeEdoreczenia: synthetic data, no network. Set EDORECZENIA_ENV=int to use INT.\n\n");

        return [(new FakeEdoreczenia($factory, $factory))->createClient(), false];
    }

    if ($env !== 'int') {
        fwrite(STDERR, "EDORECZENIA_ENV must be \"int\" (production is not offered by the examples).\n");
        exit(2);
    }

    $client = EdoreczeniaClient::withSystemCredentials(
        Environment::integration(),
        SystemCredentials::fromPemFile(
            (string) getenv('EDORECZENIA_ADDRESS'),
            (string) getenv('EDORECZENIA_SYSTEM'),
            (string) getenv('EDORECZENIA_KEY'),
        ),
    );
    fwrite(STDERR, '[int] ' . $client->deliveryAddress() . " on the INTEGRATION environment.\n\n");

    return [$client, true];
}

/**
 * Asks for explicit confirmation before an operation with legal effect on a real environment.
 */
function confirmLegalEffect(bool $real, string $what): void
{
    if (!$real) {
        return;
    }

    fwrite(STDERR, "This will {$what} on INT. It has legal effect in that environment. Type \"yes\" to continue: ");
    if (trim((string) fgets(STDIN)) !== 'yes') {
        fwrite(STDERR, "Cancelled.\n");
        exit(1);
    }
}
