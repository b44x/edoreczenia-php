<?php

declare(strict_types=1);

/*
 * Receiving: lists unread messages in INBOX (no legal effect), then opens one and saves its
 * attachments.
 *
 *   php examples/receive.php [message-id] [directory]          # FakeEdoreczenia, no network
 *   EDORECZENIA_ENV=int ... php examples/receive.php ...       # INT, see examples/_client.php
 *
 * Opening a message marks it as opened and, for non-public recipients, triggers the E.1 delivery
 * evidence. On INT the script asks for confirmation first. Not yet verified against a live
 * environment – please report results (see README).
 */

use B4x\Edoreczenia\Exception\EdoreczeniaException;
use B4x\Edoreczenia\UserAgent\V3\Enum\ListFormat;
use B4x\Edoreczenia\UserAgent\V3\Model\MessagePending;
use B4x\Edoreczenia\UserAgent\V3\Request\MessageQuery;

require __DIR__ . '/_client.php';

[$client, $real] = exampleClient();
$ua = $client->userAgent();

try {
    $list = $ua->listMessages(MessageQuery::create()->withFormat(ListFormat::Metadata)->inFolder('INBOX')->withPagination(0, 10));

    echo "INBOX (listing opens nothing)\n";
    foreach ($list->messages as $message) {
        printf("  %s  %s  %s – %s\n", $message->messageId, $message->opened === true ? 'opened  ' : 'unopened', $message->from?->displayName() ?? '?', $message->subject ?? '(no subject)');
    }

    $messageId = ($argv[1] ?? '') !== '' ? $argv[1] : (($list->messages[0] ?? null)?->messageId);
    if ($messageId === null) {
        echo "\nNothing to open.\n";
        exit(0);
    }

    confirmLegalEffect($real, "open message {$messageId}");
    $opened = $ua->openMessage($messageId);

    if ($opened instanceof MessagePending) {
        echo "\nHTTP 202: the message is being transferred ({$opened->status}). Run the script again later.\n";
        exit(0);
    }

    printf("\nOpened %s\n  Subject: %s\n  Body:    %s\n", $opened->messageId(), $opened->metadata->subject ?? '-', $opened->textBody ?? '-');

    $directory = ($argv[2] ?? '') !== '' ? $argv[2] : sys_get_temp_dir() . '/edoreczenia-' . $opened->messageId();
    if (!is_dir($directory) && !mkdir($directory, 0o700, true)) {
        throw new RuntimeException('Cannot create ' . $directory);
    }

    foreach ($opened->attachments as $attachment) {
        if ($attachment->attachmentId === null) {
            continue;
        }
        $target = $directory . '/' . ($attachment->safeFileName() ?? $attachment->attachmentId);
        $bytes = $ua->downloadAttachment($opened->messageId(), $attachment->attachmentId)->saveTo($target);
        printf("  Attachment %s: %d bytes → %s\n", $attachment->attachmentId, $bytes, $target);
    }
} catch (EdoreczeniaException $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}
