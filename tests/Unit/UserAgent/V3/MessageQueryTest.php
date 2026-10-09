<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\UserAgent\V3;

use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\UserAgent\V3\Enum\ShippingService;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortColumn;
use B4x\Edoreczenia\UserAgent\V3\Enum\SortDirection;
use B4x\Edoreczenia\UserAgent\V3\Request\MessageQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageQueryTest extends TestCase
{
    public function testBuildsAllContractParameters(): void
    {
        $query = MessageQuery::create()
            ->fromSender('MINISTERSTWO')
            ->toRecipient('AE:PL-1')
            ->withSubject('Wezwanie')
            ->withAttachments()
            ->withShippingService(ShippingService::Hybrid)
            ->eventBetween(new \DateTimeImmutable('2026-01-01T00:00:00Z'), new \DateTimeImmutable('2026-01-31T23:59:59Z'))
            ->receivedBetween(null, new \DateTimeImmutable('2026-02-01T00:00:00Z'))
            ->sortBy(SortColumn::ReceiptDate, SortDirection::Ascending)
            ->groupMassMessages()
            ->forMassMessage('mass-1')
            ->toQuery();

        self::assertSame([
            'sender' => 'MINISTERSTWO',
            'recipient' => 'AE:PL-1',
            'subject' => 'Wezwanie',
            'attachments' => true,
            'shippingService' => 'hybrid',
            'eventDateFrom' => '2026-01-01T00:00:00Z',
            'eventDateTo' => '2026-01-31T23:59:59Z',
            'receiptDateTo' => '2026-02-01T00:00:00Z',
            'sortColumn' => 'reciptDate',
            'sortDirection' => 'asc',
            'massMessagesGrouped' => true,
            'massMessageId' => 'mass-1',
        ], $query);
    }

    public function testIsImmutable(): void
    {
        $base = MessageQuery::create();
        $base->inFolder('INBOX');

        self::assertSame([], $base->toQuery());
    }

    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'limit too high' => [static fn() => MessageQuery::create()->withPagination(0, 2001)];
        yield 'limit zero' => [static fn() => MessageQuery::create()->withPagination(0, 0)];
        yield 'negative offset' => [static fn() => MessageQuery::create()->withPagination(-1)];
        yield 'empty folder' => [static fn() => MessageQuery::create()->inFolder(' ')];
        yield 'reversed range' => [static fn() => MessageQuery::create()->submittedBetween(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-01-01'))];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidValues(\Closure $build): void
    {
        $this->expectException(InvalidArgumentException::class);

        $build();
    }
}
