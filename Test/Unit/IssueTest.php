<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Unit;

use MageOS\GiftCard\Model\GiftCards;
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
     * Order comments written.
     *
     * @var array<int, string>
     */
    private array $comments = [];

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
     * @return Issue
     */
    private function issue(): Issue
    {
        $giftCards = $this->createStub(GiftCards::class);
        $giftCards->method('create')->willReturnCallback(function (float $balance, array $card): string {
            $this->cards[] = [$balance, $card];
            return 'CODE' . (++$this->created);
        });
        $giftCards->method('mask')->willReturnCallback(fn (string $code) => '…' . substr($code, -4));
        $transportBuilder = $this->createStub(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo'] as $m) {
            $transportBuilder->method($m)->willReturnSelf();
        }
        $transportBuilder->method('getTransport')->willReturn($this->createStub(TransportInterface::class));
        return new Issue(
            $giftCards,
            $transportBuilder,
            $this->createStub(LoggerInterface::class),
            $this->createStub(OrderStatusHistoryRepositoryInterface::class),
            $this->createStub(Options::class)
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
        $order->method('addCommentToStatusHistory')->willReturnCallback(function (string $comment): History {
            $this->comments[] = $comment;
            return $this->createStub(History::class);
        });
        $orderItem = new DataObject(['id' => 9, 'product' => new DataObject(['is_giftcard' => 1])]);
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
