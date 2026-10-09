<?php

declare(strict_types=1);

/*
 * Looks up an address with the SE API v4 on the INTEGRATION (INT) environment.
 *
 *   composer require b44x/edoreczenia guzzlehttp/guzzle
 *   EDORECZENIA_ADDRESS=AE:PL-... EDORECZENIA_SYSTEM=EZD1 EDORECZENIA_KEY=/path/ezd.key \
 *     php examples/search-engine.php 5250007313
 *
 * Requires access to INT (application to COI) and a system registered in the mailbox
 * permission module. The script only performs read-only searches.
 */

use B4x\Edoreczenia\Auth\SystemCredentials;
use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\Exception\EdoreczeniaException;
use B4x\Edoreczenia\Exception\NotFoundException;
use B4x\Edoreczenia\Http\RetryPolicy;
use B4x\Edoreczenia\SearchEngine\V4\Enum\OfficialIdType;
use B4x\Edoreczenia\SearchEngine\V4\Enum\SearchCategory;
use B4x\Edoreczenia\SearchEngine\V4\ErrorCode;
use B4x\Edoreczenia\SearchEngine\V4\Request\AdvancedSearch;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

require __DIR__ . '/../vendor/autoload.php';

$nip = $argv[1] ?? exit("Usage: php examples/search-engine.php <NIP>\n");

$factory = new HttpFactory();
$client = EdoreczeniaClient::withSystemCredentials(
    Environment::integration(),
    SystemCredentials::fromPemFile(
        (string) getenv('EDORECZENIA_ADDRESS'),
        (string) getenv('EDORECZENIA_SYSTEM'),
        (string) getenv('EDORECZENIA_KEY'),
    ),
    new Client(['timeout' => 30, 'connect_timeout' => 10]),
    $factory,
    $factory,
    retryPolicy: RetryPolicy::exponential(maxRetries: 2),
);

try {
    $result = $client->searchEngine()->search(
        AdvancedSearch::inCategories(SearchCategory::PublicInstitution, SearchCategory::Company, SearchCategory::Organisation)
            ->withOfficialId(OfficialIdType::Nip, $nip),
    );

    foreach ($result->items as $item) {
        printf(
            "%s  %-22s  %s%s\n",
            $item->address->address,
            is_string($item->address->status) ? $item->address->status : $item->address->status->value,
            $item->current()?->displayName() ?? '(no entity data)',
            $item->address->hasBlockingWarning() ? '  [cannot send: ' . $item->address->warning?->code . ']' : '',
        );
    }
} catch (NotFoundException $e) {
    echo $e->hasErrorCode(ErrorCode::NO_RESULTS) ? "No address found.\n" : $e->getMessage() . "\n";
} catch (EdoreczeniaException $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}
