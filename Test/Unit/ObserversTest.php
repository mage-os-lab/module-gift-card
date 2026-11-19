<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Unit;

use MageOS\GiftCard\Model\GiftCards;
use MageOS\GiftCard\Observer\Redeem;
use MageOS\GiftCard\Observer\Restore;
use MageOS\GiftCard\Observer\Revoke;
use Magento\Directory\Model\Currency;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Tests the balance moves around order placement, cancellation and refunds.
 */
class ObserversTest extends TestCase
{
    /**
     * Credits made on the fake gift card store, as [code, amount, action].
     *
     * @var array<int, array{string, float, string}>
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
            $this->redeem($this->giftCards(false), $quote, $order);
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

        $this->redeem($this->giftCards(true), $quote, $order);

        $this->assertSame(['ABC', 30.0, 33.0], [
            $order->getData('giftcard_code'),
            $order->getData('base_giftcard_amount'),
            $order->getData('giftcard_amount'),
        ]);
    }

    /**
     * Two cards pay in turn; when the second one no longer covers its part, the first debit is given back.
     */
    public function testFailedSecondCardGivesTheFirstDebitBack(): void
    {
        $giftCards = $this->createStub(GiftCards::class);
        $giftCards->method('getBalance')->willReturn(20.0);
        $giftCards->method('debit')->willReturnCallback(fn (string $code) => $code === 'A');
        $giftCards->method('credit')->willReturnCallback(
            function (string $code, float $amount, ?string $order, string $action): bool {
                $this->credits[] = [$code, $amount, $action];
                return true;
            }
        );
        $quote = new DataObject(['giftcard_code' => 'A,B', 'base_giftcard_amount' => 30.0]);

        try {
            $this->redeem($giftCards, $quote, new DataObject());
            $this->fail('The order should be refused.');
        } catch (LocalizedException $e) {
            $this->assertSame([['A', 20.0, GiftCards::CANCELED]], $this->credits);
        }
    }

    /**
     * Before the order is placed the cards are only noted: an error raised meanwhile has nothing to give back.
     */
    public function testCardsAreDebitedOnlyWhenTheOrderIsPlaced(): void
    {
        $giftCards = $this->createMock(GiftCards::class);
        $giftCards->expects($this->never())->method('debit');
        $order = new DataObject();
        $quote = new DataObject(['giftcard_code' => 'ABC', 'base_giftcard_amount' => 30.0]);

        (new Redeem($giftCards))->execute(new Observer(['quote' => $quote, 'order' => $order]));

        $this->assertNull($order->getData('giftcard_code'));
    }

    /**
     * A failed checkout gives back only what the history shows was debited, even with a single card.
     */
    public function testFailedCheckoutGivesBackOnlyWhatWasDebited(): void
    {
        $giftCards = $this->giftCards(true);
        $giftCards->method('getUsedByOrder')->willReturn(0.0);

        (new Restore($giftCards))->execute(new Observer(['order' => $this->order([]), 'quote' => new DataObject()]));

        $this->assertSame([], $this->credits);
    }

    /**
     * Money given back to a disabled card is noted on the order.
     */
    public function testCreditToADisabledCardIsNotedOnTheOrder(): void
    {
        $giftCards = $this->createStub(GiftCards::class);
        $giftCards->method('credit')->willReturn(false);
        $giftCards->method('mask')->willReturnCallback(fn (string $code) => '…' . substr($code, -4));
        $order = $this->order([]);
        $order->method('getId')->willReturn(5);
        $order->method('getBaseCurrency')->willReturn($this->currency());
        $comments = [];
        $order->method('addCommentToStatusHistory')->willReturnCallback(
            function (string $comment) use (&$comments, $order) {
                $comments[] = $comment;
                return $order;
            }
        );

        (new Restore($giftCards))->execute(new Observer(['order' => $order]));

        $this->assertCount(1, $comments);
        $this->assertStringContainsString('…' . 'ABC', $comments[0]);
    }

    /**
     * A credit memo disables the cards of the refunded line and notes those already spent.
     */
    public function testCreditMemoDisablesTheCardsOfTheRefundedLine(): void
    {
        $giftCards = $this->createMock(GiftCards::class);
        $giftCards->expects($this->once())->method('revoke')->with(42, 2)
            ->willReturn([['code' => 'ABCDEF1234', 'balance' => 10.0, 'initial_balance' => 25.0]]);
        $giftCards->method('mask')->willReturnCallback(fn (string $code) => '…' . substr($code, -4));
        $order = $this->createStub(Order::class);
        $order->method('getBaseCurrency')->willReturn($this->currency());
        $comments = [];
        $order->method('addCommentToStatusHistory')->willReturnCallback(
            function (string $comment) use (&$comments, $order) {
                $comments[] = $comment;
                return $order;
            }
        );
        $item = new DataObject(['qty' => 2, 'order_item_id' => 42, 'order_item' => new DataObject()]);
        $creditmemo = $this->createStub(Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getAllItems')->willReturn([$item]);

        (new Revoke($giftCards))->execute(new Observer(['creditmemo' => $creditmemo]));

        $this->assertCount(1, $comments);
        $this->assertStringContainsString('…1234', $comments[0]);
    }

    /**
     * A credit memo on an order paid with two cards refills the last one first, up to what it paid.
     */
    public function testCreditMemoRefillsTheLastCardFirst(): void
    {
        $giftCards = $this->createStub(GiftCards::class);
        $giftCards->method('getUsedByOrder')->willReturnCallback(fn (string $code) => $code === 'B' ? 5.0 : 25.0);
        $giftCards->method('credit')->willReturnCallback(
            function (string $code, float $amount, ?string $order, string $action): bool {
                $this->credits[] = [$code, $amount, $action];
                return true;
            }
        );
        $order = $this->createStub(Order::class);
        $order->method('getData')->willReturnMap([['giftcard_code', null, 'A,B']]);
        $creditmemo = $this->createStub(Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getData')->willReturnMap([['base_giftcard_amount', null, 12.0]]);

        (new Restore($giftCards))->execute(new Observer(['creditmemo' => $creditmemo]));

        $this->assertSame([['B', 5.0, GiftCards::REFUNDED], ['A', 7.0, GiftCards::REFUNDED]], $this->credits);
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

        $this->assertSame([['ABC', 10.0, GiftCards::CANCELED]], $this->credits);
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

        $this->assertSame([['ABC', 12.5, GiftCards::REFUNDED]], $this->credits);
    }

    /**
     * Run Redeem as core does: once before the order is placed, then while it is placed.
     *
     * @param GiftCards $giftCards
     * @param DataObject $quote
     * @param DataObject $order
     */
    private function redeem(GiftCards $giftCards, DataObject $quote, DataObject $order): void
    {
        $redeem = new Redeem($giftCards);
        $redeem->execute(new Observer(['quote' => $quote, 'order' => $order]));
        $redeem->execute(new Observer(['order' => $order]));
    }

    /**
     * Base currency that formats amounts as plain numbers.
     */
    private function currency(): Currency
    {
        $currency = $this->createStub(Currency::class);
        $currency->method('formatTxt')->willReturnCallback(fn ($amount) => (string) $amount);
        return $currency;
    }

    /**
     * Fake gift card store: debit succeeds or not, credits are recorded with their history action.
     *
     * @param bool $debitSucceeds
     * @return GiftCards&Stub
     */
    private function giftCards(bool $debitSucceeds): GiftCards&Stub
    {
        $giftCards = $this->createStub(GiftCards::class);
        $giftCards->method('getBalance')->willReturn(50.0);
        $giftCards->method('debit')->willReturn($debitSucceeds);
        $giftCards->method('credit')->willReturnCallback(
            function (string $code, float $amount, ?string $order, string $action): bool {
                $this->credits[] = [$code, $amount, $action];
                return true;
            }
        );
        return $giftCards;
    }

    /**
     * Order paid with 30 of gift card "ABC".
     *
     * @param array<int, Invoice> $invoices
     * @return Order&Stub
     */
    private function order(array $invoices): Order&Stub
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
