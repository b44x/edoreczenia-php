<?php

declare(strict_types=1);

/*
 * Sending: checks the recipient with the Search Engine API, sends a message with an optional
 * attachment and polls the send task for the per-recipient result.
 *
 *   php examples/send.php [recipient-ADE] [file]               # FakeEdoreczenia, no network
 *   EDORECZENIA_ENV=int ... php examples/send.php ADE [file]   # INT, see examples/_client.php
 *
 * Sending has legal effect and is never retried automatically. On INT the script asks for
 * confirmation first. Not yet verified against a live environment – please report results.
 */

use B4x\Edoreczenia\Exception\EdoreczeniaException;
use B4x\Edoreczenia\UserAgent\V3\Enum\MessageTaskState;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingAttachment;
use B4x\Edoreczenia\UserAgent\V3\Request\OutgoingMessage;

require __DIR__ . '/_client.php';

[$client, $real] = exampleClient();
$recipient = ($argv[1] ?? '') !== '' ? $argv[1] : ($real ? null : 'AE:PL-98765-43210-SFVYC-19');
if ($recipient === null) {
    fwrite(STDERR, "Usage: php examples/send.php RECIPIENT-ADE [file]\n");
    exit(2);
}

try {
    $found = $client->searchEngine()->findByAddresses([$recipient]);
    $address = ($found->items[0] ?? null)?->address;
    if ($address === null || !$address->isActive()) {
        fwrite(STDERR, "Recipient {$recipient} is not an active e-Doręczenia address; not sending.\n");
        exit(1);
    }

    $message = OutgoingMessage::create('Test message from the e-Doręczenia PHP SDK')
        ->withRecipients($recipient)
        ->withTextBody('This message was sent by examples/send.php.');

    $file = ($argv[2] ?? '') !== '' ? $argv[2] : null;
    if ($file !== null) {
        $contentType = mime_content_type($file);
        $message = $message->withAttachment(
            OutgoingAttachment::fromString((string) file_get_contents($file), basename($file), $contentType !== false ? $contentType : 'application/octet-stream'),
        );
    }

    confirmLegalEffect($real, "send a message to {$recipient}");
    $task = $client->userAgent()->sendMessage($message);
    if ($task->messageTaskId === null) {
        echo "Accepted without a task id. Check the SENT folder before sending again.\n";
        exit(1);
    }
    echo "Accepted, task {$task->messageTaskId}\n";

    for ($attempt = 0; $attempt < 30; ++$attempt) {
        $status = $client->userAgent()->messageTaskStatus($task->messageTaskId);
        if ($status === MessageTaskState::Finished) {
            foreach ($client->userAgent()->messageTask($task->messageTaskId) as $result) {
                printf("  %s: %s\n", $result->recipientAddress ?? '?', $result->isAccepted() ? 'accepted, message ' . $result->messageId : 'rejected, ' . ($result->error ?? '?'));
            }
            exit(0);
        }
        echo '  status: ', $status instanceof MessageTaskState ? $status->value : $status, "\n";
        sleep($real ? 2 : 0);
    }

    echo "Still processing. Check the task later; do not send again.\n";
} catch (EdoreczeniaException $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}
