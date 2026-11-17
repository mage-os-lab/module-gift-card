<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Unit;

use MageOS\GiftCard\Model\GiftCards;
use MageOS\GiftCard\Observer\Redeem;
use MageOS\GiftCard\Observer\Restore;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use PHPUnit\Framework\TestCase;

/**
 * Tests the balance moves around order placement, cancellation and refunds.
 */
class ObserversTest extends TestCase
{
    /**
     * Credits made on the fake gift card store, as [code, amount].
     *
     * @var array<int, array{string, float}>
     */
    private array $credits = [];

    /**
     * When the debit fails the order is refused and carries no gift card data for Restore to give back.
     */
    public function testOrderIsRefusedWhenTheBalanceNoLongerCoversTheAmount(): void
    {
        $order = new DataObject();
        $quote = new DataObject(['giftcard_code' => 'ABC', 'base_giftcard_amount' => 30.0, 'giftcard_amount' => 30.0]);

        try {
            (new Redeem($this->giftCards(false)))->execute(new Observer(['quote' => $quote, 'order' => $order]));
            $this->fail('The order should be refused.');
        } catch (LocalizedException $e) {
            $this->assertNull($order->getData('base_giftcard_amount'));
        }
    }

    /**
     * A successful debit copies the code and amounts to the order.
     */
    public function testSuccessfulDebitCopiesTheAmountsToTheOrder(): void
    {
        $order = new DataObject();
        $quote = new DataObject(['giftcard_code' => 'ABC', 'base_giftcard_amount' => 30.0, 'giftcard_amount' => 33.0]);

        (new Redeem($this->giftCards(true)))->execute(new Observer(['quote' => $quote, 'order' => $order]));

        $this->assertSame(['ABC', 30.0, 33.0], [
            $order->getData('giftcard_code'),
            $order->getData('base_giftcard_amount'),
            $order->getData('giftcard_amount'),
        ]);
    }

    /**
     * Canceling an order gives back the part of the gift card that no paid invoice used.
     */
    public function testCancelGivesBackThePartNotInvoiced(): void
    {
        $paid = $this->createStub(Invoice::class);
        $paid->method('isCanceled')->willReturn(false);
        $paid->method('getData')->willReturnMap([['base_giftcard_amount', null, 20.0]]);
        $order = $this->order([$paid]);

        (new Restore($this->giftCards(true)))->execute(new Observer(['order' => $order]));

        $this->assertSame([['ABC', 10.0]], $this->credits);
    }

    /**
     * A credit memo gives back its own gift card amount.
     */
    public function testCreditMemoGivesBackItsAmount(): void
    {
        $creditmemo = $this->createStub(Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($this->order([]));
        $creditmemo->method('getData')->willReturnMap([['base_giftcard_amount', null, 12.5]]);

        (new Restore($this->giftCards(true)))->execute(new Observer(['creditmemo' => $creditmemo]));

        $this->assertSame([['ABC', 12.5]], $this->credits);
    }

    /**
     * Fake gift card store: debit succeeds or not, credits are recorded.
     *
     * @param bool $debitSucceeds
     * @return GiftCards
     */
    private function giftCards(bool $debitSucceeds): GiftCards
    {
        $giftCards = $this->createStub(GiftCards::class);
        $giftCards->method('debit')->willReturn($debitSucceeds);
        $giftCards->method('credit')->willReturnCallback(function (string $code, float $amount): void {
            $this->credits[] = [$code, $amount];
        });
        return $giftCards;
    }

    /**
     * Order paid with 30 of gift card "ABC".
     *
     * @param array<int, Invoice> $invoices
     * @return Order
     */
    private function order(array $invoices): Order
    {
        $order = $this->createStub(Order::class);
        $order->method('getData')->willReturnMap([
            ['base_giftcard_amount', null, 30.0],
            ['giftcard_code', null, 'ABC'],
        ]);
        $order->method('getInvoiceCollection')->willReturn($invoices);
        return $order;
    }
}
