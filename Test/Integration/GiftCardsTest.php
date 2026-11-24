<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Integration;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Tests the gift card balances against the database.
 */
#[DbIsolation(true)]
class GiftCardsTest extends TestCase
{
    /**
     * A debit larger than the balance changes nothing; the others move the balance.
     */
    public function testDebitNeverOverdrawsTheCard(): void
    {
        $giftCards = Bootstrap::getObjectManager()->get(GiftCards::class);
        $code = $giftCards->create(50.0);

        $this->assertFalse($giftCards->debit($code, 60.0));
        $this->assertSame(50.0, $giftCards->getBalance($code));
        $this->assertTrue($giftCards->debit($code, 20.0));
        $giftCards->credit($code, 5.0);
        $this->assertSame(35.0, $giftCards->getBalance($code));
    }

    /**
     * An unknown code has no balance and cannot be debited.
     */
    public function testUnknownCodeHasNoBalance(): void
    {
        $giftCards = Bootstrap::getObjectManager()->get(GiftCards::class);

        $this->assertNull($giftCards->getBalance('NO-SUCH-CODE'));
        $this->assertFalse($giftCards->debit('NO-SUCH-CODE', 1.0));
    }

    /**
     * A disabled card cannot be spent until it is enabled again; the admin can set its balance.
     */
    public function testDisabledCardCannotBeSpent(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $giftCards = $objectManager->get(GiftCards::class);
        $code = $giftCards->create(50.0, ['order_id' => 7]);
        $resource = $objectManager->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $select = $connection->select()
            ->from($resource->getTableName('mageos_giftcard'), ['giftcard_id', 'initial_balance', 'order_id'])
            ->where('code = ?', $code);
        $row = $connection->fetchRow($select);
        $this->assertSame([50.0, 7], [(float) $row['initial_balance'], (int) $row['order_id']]);

        $giftCards->update((int) $row['giftcard_id'], ['is_active' => false]);
        $this->assertNull($giftCards->getBalance($code));
        $this->assertFalse($giftCards->debit($code, 10.0));

        $giftCards->update(
            (int) $row['giftcard_id'],
            ['is_active' => true, 'balance' => 30.0, 'expected_balance' => 50.0]
        );
        $this->assertSame(30.0, $giftCards->getBalance($code));
    }

    /**
     * A new balance is refused when the card was used since the admin saw it.
     */
    public function testStaleBalanceChangeIsRefused(): void
    {
        $giftCards = Bootstrap::getObjectManager()->get(GiftCards::class);
        $code = $giftCards->create(50.0);
        $giftCards->debit($code, 20.0, '000000010');

        try {
            $giftCards->update($this->id($code), ['balance' => 45.0, 'expected_balance' => 50.0], 'jane');
            $this->fail('A stale balance should be refused.');
        } catch (LocalizedException $e) {
            $this->assertSame(30.0, $giftCards->getBalance($code));
        }
    }

    /**
     * Money given back to an expired card can be spent again, for a grace period.
     */
    #[\Magento\TestFramework\Fixture\Config(GiftCards::XML_VALIDITY, '12')]
    public function testCreditExtendsAnExpiredCard(): void
    {
        $giftCards = Bootstrap::getObjectManager()->get(GiftCards::class);
        $code = $giftCards->create(50.0, ['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)]);

        $this->assertTrue($giftCards->credit($code, 10.0, '000000011'));
        $this->assertSame(60.0, $giftCards->getBalance($code));
    }

    /**
     * A gift shows up in its owner's account only once e-mailed, so a scheduled gift stays a surprise.
     */
    public function testScheduledGiftIsHiddenFromTheAccountUntilSent(): void
    {
        $giftCards = Bootstrap::getObjectManager()->get(GiftCards::class);
        $code = $giftCards->create(25.0, [
            'customer_id' => 1,
            'recipient_email' => 'friend@example.com',
            'send_at' => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        $this->assertNotContains($code, array_column($giftCards->getByCustomer(1), 'code'));

        $giftCards->markSent($this->id($code), 'friend@example.com');
        $this->assertContains($code, array_column($giftCards->getByCustomer(1), 'code'));
    }

    /**
     * After MAX_SEND_ATTEMPTS failed e-mails, the cron stops trying.
     */
    public function testGiftIsNoLongerDueAfterTooManyFailures(): void
    {
        $giftCards = Bootstrap::getObjectManager()->get(GiftCards::class);
        $code = $giftCards->create(25.0, [
            'recipient_email' => 'friend@example.com',
            'send_at' => gmdate('Y-m-d H:i:s', time() - 60),
        ]);
        for ($i = 0; $i < GiftCards::MAX_SEND_ATTEMPTS; $i++) {
            $this->assertContains($code, array_column($giftCards->getDue(), 'code'));
            $giftCards->markFailed($this->id($code));
        }
        $this->assertNotContains($code, array_column($giftCards->getDue(), 'code'));
    }

    /**
     * Every balance change is recorded with the balance it left, the order and the admin user.
     */
    public function testHistoryRecordsEveryChange(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $giftCards = $objectManager->get(GiftCards::class);
        $code = $giftCards->create(50.0, ['order_id' => 7], '000000007');
        $this->assertFalse($giftCards->debit($code, 80.0, '000000008'));
        $giftCards->debit($code, 20.0, '000000009');
        $giftCards->credit($code, 5.0, '000000009', GiftCards::CANCELED);
        $resource = $objectManager->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $id = (int) $connection->fetchOne(
            $connection->select()->from($resource->getTableName('mageos_giftcard'), 'giftcard_id')
                ->where('code = ?', $code)
        );
        $giftCards->update($id, ['balance' => 40.0, 'expected_balance' => 35.0, 'is_active' => false], 'jane');
        $giftCards->update($id, ['balance' => 40.0, 'expected_balance' => 40.0, 'is_active' => false], 'jane');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    $resource->getTableName('mageos_giftcard_history'),
                    ['action', 'amount', 'balance', 'order_increment_id', 'admin_user']
                )
                ->where('giftcard_id = ?', $id)
                ->order('history_id')
        );
        $this->assertSame([
            ['issued', 50.0, 50.0, '000000007', null],
            ['used', -20.0, 30.0, '000000009', null],
            ['canceled', 5.0, 35.0, '000000009', null],
            ['adjusted', 5.0, 40.0, null, 'jane'],
            ['disabled', 0.0, 40.0, null, 'jane'],
        ], array_map(fn (array $row) => [
            $row['action'],
            (float) $row['amount'],
            (float) $row['balance'],
            $row['order_increment_id'],
            $row['admin_user'],
        ], $rows));
    }

    /**
     * An expired card cannot be spent; the admin can extend it.
     */
    public function testExpiredCardCannotBeSpent(): void
    {
        $giftCards = Bootstrap::getObjectManager()->get(GiftCards::class);
        $code = $giftCards->create(50.0, ['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)]);

        $this->assertNull($giftCards->getBalance($code));
        $this->assertFalse($giftCards->debit($code, 10.0));
        $giftCards->update($this->id($code), ['expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
        $this->assertSame(50.0, $giftCards->getBalance($code));
    }

    /**
     * A gift is due once its send date has come, and only until it is sent; a refund disables the unsent one first.
     */
    public function testGiftIsDueOnItsDateAndRevokedFirstWhenRefunded(): void
    {
        $giftCards = Bootstrap::getObjectManager()->get(GiftCards::class);
        $later = $giftCards->create(25.0, [
            'order_id' => 99001,
            'order_item_id' => 99101,
            'recipient_email' => 'later@example.com',
            'send_at' => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        $now = $giftCards->create(25.0, [
            'order_id' => 99001,
            'order_item_id' => 99101,
            'recipient_email' => 'now@example.com',
            'send_at' => gmdate('Y-m-d H:i:s', time() - 60),
        ]);
        $due = array_column($giftCards->getDue(), 'code');
        $this->assertContains($now, $due);
        $this->assertNotContains($later, $due);

        $giftCards->markSent($this->id($now), 'now@example.com');
        $this->assertNotContains($now, array_column($giftCards->getDue(), 'code'));

        $this->assertSame([], $giftCards->revoke(99101, 1, 'Credit memo'));
        $this->assertNull($giftCards->getBalance($later));
        $this->assertSame(25.0, $giftCards->getBalance($now));
    }

    /**
     * A refund disables an unspent card before a spent one, only on the refunded line, and returns spent cards.
     */
    public function testRefundDisablesTheUnspentCardOfTheRefundedLine(): void
    {
        $giftCards = Bootstrap::getObjectManager()->get(GiftCards::class);
        $spent = $giftCards->create(100.0, ['order_id' => 99002, 'order_item_id' => 99201]);
        $full = $giftCards->create(100.0, ['order_id' => 99002, 'order_item_id' => 99201]);
        $otherLine = $giftCards->create(25.0, ['order_id' => 99002, 'order_item_id' => 99202]);
        $giftCards->debit($spent, 100.0, '000000012');

        $this->assertSame([], $giftCards->revoke(99201, 1, 'Credit memo'));
        $this->assertNull($giftCards->getBalance($full));
        $this->assertSame(25.0, $giftCards->getBalance($otherLine));

        $this->assertSame([$spent], array_column($giftCards->revoke(99201, 1, 'Credit memo'), 'code'));
    }

    /**
     * What an order that was never saved took from a card is given back once, with nothing left to give after.
     */
    public function testDebitOfAnOrderNeverSavedIsGivenBackOnce(): void
    {
        $giftCards = Bootstrap::getObjectManager()->get(GiftCards::class);
        $code = $giftCards->create(50.0);
        $giftCards->debit($code, 20.0, '000099001');
        $giftCards->debit($code, 5.0, '');

        $debits = $giftCards->getDebitsBetween(
            gmdate('Y-m-d H:i:s', time() - 3600),
            gmdate('Y-m-d H:i:s', time() + 60)
        );
        $this->assertSame(
            [['order_increment_id' => '000099001', 'code' => $code]],
            array_values(array_filter($debits, fn (array $debit) => $debit['code'] === $code))
        );

        $this->assertSame(20.0, $giftCards->creditOrphan($code, '000099001'));
        $this->assertSame(0.0, $giftCards->creditOrphan($code, '000099001'));
        $this->assertSame(45.0, $giftCards->getBalance($code));
    }

    /**
     * An order holds the value of its active cards, whatever was spent of them; lines know their cards.
     */
    public function testOrderHoldsItsActiveCards(): void
    {
        $giftCards = Bootstrap::getObjectManager()->get(GiftCards::class);
        $spent = $giftCards->create(50.0, ['order_id' => 99003, 'order_item_id' => 99301]);
        $disabled = $giftCards->create(50.0, ['order_id' => 99003, 'order_item_id' => 99301]);
        $giftCards->debit($spent, 30.0, '000099002');
        $giftCards->update($this->id($disabled), ['is_active' => false]);

        $this->assertEqualsWithDelta(49.99, $giftCards->getHeldByOrder(99003), 0.0001);
        $this->assertSame(0.0, $giftCards->getHeldByOrder(99004));
        $this->assertSame([99301 => ['all' => 2, 'active' => 1]], $giftCards->countByOrderItems([99301, 99302]));
    }

    /**
     * Expiry from the configured validity, in months.
     */
    #[\Magento\TestFramework\Fixture\Config(GiftCards::XML_VALIDITY, '12')]
    public function testNewCardExpiresAfterTheConfiguredValidity(): void
    {
        $giftCards = Bootstrap::getObjectManager()->get(GiftCards::class);
        $card = $giftCards->get($this->id($giftCards->create(10.0)));

        $this->assertEqualsWithDelta(strtotime('+12 months'), strtotime($card['expires_at'] . ' UTC'), 120);
    }

    /**
     * ID of the card with this code.
     *
     * @param string $code
     */
    private function id(string $code): int
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        return (int) $connection->fetchOne(
            $connection->select()->from($resource->getTableName('mageos_giftcard'), 'giftcard_id')
                ->where('code = ?', $code)
        );
    }
}
