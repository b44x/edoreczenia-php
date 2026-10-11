<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\UserAgent\V3;

use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\Exception\ServerException;
use B4x\Edoreczenia\Exception\UnexpectedResponseException;
use B4x\Edoreczenia\Http\RetryPolicy;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\Tests\Support\MockHttpClient;
use B4x\Edoreczenia\Tests\Support\SpyTokenProvider;
use B4x\Edoreczenia\UserAgent\V3\Enum\ArchiveTaskSortColumn;
use B4x\Edoreczenia\UserAgent\V3\Enum\ArchiveTaskStatus;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortDirection;
use B4x\Edoreczenia\UserAgent\V3\Request\ArchiveTaskQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\ArchiveTaskRequest;
use B4x\Edoreczenia\UserAgent\V3\UserAgentClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArchivesTest extends TestCase
{
    private const MAILBOX = 'AE:PL-12345-67890-ABCDE-12';
    private const BASE = 'https://uaapi-int-ow.poczta-polska.pl/api/v3/';
    private const TASK = '3F2504E0-4F89-41D3-9A0C-0305E82C3301';
    private const MESSAGE = 'D41568F4-7175-42BB-9503-DAA282180D70';

    private MockHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
    }

    public function testCreateArchiveTaskPostsIdsAndDescription(): void
    {
        $this->http->queue(Http::fixture(202, 'user-agent-v3/archive-task.202.json'));

        $taskId = $this->ua()->createArchiveTask(ArchiveTaskRequest::forMessages(self::MESSAGE, 'M-2', self::MESSAGE)->withDescription('Archiwum za kwiecień'));

        self::assertSame(self::TASK, $taskId);
        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE . self::MAILBOX . '/message-archive-task', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(['messageId' => [self::MESSAGE, 'M-2'], 'taskDescription' => 'Archiwum za kwiecień'], Http::jsonBody($request));
    }

    public function testCreateArchiveTaskWithoutDescription(): void
    {
        $this->http->queue(Http::fixture(202, 'user-agent-v3/archive-task.202.json'));

        $this->ua()->createArchiveTask(ArchiveTaskRequest::forMessages(self::MESSAGE));

        self::assertSame(['messageId' => [self::MESSAGE]], Http::jsonBody($this->http->lastRequest()));
    }

    public function testCreateArchiveTaskRequiresTheTaskId(): void
    {
        $this->http->queue(Http::json(202, '{}'));

        try {
            $this->ua()->createArchiveTask(ArchiveTaskRequest::forMessages(self::MESSAGE));
            self::fail('Expected exception');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('$.taskId', $e->getMessage());
        }
    }

    public function testArchiveTaskRequestIsValidated(): void
    {
        foreach ([
            static fn() => ArchiveTaskRequest::forMessages(''),
            static fn() => ArchiveTaskRequest::forMessages('M-1', 'a b'),
            static fn() => ArchiveTaskRequest::forMessages('M-1')->withDescription(' '),
            static fn() => ArchiveTaskRequest::forMessages('M-1')->withDescription(str_repeat('ż', ArchiveTaskRequest::MAX_DESCRIPTION_LENGTH + 1)),
        ] as $i => $build) {
            try {
                $build();
                self::fail('Expected exception #' . $i);
            } catch (InvalidArgumentException) {
            }
        }

        $request = ArchiveTaskRequest::forMessages('M-1')->withDescription(str_repeat('ż', ArchiveTaskRequest::MAX_DESCRIPTION_LENGTH));
        self::assertSame(['M-1'], $request->messageIds());
    }

    public function testSideEffectingArchiveCallsAreNeverRetried(): void
    {
        $ua = $this->retryingClient()->userAgent();
        $calls = [
            static fn() => $ua->createArchiveTask(ArchiveTaskRequest::forMessages(self::MESSAGE)),
            static fn() => $ua->cancelArchiveTask(self::TASK),
            static fn() => $ua->finishArchiveTask(self::TASK),
            static fn() => $ua->finishArchiveTaskAndDeleteMessages(self::TASK),
        ];

        foreach ($calls as $i => $call) {
            $this->http->queue(Http::json(503, []));
            try {
                $call();
                self::fail('Expected exception');
            } catch (ServerException) {
            }
            self::assertCount($i + 1, $this->http->requests, 'one request per call');
        }
    }

    public function testListArchiveTasksMapsTheSchema(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/archive-tasks.200.json'));

        $list = $this->ua()->listArchiveTasks();

        self::assertSame(self::BASE . self::MAILBOX . '/message-archive-task-list', (string) $this->http->lastRequest()->getUri());
        self::assertSame('GET', $this->http->lastRequest()->getMethod());
        self::assertSame(3, $list->total);
        self::assertCount(3, $list->tasks);

        $ready = $list->tasks[0];
        self::assertSame(self::TASK, $ready->taskId);
        self::assertSame(ArchiveTaskStatus::ReadyToDownload, $ready->status);
        self::assertTrue($ready->isReadyToDownload());
        self::assertSame('2026-05-04T08:15:00+00:00', $ready->createdAt->format(\DATE_ATOM));
        self::assertSame('2026-05-11T08:15:00+00:00', $ready->downloadDeadline?->format(\DATE_ATOM));
        self::assertSame(2, $ready->messageCount);
        self::assertFalse($ready->deletesMessages);
        self::assertTrue($ready->canDownloadAndChangeStatus);
        self::assertSame('Archiwum za kwiecień', $ready->description);

        $running = $list->tasks[1];
        self::assertSame(ArchiveTaskStatus::ArchivingInProgress, $running->status);
        self::assertFalse($running->isReadyToDownload());
        self::assertSame('00:15', $running->estimatedTimeToComplete);
        self::assertNull($running->downloadDeadline);
        self::assertNull($running->deletesMessages);
        self::assertFalse($running->canDownloadAndChangeStatus);

        self::assertSame(ArchiveTaskStatus::Error, $list->tasks[2]->status);
        self::assertSame('Nie udało się przygotować archiwum', $list->tasks[2]->errorDescription);
        self::assertSame($running, $list->find('3F2504E0-4F89-41D3-9A0C-0305E82C3302'));
        self::assertNull($list->find('nope'));
    }

    public function testUnknownStatusesAreKeptAsStrings(): void
    {
        $this->http->queue(Http::json(200, ['tasks' => [self::task(['taskStatus' => 'PAUSED'])]]));

        $list = $this->ua()->listArchiveTasks();

        self::assertSame('PAUSED', $list->tasks[0]->status);
        self::assertFalse($list->tasks[0]->isReadyToDownload());
        self::assertNull($list->total);
    }

    public function testRequiredTaskFieldsAreEnforced(): void
    {
        foreach (['taskId', 'creationDate', 'taskStatus', 'numberMessages', 'downloadAndChangeStatus'] as $field) {
            $task = self::task();
            unset($task[$field]);
            $this->http->queue(Http::json(200, ['tasks' => [$task]]));
            try {
                $this->ua()->listArchiveTasks();
                self::fail('Expected exception for ' . $field);
            } catch (UnexpectedResponseException $e) {
                self::assertStringContainsString('$.tasks[0].' . $field, $e->getMessage());
            }
        }
    }

    public function testListQuery(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/archive-tasks.200.json'));

        $this->ua()->listArchiveTasks(ArchiveTaskQuery::create()->withPagination(20, 100)->forTask(self::TASK)->sortBy(ArchiveTaskSortColumn::DownloadDeadline, SortDirection::Ascending));

        parse_str($this->http->lastRequest()->getUri()->getQuery(), $query);
        self::assertSame(['offset' => '20', 'limit' => '100', 'taskId' => self::TASK, 'sortColumn' => 'dateForDownload', 'sortDirection' => 'asc'], $query);
    }

    public function testListQueryIsValidated(): void
    {
        foreach ([
            static fn() => ArchiveTaskQuery::create()->withPagination(-1),
            static fn() => ArchiveTaskQuery::create()->withPagination(0, 0),
            static fn() => ArchiveTaskQuery::create()->withPagination(0, ArchiveTaskQuery::MAX_LIMIT + 1),
            static fn() => ArchiveTaskQuery::create()->forTask(''),
            static fn() => ArchiveTaskQuery::create()->forTask('a b'),
        ] as $i => $build) {
            try {
                $build();
                self::fail('Expected exception #' . $i);
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame([], ArchiveTaskQuery::create()->toQuery());
    }

    public function testArchiveTaskFiltersByIdAndReturnsNullWhenMissing(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/archive-tasks.200.json'), Http::json(200, ['total' => 0, 'tasks' => []]));
        $ua = $this->ua();

        self::assertSame(self::TASK, $ua->archiveTask(self::TASK)?->taskId);
        parse_str($this->http->lastRequest()->getUri()->getQuery(), $query);
        self::assertSame(['taskId' => self::TASK], $query);
        self::assertNull($ua->archiveTask(self::TASK));
    }

    public function testReadingArchivesIsRetried(): void
    {
        $this->http->queue(
            Http::json(503, []),
            Http::fixture(200, 'user-agent-v3/archive-tasks.200.json'),
            Http::json(503, []),
            new Response(200, ['Content-Type' => 'application/zip'], 'PK'),
            Http::json(502, []),
            new Response(200, ['Content-Type' => 'application/zip'], 'PK'),
        );
        $ua = $this->retryingClient()->userAgent();

        self::assertCount(3, $ua->listArchiveTasks()->tasks);
        self::assertSame('PK', (string) $ua->downloadArchive(self::TASK)->stream);
        self::assertSame('PK', (string) $ua->downloadMessageArchive(self::MESSAGE)->stream);
        self::assertCount(6, $this->http->requests);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function changes(): iterable
    {
        yield 'cancel' => ['cancelArchiveTask', 'CANCEL'];
        yield 'finish' => ['finishArchiveTask', 'FINISH_WITHOUT_DELETING_MESSAGES'];
        yield 'finish and delete' => ['finishArchiveTaskAndDeleteMessages', 'FINISH_WITH_DELETING_MESSAGES'];
    }

    #[DataProvider('changes')]
    public function testChangesPatchTheTaskStatus(string $method, string $change): void
    {
        $this->http->queue(Http::json(202, ['total' => 1, 'tasks' => [self::task(['taskStatus' => 'FINISHED', 'withDeletingMessages' => true])]]));

        $ua = $this->ua();
        $result = match ($method) {
            'cancelArchiveTask' => $ua->cancelArchiveTask(self::TASK),
            'finishArchiveTask' => $ua->finishArchiveTask(self::TASK),
            default => $ua->finishArchiveTaskAndDeleteMessages(self::TASK),
        };

        $request = $this->http->lastRequest();
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame(self::BASE . self::MAILBOX . '/message-archive-task', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(['taskId' => self::TASK, 'changeStatus' => $change], Http::jsonBody($request));
        self::assertSame(ArchiveTaskStatus::Finished, $result->tasks[0]->status);
        self::assertTrue($result->tasks[0]->deletesMessages);
    }

    public function testDownloadsAreStreamed(): void
    {
        $this->http->queue(
            new Response(200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="../../archive.zip"', 'Content-Length' => '4'], "PK\x03\x04"),
            new Response(200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="message.zip"'], "PK\x03\x04"),
        );
        $ua = $this->ua();

        $archive = $ua->downloadArchive(self::TASK);
        self::assertSame(self::BASE . self::MAILBOX . '/message-archive-task/' . self::TASK . '/download', (string) $this->http->lastRequest()->getUri());
        self::assertSame('*/*', $this->http->lastRequest()->getHeaderLine('Accept'));
        self::assertSame('application/zip', $archive->contentType);
        self::assertSame('archive.zip', $archive->fileName, 'server-supplied paths are reduced to a base name');
        self::assertSame(4, $archive->size);

        $single = $ua->downloadMessageArchive(self::MESSAGE);
        self::assertSame(self::BASE . self::MAILBOX . '/messages/' . self::MESSAGE . '/message-archive-file', (string) $this->http->lastRequest()->getUri());
        self::assertSame('GET', $this->http->lastRequest()->getMethod());
        self::assertSame('message.zip', $single->fileName);
        self::assertNull($single->size);
    }

    public function testIdsAreValidatedBeforeAnyRequest(): void
    {
        $ua = $this->ua();
        foreach (['', 'a b'] as $id) {
            foreach ([
                static fn() => $ua->downloadArchive($id),
                static fn() => $ua->downloadMessageArchive($id),
                static fn() => $ua->cancelArchiveTask($id),
                static fn() => $ua->finishArchiveTask($id),
                static fn() => $ua->finishArchiveTaskAndDeleteMessages($id),
                static fn() => $ua->archiveTask($id),
            ] as $call) {
                try {
                    $call();
                    self::fail('Expected exception');
                } catch (InvalidArgumentException) {
                }
            }
        }
        self::assertSame([], $this->http->requests);
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function task(array $override = []): array
    {
        return $override + [
            'taskId' => self::TASK,
            'creationDate' => '2026-05-04T08:15:00Z',
            'taskStatus' => 'READY_TO_DOWNLOAD',
            'numberMessages' => 1,
            'downloadAndChangeStatus' => true,
        ];
    }

    private function ua(): UserAgentClient
    {
        return (new EdoreczeniaClient(Environment::integration(), self::MAILBOX, new SpyTokenProvider(), $this->http, Http::factory(), Http::factory()))->userAgent();
    }

    private function retryingClient(): EdoreczeniaClient
    {
        return new EdoreczeniaClient(Environment::integration(), self::MAILBOX, new SpyTokenProvider(), $this->http, Http::factory(), Http::factory(), null, RetryPolicy::exponential(3, 0, 0));
    }
}
