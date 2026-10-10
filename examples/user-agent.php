<?php

declare(strict_types=1);

/*
 * Read-only tour of a mailbox on the INTEGRATION (INT) environment: folders with counters,
 * unread messages (metadata only – nothing is opened) and the evidences of the newest message.
 *
 *   composer require b44x/edoreczenia guzzlehttp/guzzle
 *   EDORECZENIA_ADDRESS=AE:PL-... EDORECZENIA_SYSTEM=EZD1 EDORECZENIA_KEY=/path/ezd.key \
 *     php examples/user-agent.php [directory-to-save-evidences]
 *
 * Listing never opens messages, so running this script has no legal effect.
 * Not yet verified against a live environment – please report results (see README).
 */

use B4x\Edoreczenia\Auth\SystemCredentials;
use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\Exception\EdoreczeniaException;
use B4x\Edoreczenia\Http\RetryPolicy;
use B4x\Edoreczenia\UserAgent\V3\Enum\EvidenceService;
use B4x\Edoreczenia\UserAgent\V3\Enum\EvidenceType;
use B4x\Edoreczenia\UserAgent\V3\Enum\ListFormat;
use B4x\Edoreczenia\UserAgent\V3\Enum\ShippingService;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortColumn;
use B4x\Edoreczenia\UserAgent\V3\Request\MessageQuery;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

require __DIR__ . '/../vendor/autoload.php';

$saveDir = $argv[1] ?? null;
$factory = new HttpFactory();

$client = EdoreczeniaClient::withSystemCredentials(
    Environment::integration(),
    SystemCredentials::fromPemFile(
        (string) getenv('EDORECZENIA_ADDRESS'),
        (string) getenv('EDORECZENIA_SYSTEM'),
        (string) getenv('EDORECZENIA_KEY'),
    ),
    new Client(['timeout' => 60, 'connect_timeout' => 10]),
    $factory,
    $factory,
    retryPolicy: RetryPolicy::exponential(maxRetries: 2),
);

$label = static fn(\BackedEnum|string|null $value): string => $value instanceof \BackedEnum ? (string) $value->value : ($value ?? '-');

try {
    $ua = $client->userAgent();

    echo "Folders\n";
    foreach ($ua->directoriesWithCounts() as $folder) {
        printf("  %-20s %5s unread / %5s total\n", $folder->name ?? $folder->label ?? '?', $folder->unreadMessages ?? '?', $folder->totalMessages ?? '?');
    }

    $list = $ua->listMessages(
        MessageQuery::create()
            ->withFormat(ListFormat::Metadata)
            ->inFolder('INBOX')
            ->onlyOpened(false)
            ->sortBy(SortColumn::Timestamp)
            ->withPagination(0, 10),
    );

    printf("\nUnread in INBOX: %s (showing %d)\n", $list->total ?? '?', count($list->messages));
    foreach ($list->messages as $message) {
        printf(
            "  %s  %-22s %-10s %s – %s%s\n",
            $message->createdAt?->format('Y-m-d H:i') ?? '----------------',
            $label($message->status),
            $label($message->shippingService),
            $message->from?->displayName() ?? '?',
            $message->subject ?? '(no subject)',
            $message->isIncomingNotification() ? '  [notification: original not fetched yet]' : '',
        );
    }

    $newest = $list->messages[0] ?? null;
    if ($newest === null) {
        exit(0);
    }

    echo "\nEvidences of {$newest->messageId}\n";
    $service = $newest->shippingService === ShippingService::Hybrid ? EvidenceService::Puh : EvidenceService::Purde;
    foreach ($ua->messageEvidences($newest->messageId)->evidences as $evidence) {
        printf("  %-8s %s  downloaded=%s\n", $label($evidence->type), $evidence->eventDate?->format('Y-m-d H:i') ?? '-', var_export($evidence->downloaded, true));

        if ($saveDir !== null && $evidence->type instanceof EvidenceType) {
            $file = $ua->downloadEvidence($service, $evidence->evidenceId);
            $target = rtrim($saveDir, '/') . '/' . ($file->fileName ?? $evidence->evidenceId);
            printf("           saved %d bytes to %s\n", $file->saveTo($target), $target);
        }
    }
} catch (EdoreczeniaException $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}
