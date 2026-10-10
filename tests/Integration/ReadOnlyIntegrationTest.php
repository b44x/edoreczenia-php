<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Integration;

use B4x\Edoreczenia\Auth\SystemCredentials;
use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Optional smoke test against the INTEGRATION (INT) environment only.
 *
 * Runs only when these variables are set (access to INT requires an approved application to COI):
 *   EDORECZENIA_INT_ADDRESS      mailbox address of the test entity, e.g. AE:PL-...
 *   EDORECZENIA_INT_SYSTEM       system name registered in the mailbox permission module
 *   EDORECZENIA_INT_KEY_PATH     path to the system's PEM private key
 *   EDORECZENIA_INT_KEY_PASSPHRASE (optional)
 *
 * It performs read-only calls only (own-address lookup, country dictionary, folder list);
 * it never opens, sends or deletes messages.
 */
#[Group('integration')]
final class ReadOnlyIntegrationTest extends TestCase
{
    public function testOwnAddressCanBeLookedUpOnInt(): void
    {
        [$client, $address] = $this->client();

        $details = $client->searchEngine()->getOwnAddressDetails();

        self::assertSame($address, $details->address->address);
    }

    public function testReadOnlyMailboxOperationsWorkOnInt(): void
    {
        [$client] = $this->client();

        self::assertNotSame([], $client->userAgent()->countries());
        self::assertNotSame([], $client->userAgent()->directories(), 'every mailbox has predefined folders');
        self::assertGreaterThanOrEqual(0, $client->userAgent()->listMessages()->total ?? 0);
        self::assertGreaterThanOrEqual(0, $client->userAgent()->listThreads()->total ?? 0);
        self::assertGreaterThanOrEqual(0, \count($client->userAgent()->mailboxEvidences()->evidences));
    }

    /**
     * @return array{EdoreczeniaClient, string}
     */
    private function client(): array
    {
        $address = getenv('EDORECZENIA_INT_ADDRESS');
        $system = getenv('EDORECZENIA_INT_SYSTEM');
        $keyPath = getenv('EDORECZENIA_INT_KEY_PATH');
        $passphrase = getenv('EDORECZENIA_INT_KEY_PASSPHRASE');

        if (!\is_string($address) || !\is_string($system) || !\is_string($keyPath) || $address === '' || $system === '' || $keyPath === '') {
            self::markTestSkipped('INT credentials not configured.');
        }

        $factory = new HttpFactory();
        $client = EdoreczeniaClient::withSystemCredentials(
            Environment::integration(),
            SystemCredentials::fromPemFile($address, $system, $keyPath, \is_string($passphrase) && $passphrase !== '' ? $passphrase : null),
            new Client(['timeout' => 30, 'connect_timeout' => 10]),
            $factory,
            $factory,
        );

        return [$client, $address];
    }
}
