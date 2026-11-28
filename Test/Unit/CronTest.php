<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Unit;

use MageOS\GiftCard\Cron\GiveBackOrphans;
use MageOS\GiftCard\Cron\IssueMissing;
use MageOS\GiftCard\Cron\RemindExpiry;
use MageOS\GiftCard\Cron\SendGifts;
use MageOS\GiftCard\Model\GiftCards;
use MageOS\GiftCard\Model\Issuer;
use MageOS\GiftCard\Model\Mailer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the cron jobs that repair what a dead request left behind.
 */
class CronTest extends TestCase
{
    /**
     * A debit is given back only when no order has its number; the comparison ignores case.
     */
    public function testOnlyDebitsOfOrdersNeverSavedAreGivenBack(): void
    {
        $giftCards = $this->createMock(GiftCards::class);
        $giftCards->method('getDebitsBetween')->willReturn([
            ['code' => 'SAVED', 'order_increment_id' => 'A000000001'],
            ['code' => 'ORPHAN', 'order_increment_id' => '000000002'],
        ]);
        $giftCards->expects($this->once())->method('creditOrphan')->with('ORPHAN', '000000002')->willReturn(30.0);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        (new GiveBackOrphans($giftCards, $this->orders(['a000000001']), $this->lock(true), $logger))->execute();
    }

    /**
     * When the orders cannot be read, nothing is given back.
     */
    public function testNothingIsGivenBackWhenTheOrdersCannotBeRead(): void
    {
        $giftCards = $this->createMock(GiftCards::class);
        $giftCards->method('getDebitsBetween')->willReturn([['code' => 'X', 'order_increment_id' => '000000002']]);
        $giftCards->expects($this->never())->method('creditOrphan');
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willThrowException(new \RuntimeException('database is away'));
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $logger = $this->createStub(LoggerInterface::class);
        $cron = new GiveBackOrphans($giftCards, $resource, $this->lock(true), $logger);

        $this->expectException(\RuntimeException::class);
        $cron->execute();
    }

    /**
     * A second run started while one is still at work does nothing: no card is e-mailed or given back twice.
     */
    public function testARunAlreadyAtWorkIsNotJoined(): void
    {
        $giftCards = $this->createMock(GiftCards::class);
        $giftCards->expects($this->never())->method('getDue');
        $giftCards->expects($this->never())->method('getDebitsBetween');
        $giftCards->expects($this->never())->method('getExpiring');
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');
        $logger = $this->createStub(LoggerInterface::class);
        $lock = $this->lock(false);

        (new SendGifts($giftCards, $this->createStub(Mailer::class), $lock))->execute();
        (new GiveBackOrphans($giftCards, $resource, $lock, $logger))->execute();
        $this->issueMissing($giftCards, $resource, $lock)->execute();
        (new RemindExpiry($giftCards, $this->createStub(Mailer::class), $lock, $this->config('15')))->execute();
    }

    /**
     * Each expiring card is reminded once: a card another run already claimed is not e-mailed again.
     */
    public function testExpiryReminderIsSentOncePerCard(): void
    {
        $giftCards = $this->createStub(GiftCards::class);
        $giftCards->method('getExpiring')->willReturn([
            ['giftcard_id' => 1, 'reminder_email' => 'zoe@example.com'],
            ['giftcard_id' => 2, 'reminder_email' => 'max@example.com'],
        ]);
        $giftCards->method('claimReminder')->willReturnCallback(fn (int $id): bool => $id === 1);
        $sent = [];
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('remind')->willReturnCallback(function (array $card, string $email) use (&$sent): bool {
            $sent[] = $email;
            return true;
        });

        (new RemindExpiry($giftCards, $mailer, $this->lock(true), $this->config('15')))->execute();

        $this->assertSame(['zoe@example.com'], $sent);
    }

    /**
     * A reminder whose e-mail fails is released, so the next run sends it; a reminder sent is kept.
     */
    public function testFailedExpiryReminderIsSentAgainByTheNextRun(): void
    {
        $giftCards = $this->createMock(GiftCards::class);
        $giftCards->method('getExpiring')->willReturn([
            ['giftcard_id' => 1, 'reminder_email' => 'zoe@example.com'],
            ['giftcard_id' => 2, 'reminder_email' => 'max@example.com'],
        ]);
        $giftCards->method('claimReminder')->willReturn(true);
        $giftCards->expects($this->once())->method('releaseReminder')->with(1);
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('remind')->willReturnCallback(fn (array $card): bool => $card['giftcard_id'] === 2);

        (new RemindExpiry($giftCards, $mailer, $this->lock(true), $this->config('15')))->execute();
    }

    /**
     * Without a number of days in the configuration, no reminder is looked for.
     */
    public function testNoExpiryReminderWhenNotConfigured(): void
    {
        $giftCards = $this->createMock(GiftCards::class);
        $giftCards->expects($this->never())->method('getExpiring');

        (new RemindExpiry($giftCards, $this->createStub(Mailer::class), $this->lock(true), $this->config('')))
            ->execute();
    }

    /**
     * Configuration with this number of reminder days.
     *
     * @param string $days
     * @return ScopeConfigInterface
     */
    private function config(string $days): ScopeConfigInterface
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn($days);
        return $config;
    }

    /**
     * How many cards a line lacks, from its units paid and refunded and the cards it issued.
     *
     * @param int $expected
     * @param int $paid
     * @param int $refunded
     * @param int $issued
     * @param int $active
     */
    #[DataProvider('lines')]
    public function testMissingCards(int $expected, int $paid, int $refunded, int $issued, int $active): void
    {
        $cron = $this->issueMissing(
            $this->createStub(GiftCards::class),
            $this->createStub(ResourceConnection::class),
            $this->lock(true)
        );

        $this->assertSame($expected, $cron->missing($paid, $refunded, $issued, $active));
    }

    /**
     * Lines as [cards missing, units paid, units refunded, cards issued, cards active].
     *
     * @return array<string, array{int, int, int, int, int}>
     */
    public static function lines(): array
    {
        return [
            'all issued' => [0, 2, 0, 2, 2],
            'none issued' => [2, 2, 0, 0, 0],
            'issue stopped half-way' => [1, 2, 0, 1, 1],
            'a card disabled by an admin is not replaced' => [0, 2, 0, 2, 1],
            'a refunded unit gets no card' => [1, 2, 1, 0, 0],
            'refunded unit whose card was disabled' => [0, 2, 1, 2, 1],
            'half-way, then the issued card refunded' => [1, 2, 1, 1, 0],
            'more cards than units' => [0, 1, 0, 2, 2],
        ];
    }

    /**
     * Cron that issues missing cards, on fakes.
     *
     * @param GiftCards $giftCards
     * @param ResourceConnection $resource
     * @param LockManagerInterface $lock
     * @return IssueMissing
     */
    private function issueMissing(
        GiftCards $giftCards,
        ResourceConnection $resource,
        LockManagerInterface $lock
    ): IssueMissing {
        return new IssueMissing(
            $giftCards,
            $this->createStub(Issuer::class),
            $resource,
            $this->createStub(OrderRepositoryInterface::class),
            $lock,
            $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * Lock that is free, or held by another run.
     *
     * @param bool $free
     * @return LockManagerInterface
     */
    private function lock(bool $free): LockManagerInterface
    {
        $lock = $this->createStub(LockManagerInterface::class);
        $lock->method('lock')->willReturn($free);
        return $lock;
    }

    /**
     * Sales database where these order numbers exist.
     *
     * @param string[] $numbers
     * @return ResourceConnection
     */
    private function orders(array $numbers): ResourceConnection
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturn($numbers);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturn('sales_order');
        return $resource;
    }
}
