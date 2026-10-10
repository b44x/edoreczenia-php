<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\UserAgent\V3;

use B4x\Edoreczenia\Config\Environment;
use B4x\Edoreczenia\EdoreczeniaClient;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\Tests\Support\Http;
use B4x\Edoreczenia\Tests\Support\MockHttpClient;
use B4x\Edoreczenia\Tests\Support\RecordingLogger;
use B4x\Edoreczenia\Tests\Support\SpyTokenProvider;
use B4x\Edoreczenia\UserAgent\V3\Enum\MessageStatus;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortDirection;
use B4x\Edoreczenia\UserAgent\V3\Enum\ThreadMessageSortColumn;
use B4x\Edoreczenia\UserAgent\V3\Enum\ThreadSortColumn;
use B4x\Edoreczenia\UserAgent\V3\Request\ThreadMessageQuery;
use B4x\Edoreczenia\UserAgent\V3\Request\ThreadQuery;
use PHPUnit\Framework\TestCase;

final class ThreadsTest extends TestCase
{
    private const MAILBOX = 'AE:PL-12345-67890-ABCDE-12';

    private MockHttpClient $http;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->logger = new RecordingLogger();
    }

    public function testListThreadsMapsContractShape(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/threads.200.json'));

        $list = $this->client()->userAgent()->listThreads(ThreadQuery::create()->withCaseId('ZNAK-SPRAWY.1.2026')->sortBy(ThreadSortColumn::LastMessageEventDate));

        $uri = $this->http->lastRequest()->getUri();
        self::assertSame('/api/v3/' . self::MAILBOX . '/threads', $uri->getPath());
        parse_str($uri->getQuery(), $query);
        self::assertSame(['caseId' => 'ZNAK-SPRAWY.1.2026', 'sortColumn' => 'lastMessageEventDate', 'sortDirection' => 'desc'], $query);

        self::assertSame(1, $list->total);
        $thread = $list->threads[0];
        self::assertSame('T-1', $thread->threadId);
        self::assertFalse($thread->hasUnread);
        self::assertSame(['ZNAK-SPRAWY.1.2026'], $thread->caseIds);
        self::assertSame('MINISTERSTWO POCZT I TELEGRAFÓW', $thread->senders[0]->displayName());
        self::assertNull($thread->senders[0]->deliveryAddress);
        self::assertSame('ADAM MALINOWSKI', $thread->recipients[0]->displayName());
        self::assertSame('2017-07-21T17:32:28+00:00', $thread->firstMessageEventDate?->format(\DATE_ATOM), 'misspelled contract field is read');
        self::assertSame('2017-07-22T08:00:00+00:00', $thread->lastMessageEventDate?->format(\DATE_ATOM));
        self::assertSame(2, $thread->messageCount);
    }

    public function testCorrectedFieldNamesAreAcceptedToo(): void
    {
        $this->http->queue(Http::json(200, ['threads' => [['threadId' => 'T-2', 'firstMessageEventDate' => '2026-01-01T00:00:00Z']]]));

        $thread = $this->client()->userAgent()->listThreads()->threads[0];

        self::assertSame('2026-01-01T00:00:00+00:00', $thread->firstMessageEventDate?->format(\DATE_ATOM));
        self::assertNull($thread->hasUnread);
    }

    public function testGetThreadReturnsListedMessages(): void
    {
        $this->http->queue(Http::fixture(200, 'user-agent-v3/thread.200.json'));

        $thread = $this->client()->userAgent()->getThread('T-1', ThreadMessageQuery::create()->withPagination(0, 50)->sortBy(ThreadMessageSortColumn::CaseId, SortDirection::Ascending));

        $uri = $this->http->lastRequest()->getUri();
        self::assertSame('/api/v3/' . self::MAILBOX . '/threads/T-1', $uri->getPath());
        parse_str($uri->getQuery(), $query);
        self::assertSame(['offset' => '0', 'limit' => '50', 'sortColumn' => 'caseID', 'sortDirection' => 'asc'], $query);
        self::assertSame('T-1', $thread->threadId);
        self::assertSame(MessageStatus::Delivered, $thread->messages->messages[0]->status);
        self::assertSame(1, $thread->messages->total);
    }

    public function testThreadIdIsEncodedAndMasked(): void
    {
        $this->http->queue(Http::json(200, ['threadId' => 'a/b']));

        $thread = $this->client()->userAgent()->getThread('a/b');

        self::assertSame('/api/v3/' . self::MAILBOX . '/threads/a%2Fb', $this->http->lastRequest()->getUri()->getPath());
        self::assertSame([], $thread->messages->messages);
        self::assertNull($thread->messages->total);
        self::assertStringNotContainsString(self::MAILBOX, $this->logger->dump());
    }

    public function testRejectsBlankThreadIdLocally(): void
    {
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->client()->userAgent()->getThread(' ');
        } finally {
            self::assertSame([], $this->http->requests);
        }
    }

    public function testPageSizeLimitsDifferPerOperation(): void
    {
        self::assertSame(2000, ThreadQuery::create()->withPagination(0, 2000)->toQuery()['limit']);

        $this->expectException(InvalidArgumentException::class);
        ThreadMessageQuery::create()->withPagination(0, 51);
    }

    public function testFiltersAreImmutableAndValidated(): void
    {
        $base = ThreadQuery::create();
        $base->withMessageTitle('X');
        self::assertSame([], $base->toQuery());

        $this->expectException(InvalidArgumentException::class);
        ThreadQuery::create()->eventBetween(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-01-01'));
    }

    private function client(): EdoreczeniaClient
    {
        return new EdoreczeniaClient(Environment::integration(), self::MAILBOX, new SpyTokenProvider(), $this->http, Http::factory(), Http::factory(), $this->logger);
    }
}
