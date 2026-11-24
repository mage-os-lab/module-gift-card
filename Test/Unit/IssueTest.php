<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Unit;

use MageOS\GiftCard\Model\GiftCards;
use MageOS\GiftCard\Model\Issuer;
use MageOS\GiftCard\Model\Options;
use MageOS\GiftCard\Observer\Issue;
use Magento\Directory\Model\Currency;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status\History;
use Magento\Store\Model\App\Emulation;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests when gift card codes are created for a paid invoice.
 */
class IssueTest extends TestCase
{
    /**
     * Number of gift cards created on the fake store.
     *
     * @var int
     */
    private int $created = 0;

    /**
     * Balance and columns of each card created, as [balance, card].
     *
     * @var array<int, array{float, array<string, mixed>}>
     */
    private array $cards = [];

    /**
     * Cards the order line had already issued before the test.
     *
     * @var int
     */
    private int $issuedBefore = 0;

    /**
     * Order comments written.
     *
     * @var array<int, string>
     */
    private array $comments = [];

    /**
     * The buyer's e-mail is written in the order's store view, not in the language of the admin who invoices.
     */
    public function testBuyerEmailIsWrittenInTheStoreOfTheOrder(): void
    {
        $emulation = $this->createMock(Emulation::class);
        $emulation->expects($this->once())->method('startEnvironmentEmulation')->with(3, 'frontend', true);
        $emulation->expects($this->once())->method('stopEnvironmentEmulation');
        $issue = $this->issue($emulation);
        $invoice = $this->invoice(1);

        $issue->execute($this->event('sales_order_invoice_pay', $invoice));
        $issue->execute($this->event('sales_order_invoice_save_commit_after', $invoice));
    }

    /**
     * Paying only marks the invoice; the codes come once it is committed, and only once.
     */
    public function testCodesAreCreatedOnceAfterTheInvoiceIsCommitted(): void
    {
        $issue = $this->issue();
        $invoice = $this->invoice(2);

        $issue->execute($this->event('sales_order_invoice_pay', $invoice));
        $this->assertSame(0, $this->createdCount());

        $issue->execute($this->event('sales_order_invoice_save_commit_after', $invoice));
        $this->assertSame(2, $this->createdCount());

        $issue->execute($this->event('sales_order_invoice_save_commit_after', $invoice));
        $this->assertSame(2, $this->createdCount());
    }

    /**
     * A discounted gift card is worth what was paid for it, and each card knows its order line.
     * The order comment holds only the end of each code.
     */
    public function testCardIsWorthWhatWasPaidAndKnowsItsLine(): void
    {
        $issue = $this->issue();
        $invoice = $this->invoice(2, 10.0);

        $issue->execute($this->event('sales_order_invoice_pay', $invoice));
        $issue->execute($this->event('sales_order_invoice_save_commit_after', $invoice));

        $this->assertSame([20.0, 20.0], array_column($this->cards, 0));
        $this->assertSame(9, $this->cards[0][1]['order_item_id']);
        $this->assertStringNotContainsString('CODE1', $this->comments[0]);
        $this->assertStringContainsString('…ODE1', $this->comments[0]);
    }

    /**
     * An invoice paid again creates no second set of cards: never more cards than units invoiced on the line.
     */
    public function testInvoicePaidTwiceIssuesNoMoreCardsThanUnitsInvoiced(): void
    {
        $issue = $this->issue();
        $invoice = $this->invoice(2);

        foreach ([1, 2] as $time) {
            $issue->execute($this->event('sales_order_invoice_pay', $invoice));
            $issue->execute($this->event('sales_order_invoice_save_commit_after', $invoice));
        }

        $this->assertSame(2, $this->createdCount());
    }

    /**
     * When fewer cards than units are left to issue, each is still worth one unit, not the whole line.
     */
    public function testCardOfAPartlyIssuedLineIsWorthOneUnit(): void
    {
        $this->issuedBefore = 2;
        $issue = $this->issue();
        $invoice = $this->invoice(3);

        $issue->execute($this->event('sales_order_invoice_pay', $invoice));
        $issue->execute($this->event('sales_order_invoice_save_commit_after', $invoice));

        $this->assertSame([25.0], array_column($this->cards, 0));
    }

    /**
     * The order line remembers that it sold a gift card: unflagging or deleting the product before the
     * invoice is paid still issues the card.
     */
    public function testOrderLineRemembersItSoldAGiftCard(): void
    {
        $issue = $this->issue();
        $invoice = $this->invoice(1);
        $invoice->getAllItems()[0]->getData('order_item')->addData(['is_giftcard' => '1', 'product' => null]);

        $issue->execute($this->event('sales_order_invoice_pay', $invoice));
        $issue->execute($this->event('sales_order_invoice_save_commit_after', $invoice));

        $this->assertSame(1, $this->createdCount());
    }

    /**
     * The cron hands over how many cards each line still lacks: no more are issued, and the count goes down.
     */
    public function testNoMoreCardsThanTheLineLacks(): void
    {
        $missing = [9 => 1];

        $issued = $this->issuer()->issue($this->invoice(3), $missing);

        $this->assertSame([1, [9 => 0], [25.0]], [$issued, $missing, array_column($this->cards, 0)]);
    }

    /**
     * Saving an invoice that was not paid in this request creates nothing.
     */
    public function testCommitWithoutPaymentCreatesNothing(): void
    {
        $this->issue()->execute($this->event('sales_order_invoice_save_commit_after', $this->invoice(1)));

        $this->assertSame(0, $this->createdCount());
    }

    /**
     * Gift cards created so far; impure because the count changes inside the stub callbacks.
     *
     * @phpstan-impure
     *
     * @return int
     */
    private function createdCount(): int
    {
        return $this->created;
    }

    /**
     * Issue observer with a fake gift card store and mailer.
     *
     * @param Emulation|null $emulation
     * @return Issue
     */
    private function issue(?Emulation $emulation = null): Issue
    {
        return new Issue($this->issuer($emulation));
    }

    /**
     * Issuer with a fake gift card store and mailer.
     *
     * @param Emulation|null $emulation
     * @return Issuer
     */
    private function issuer(?Emulation $emulation = null): Issuer
    {
        $giftCards = $this->createStub(GiftCards::class);
        $giftCards->method('create')->willReturnCallback(function (float $balance, array $card): string {
            $this->cards[] = [$balance, $card];
            return 'CODE' . (++$this->created);
        });
        $giftCards->method('countByOrderItem')->willReturnCallback(fn (): int => $this->created + $this->issuedBefore);
        $giftCards->method('mask')->willReturnCallback(fn (string $code) => '…' . substr($code, -4));
        $transportBuilder = $this->createStub(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo'] as $m) {
            $transportBuilder->method($m)->willReturnSelf();
        }
        $transportBuilder->method('getTransport')->willReturn($this->createStub(TransportInterface::class));
        return new Issuer(
            $giftCards,
            $transportBuilder,
            $this->createStub(LoggerInterface::class),
            $this->createStub(OrderStatusHistoryRepositoryInterface::class),
            $this->createStub(Options::class),
            $emulation ?? $this->createStub(Emulation::class)
        );
    }

    /**
     * Invoice with one gift card line of the given quantity, 25 a unit before the discount.
     *
     * @param int $qty
     * @param float $discount on the whole line
     * @return DataObject
     */
    private function invoice(int $qty, float $discount = 0.0): DataObject
    {
        $currency = $this->createStub(Currency::class);
        $currency->method('formatTxt')->willReturn('€25.00');
        $order = $this->createStub(Order::class);
        $order->method('getBaseCurrency')->willReturn($currency);
        $order->method('getStoreId')->willReturn(3);
        $order->method('addCommentToStatusHistory')->willReturnCallback(function (string $comment): History {
            $this->comments[] = $comment;
            return $this->createStub(History::class);
        });
        $orderItem = new DataObject([
            'id' => 9,
            'qty_invoiced' => $qty,
            'product' => new DataObject(['is_giftcard' => 1]),
        ]);
        $item = new DataObject([
            'order_item' => $orderItem,
            'qty' => $qty,
            'base_row_total_incl_tax' => 25.0 * $qty,
            'base_discount_amount' => $discount,
        ]);
        return new class(['order' => $order, 'all_items' => [$item]]) extends DataObject {
            /**
             * @return array<int, DataObject>
             */
            public function getAllItems(): array
            {
                return $this->getData('all_items');
            }
        };
    }

    /**
     * Observer for the given event name and invoice.
     *
     * @param string $name
     * @param DataObject $invoice
     * @return Observer
     */
    private function event(string $name, DataObject $invoice): Observer
    {
        return new Observer(['event' => new Event(['name' => $name]), 'invoice' => $invoice]);
    }
}
