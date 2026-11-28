<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Unit;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the gift card store on a fake database: batches, erasure of personal data, expiry reminders.
 */
class GiftCardsTest extends TestCase
{
    /**
     * Transaction calls, in order: "begin", "commit" or "rollBack".
     *
     * @var string[]
     */
    private array $transactions = [];

    /**
     * Rows inserted in the card table, as their balance.
     *
     * @var float[]
     */
    private array $inserted = [];

    /**
     * Expiry of the rows inserted in the card table.
     *
     * @var array<int, mixed>
     */
    private array $insertedExpiry = [];

    /**
     * UPDATE statements, as [table, columns set, conditions].
     *
     * @var array<int, array{string, array<string, mixed>, mixed}>
     */
    private array $updates = [];

    /**
     * Arguments of the from() and columns() calls of the SELECT statements built.
     *
     * @var array<int, array<int|string, mixed>>
     */
    private array $selected = [];

    /**
     * Card row returned when one is read.
     *
     * @var array<string, mixed>
     */
    private array $card = [];

    /**
     * A batch creates all its cards, in one transaction, and returns their codes in order.
     */
    public function testBatchCreatesEveryCard(): void
    {
        $codes = $this->giftCards()->createBatch([[10.0, []], [20.0, ['store_id' => 1]]], 'admin');

        $this->assertCount(2, $codes);
        $this->assertSame([10.0, 20.0], $this->inserted);
        $this->assertSame(['begin', 'commit'], $this->transactions);
    }

    /**
     * The default validity is set on the cards before they are created, once: the list of codes downloaded
     * gives the expiry recorded. An expiry entered, or none, is kept.
     */
    public function testBatchCardsCarryTheExpiryRecorded(): void
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn('12');
        $giftCards = $this->giftCards(null, $config);

        $cards = $giftCards->withValidity([
            [10.0, []],
            [20.0, ['expires_at' => '2027-06-30 06:59:59']],
            [30.0, ['expires_at' => null]],
            [40.0, []],
        ]);
        $giftCards->createBatch($cards);

        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $cards[0][1]['expires_at']);
        $this->assertSame('2027-06-30 06:59:59', $cards[1][1]['expires_at']);
        $this->assertNull($cards[2][1]['expires_at']);
        $this->assertSame($cards[0][1]['expires_at'], $cards[3][1]['expires_at']);
        $this->assertSame(array_map(fn (array $card) => $card[1]['expires_at'], $cards), $this->insertedExpiry);
    }

    /**
     * When one card of a batch cannot be created, the transaction is rolled back: no card of the batch is left.
     */
    public function testBatchCreatesNoCardWhenOneFails(): void
    {
        $giftCards = $this->giftCards(30.0);

        try {
            $giftCards->createBatch([[10.0, []], [20.0, []], [30.0, []]]);
            $this->fail('The batch should fail.');
        } catch (\RuntimeException) {
            $this->assertSame(['begin', 'rollBack'], $this->transactions);
        }
    }

    /**
     * Erasing a customer's data never changes a balance, a status, an expiry, an order link or an amount:
     * it only empties personal columns, and the e-mail address in the history comments.
     */
    public function testForgettingACustomerKeepsBalancesAndAccounting(): void
    {
        $this->giftCards()->forgetCustomer(7, 'zoe@example.com');

        $columns = [];
        foreach ($this->updates as [$table, $set]) {
            foreach ($set as $column => $value) {
                $columns[] = $table . '.' . $column;
                $this->assertNull($value, $column . ' must be emptied, not rewritten');
            }
        }
        $this->assertSame([], array_values(array_diff($columns, [
            'mageos_giftcard.customer_id',
            'mageos_giftcard.recipient_email',
            'mageos_giftcard.recipient_name',
            'mageos_giftcard.sender_name',
            'mageos_giftcard.message',
            'mageos_giftcard_history.comment',
        ])));
        $this->assertContains('mageos_giftcard.customer_id', $columns);
        $this->assertContains('mageos_giftcard_history.comment', $columns);
        $this->assertSame(['begin', 'commit'], $this->transactions);
    }

    /**
     * A new expiry date makes the expiry reminder due again; a change of status alone does not.
     */
    public function testANewExpiryDateMakesTheReminderDueAgain(): void
    {
        $this->card = [
            'giftcard_id' => 5,
            'code' => 'ABC',
            'balance' => '20.0000',
            'is_active' => 1,
            'expires_at' => '2026-12-01 00:00:00',
            'reminded_at' => '2026-11-20 00:00:00',
        ];
        $giftCards = $this->giftCards();

        $giftCards->update(5, ['is_active' => false]);
        $giftCards->update(5, ['expires_at' => '2027-06-01 00:00:00']);

        $this->assertArrayNotHasKey('reminded_at', $this->updates[0][1]);
        $this->assertArrayHasKey('reminded_at', $this->updates[1][1]);
        $this->assertNull($this->updates[1][1]['reminded_at']);
        $this->assertSame('20.0000', $this->updates[1][1]['balance']);
    }

    /**
     * The expiry reminder never takes the address of an order placed by an account: once the account is deleted,
     * core keeps that order's e-mail address. Only a guest's order gives the address, for a card never given away.
     */
    public function testExpiryReminderTakesTheOrderAddressOfGuestsOnly(): void
    {
        $this->giftCards()->getExpiring(15);

        $email = '';
        foreach ($this->selected as $args) {
            if (isset($args[0]['reminder_email'])) {
                $email = (string) $args[0]['reminder_email'];
            }
        }
        $this->assertStringContainsString(
            "WHEN o.customer_is_guest = 1 AND c.sent_at IS NULL THEN o.customer_email",
            $email
        );
        $this->assertSame(1, substr_count($email, 'o.customer_email'));
    }

    /**
     * The history line of a reminder does not hold the address it was e-mailed to.
     */
    public function testReminderHistoryHoldsNoEmailAddress(): void
    {
        $this->giftCards()->markReminded(1);

        $comment = null;
        foreach ($this->selected as $args) {
            if (isset($args[1]['comment'])) {
                $comment = (string) $args[1]['comment'];
            }
        }
        $this->assertSame('NULL', $comment);
    }

    /**
     * Deleting a customer empties the comments of the reminder lines of its cards and of the gifts it bought,
     * whatever address they were written with.
     */
    public function testForgettingACustomerEmptiesTheReminderLinesOfItsCards(): void
    {
        $this->giftCards()->forgetCustomer(7, 'zoe@example.com');

        $cleared = array_filter(
            $this->updates,
            fn (array $update): bool => $update[0] === 'mageos_giftcard_history'
                && is_array($update[2])
                && ($update[2]['action = ?'] ?? null) === GiftCards::REMINDED
                && array_key_exists('giftcard_id IN (?)', $update[2])
        );
        $this->assertCount(1, $cleared);
        $this->assertSame(['comment' => null], array_values($cleared)[0][1]);
    }

    /**
     * Gift card store on a fake connection that records what is written.
     *
     * @param float|null $failOn balance of the card whose insert fails
     * @param ScopeConfigInterface|null $config
     * @return GiftCards
     */
    private function giftCards(?float $failOn = null, ?ScopeConfigInterface $config = null): GiftCards
    {
        $select = $this->createStub(Select::class);
        foreach (['where', 'join', 'joinLeft', 'order', 'limit', 'forUpdate', 'distinct'] as $m) {
            $select->method($m)->willReturnSelf();
        }
        foreach (['from', 'columns'] as $m) {
            $select->method($m)->willReturnCallback(function (...$args) use ($select) {
                $this->selected[] = $args;
                return $select;
            });
        }
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quote')->willReturnCallback(fn ($value): string => "'" . $value . "'");
        $connection->method('beginTransaction')->willReturnCallback(function () use ($connection) {
            $this->transactions[] = 'begin';
            return $connection;
        });
        $connection->method('commit')->willReturnCallback(function () use ($connection) {
            $this->transactions[] = 'commit';
            return $connection;
        });
        $connection->method('rollBack')->willReturnCallback(function () use ($connection) {
            $this->transactions[] = 'rollBack';
            return $connection;
        });
        $connection->method('insert')->willReturnCallback(function (string $table, array $row) use ($failOn): int {
            if ($row['balance'] === $failOn) {
                throw new \RuntimeException('Duplicate entry');
            }
            $this->inserted[] = $row['balance'];
            $this->insertedExpiry[] = $row['expires_at'] ?? null;
            return 1;
        });
        $connection->method('update')->willReturnCallback(function (string $table, array $set, $where = ''): int {
            $this->updates[] = [$table, $set, $where];
            return 1;
        });
        $connection->method('fetchRow')->willReturnCallback(fn (): array => $this->card);
        $connection->method('fetchAll')->willReturn([]);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('formatDate')->willReturn('Jun 1, 2027');
        return new GiftCards(
            $resource,
            $config ?? $this->createStub(ScopeConfigInterface::class),
            $this->createStub(CustomerRepositoryInterface::class),
            $this->createStub(StoreManagerInterface::class),
            $timezone
        );
    }
}
