<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\Testing;

use B4x\Edoreczenia\Exception\BadRequestException;
use B4x\Edoreczenia\Testing\FakeEdoreczenia;
use B4x\Edoreczenia\Testing\SyntheticData;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\UserAgent\V3\Enum\ArchiveTaskSortColumn;
use B4x\Edoreczenia\UserAgent\V3\Enum\ArchiveTaskStatus;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortDirection;
use B4x\Edoreczenia\UserAgent\V3\Model\Download;
use B4x\Edoreczenia\UserAgent\V3\Request\ArchiveTaskQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\ArchiveTaskRequest;
use B4x\Edoreczenia\UserAgent\V3\UserAgentClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class FakeEdoreczeniaArchiveTest extends TestCase
{
    private const UA = 'https://uaapi-int-ow.poczta-polska.pl/api/v3/';

    private FakeEdoreczenia $fake;
    private UserAgentClient $ua;

    protected function setUp(): void
    {
        $this->fake = new FakeEdoreczenia(Http::factory(), Http::factory());
        $this->ua = $this->fake->createClient()->userAgent();
    }

    public function testDeletedMessagesDisappearFromListingsOfThisInstance(): void
    {
        self::assertSame(2, $this->ua->listMessages()->total);

        $deleted = $this->ua->deleteMessages(SyntheticData::MESSAGE_ID);

        self::assertCount(1, $deleted);
        self::assertSame(SyntheticData::MESSAGE_ID, $deleted[0]->messageId);
        $list = $this->ua->listMessages();
        self::assertSame(1, $list->total);
        self::assertSame([SyntheticData::SENT_MESSAGE_ID], array_map(static fn($m): string => $m->messageId, $list->messages));
        self::assertSame([], $this->ua->getThread(SyntheticData::THREAD_ID)->messages->messages);
        self::assertSame(['EV-3'], array_map(static fn($e): string => $e->evidenceId, $this->ua->mailboxEvidences()->evidences));

        // A new fake instance still has every message.
        $other = (new FakeEdoreczenia(Http::factory(), Http::factory()))->createClient()->userAgent();
        self::assertSame(2, $other->listMessages()->total);
    }

    public function testDeletingDoesNotOpenTheMessage(): void
    {
        $this->ua->deleteMessages(SyntheticData::SENT_MESSAGE_ID);

        $remaining = $this->ua->listMessages()->messages;
        self::assertCount(1, $remaining);
        self::assertFalse($remaining[0]->opened);
    }

    public function testDeletedMessagesCannotBeReadOrDeletedAgain(): void
    {
        $this->ua->deleteMessages(SyntheticData::MESSAGE_ID, SyntheticData::PENDING_MESSAGE_ID);

        foreach ([
            'open' => fn() => $this->ua->openMessage(SyntheticData::MESSAGE_ID),
            'open pending' => fn() => $this->ua->openMessage(SyntheticData::PENDING_MESSAGE_ID),
            'attachment' => fn() => $this->ua->downloadAttachment(SyntheticData::MESSAGE_ID, 'ATT-1'),
            'json attachment' => fn() => $this->ua->getAttachment(SyntheticData::MESSAGE_ID, 'ATT-1'),
            'evidences' => fn() => $this->ua->messageEvidences(SyntheticData::MESSAGE_ID),
            'technical evidences' => fn() => $this->ua->downloadTechnicalEvidences(SyntheticData::MESSAGE_ID),
            'message archive' => fn() => $this->ua->downloadMessageArchive(SyntheticData::MESSAGE_ID),
            'delete again' => fn() => $this->ua->deleteMessages(SyntheticData::MESSAGE_ID),
            'archive' => fn() => $this->ua->createArchiveTask(ArchiveTaskRequest::forMessages(SyntheticData::MESSAGE_ID)),
        ] as $name => $call) {
            try {
                $call();
                self::fail('Expected exception for ' . $name);
            } catch (BadRequestException $e) {
                self::assertSame('INVALID_PARAMETER', $e->getErrors()[0]->code, $name);
            }
        }
    }

    public function testUnknownIdsFailTheWholeDeletion(): void
    {
        $response = $this->call('DELETE', 'messages/' . SyntheticData::MESSAGE_ID . ',nope');

        self::assertSame(400, $response->getStatusCode());
        $errors = json_decode((string) $response->getBody(), true);
        self::assertSame([['error' => 'INVALID_PARAMETER', 'error_description' => 'Unknown message id', 'messageId' => 'nope']], $errors);
        self::assertSame(2, $this->ua->listMessages()->total, 'nothing was deleted');
    }

    public function testArchiveTaskLifecycle(): void
    {
        $taskId = $this->ua->createArchiveTask(ArchiveTaskRequest::forMessages(SyntheticData::MESSAGE_ID, SyntheticData::SENT_MESSAGE_ID)->withDescription('Archiwum'));

        $first = $this->ua->archiveTask($taskId);
        self::assertNotNull($first);
        self::assertSame(ArchiveTaskStatus::ArchivingInProgress, $first->status);
        self::assertSame(2, $first->messageCount);
        self::assertSame('Archiwum', $first->description);
        self::assertSame('00:01', $first->estimatedTimeToComplete);
        self::assertTrue($first->canDownloadAndChangeStatus);

        $this->expectBadRequest(fn() => $this->ua->downloadArchive($taskId), 'not ready yet');
        $this->expectBadRequest(fn() => $this->ua->finishArchiveTask($taskId), 'not ready yet');

        $ready = $this->ua->archiveTask($taskId);
        self::assertNotNull($ready);
        self::assertTrue($ready->isReadyToDownload());
        self::assertNotNull($ready->downloadDeadline);
        self::assertNull($ready->estimatedTimeToComplete);

        $zip = $this->ua->downloadArchive($taskId);
        self::assertSame('application/zip', $zip->contentType);
        self::assertSame($taskId . '.zip', $zip->fileName);
        self::assertSame(['README.txt', SyntheticData::MESSAGE_ID . '.json', SyntheticData::SENT_MESSAGE_ID . '.json'], self::zipEntries($zip));

        $finished = $this->ua->finishArchiveTask($taskId)->find($taskId);
        self::assertNotNull($finished);
        self::assertSame(ArchiveTaskStatus::Finished, $finished->status);
        self::assertFalse($finished->deletesMessages);
        self::assertSame(2, $this->ua->listMessages()->total, 'messages are kept');
        $this->expectBadRequest(fn() => $this->ua->downloadArchive($taskId), 'finished');
        $this->expectBadRequest(fn() => $this->ua->cancelArchiveTask($taskId), 'finished');
    }

    public function testFinishingWithDeletionDeletesTheMessages(): void
    {
        $taskId = $this->ua->createArchiveTask(ArchiveTaskRequest::forMessages(SyntheticData::MESSAGE_ID));
        $this->ua->listArchiveTasks();
        $this->ua->listArchiveTasks();

        $finished = $this->ua->finishArchiveTaskAndDeleteMessages($taskId)->tasks[0];

        self::assertSame(ArchiveTaskStatus::Finished, $finished->status);
        self::assertTrue($finished->deletesMessages);
        self::assertSame([SyntheticData::SENT_MESSAGE_ID], array_map(static fn($m): string => $m->messageId, $this->ua->listMessages()->messages));
    }

    public function testCancel(): void
    {
        $taskId = $this->ua->createArchiveTask(ArchiveTaskRequest::forMessages(SyntheticData::MESSAGE_ID));

        self::assertSame(ArchiveTaskStatus::Canceled, $this->ua->cancelArchiveTask($taskId)->tasks[0]->status);
        self::assertSame(ArchiveTaskStatus::Canceled, $this->ua->archiveTask($taskId)?->status);
        $this->expectBadRequest(fn() => $this->ua->downloadArchive($taskId), 'canceled');
        $this->expectBadRequest(fn() => $this->ua->cancelArchiveTask('nope'), 'unknown task');
    }

    public function testTaskListPaginationAndSorting(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $ids[] = $this->ua->createArchiveTask(ArchiveTaskRequest::forMessages(SyntheticData::MESSAGE_ID));
        }

        $all = $this->ua->listArchiveTasks();
        self::assertSame(3, $all->total);
        self::assertSame($ids, array_map(static fn($t): string => $t->taskId, $all->tasks));

        $page = $this->ua->listArchiveTasks(ArchiveTaskQuery::create()->withPagination(1, 1)->sortBy(ArchiveTaskSortColumn::CreationDate, SortDirection::Descending));
        self::assertSame(3, $page->total);
        self::assertSame([$ids[1]], array_map(static fn($t): string => $t->taskId, $page->tasks));
        self::assertNull($this->ua->archiveTask('nope'));
    }

    public function testSingleMessageArchive(): void
    {
        $zip = $this->ua->downloadMessageArchive(SyntheticData::MESSAGE_ID);

        self::assertSame('application/zip', $zip->contentType);
        self::assertSame(['README.txt', SyntheticData::MESSAGE_ID . '.json'], self::zipEntries($zip));
        self::assertFalse($this->ua->listMessages()->messages[0]->opened, 'archiving does not open the message in the fake');
        $this->expectBadRequest(fn() => $this->ua->downloadMessageArchive('nope'), 'unknown message');
    }

    public function testInvalidRequestsAreRejected(): void
    {
        foreach (['{}', '{"messageId":[]}', '{"messageId":"x"}', '{"messageId":[1]}', '{"messageId":["' . SyntheticData::MESSAGE_ID . '"],"taskDescription":"' . str_repeat('a', 256) . '"}', '{"messageId":["nope"]}'] as $body) {
            self::assertSame(400, $this->call('POST', 'message-archive-task', $body)->getStatusCode(), $body);
        }
        self::assertSame(400, $this->call('PATCH', 'message-archive-task', '{"taskId":"nope","changeStatus":"CANCEL"}')->getStatusCode());
        $taskId = $this->ua->createArchiveTask(ArchiveTaskRequest::forMessages(SyntheticData::MESSAGE_ID));
        self::assertSame(400, $this->call('PATCH', 'message-archive-task', '{"taskId":"' . $taskId . '","changeStatus":"PAUSE"}')->getStatusCode());
        self::assertSame(405, $this->call('PUT', 'message-archive-task', '{}')->getStatusCode());
    }

    public function testMailboxAndTokenAreChecked(): void
    {
        $other = $this->fake->createClient('AE:PL-98765-43210-SFVYC-19')->userAgent();
        $taskId = $this->ua->createArchiveTask(ArchiveTaskRequest::forMessages(SyntheticData::MESSAGE_ID));

        self::assertNull($other->archiveTask($taskId), 'tasks belong to the mailbox');
        self::assertSame(2, $other->listMessages()->total);
        $this->ua->deleteMessages(SyntheticData::MESSAGE_ID);
        self::assertSame(2, $other->listMessages()->total, 'deletions belong to the mailbox');

        $this->fake->acceptToken('t', SyntheticData::MAILBOX);
        $foreign = Http::factory()->createRequest('GET', self::UA . 'AE:PL-98765-43210-SFVYC-19/message-archive-task-list')->withHeader('Authorization', 'Bearer t');
        self::assertSame(403, $this->fake->sendRequest($foreign)->getStatusCode());
        $anonymous = Http::factory()->createRequest('DELETE', self::UA . SyntheticData::MAILBOX . '/messages/' . SyntheticData::SENT_MESSAGE_ID);
        self::assertSame(401, $this->fake->sendRequest($anonymous)->getStatusCode());

    }

    public function testFailNextStillTakesPrecedence(): void
    {
        $this->fake->failNext('/message-archive-task', 503, []);

        self::assertSame(503, $this->call('POST', 'message-archive-task', '{"messageId":["' . SyntheticData::MESSAGE_ID . '"]}')->getStatusCode());
        self::assertSame(0, $this->ua->listArchiveTasks()->total);
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function expectBadRequest(\Closure $call, string $reason): void
    {
        try {
            $call();
            self::fail('Expected HTTP 400: ' . $reason);
        } catch (BadRequestException) {
        }
    }

    private function call(string $method, string $path, string $body = ''): ResponseInterface
    {
        $this->fake->acceptToken('t', SyntheticData::MAILBOX);
        $request = Http::factory()->createRequest($method, self::UA . SyntheticData::MAILBOX . '/' . $path)
            ->withHeader('Authorization', 'Bearer t')
            ->withBody(Http::factory()->createStream($body));

        return $this->fake->sendRequest($request);
    }

    /**
     * Entry names of a stored ZIP, read from its central directory without ext-zip.
     *
     * @return list<string>
     */
    private static function zipEntries(Download $download): array
    {
        $bytes = (string) $download->stream;
        self::assertStringStartsWith("PK\x03\x04", $bytes);
        $end = strrpos($bytes, "PK\x05\x06");
        self::assertNotFalse($end);
        $eocd = unpack('Vsig/vdisk/vcd/ventries/vtotal/Vsize/Voffset', substr($bytes, $end, 22));
        self::assertIsArray($eocd);
        $offset = $eocd['offset'];
        self::assertIsInt($offset);

        $names = [];
        for ($i = 0; $i < $eocd['total']; ++$i) {
            $header = unpack('Vsig/vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Vlocal', substr($bytes, $offset, 46));
            self::assertIsArray($header);
            self::assertSame(0x02014B50, $header['sig']);
            self::assertIsInt($header['local']);
            self::assertSame("PK\x03\x04", substr($bytes, $header['local'], 4), 'central directory points at a local header');
            self::assertIsInt($header['name']);
            self::assertIsInt($header['extra']);
            self::assertIsInt($header['comment']);
            $names[] = substr($bytes, $offset + 46, $header['name']);
            $offset += 46 + $header['name'] + $header['extra'] + $header['comment'];
        }

        if (class_exists(\ZipArchive::class)) {
            $path = tempnam(sys_get_temp_dir(), 'fake-archive');
            self::assertIsString($path);
            try {
                $download->saveTo($path);
                $zip = new \ZipArchive();
                self::assertTrue($zip->open($path, \ZipArchive::CHECKCONS));
                $listed = [];
                for ($i = 0; $i < $zip->numFiles; ++$i) {
                    $listed[] = $zip->getNameIndex($i);
                    self::assertNotFalse($zip->getFromIndex($i));
                }
                $zip->close();
                self::assertSame($names, $listed);
            } finally {
                unlink($path);
            }
        }

        return $names;
    }
}
