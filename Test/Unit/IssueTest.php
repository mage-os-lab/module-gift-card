<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Unit;

use MageOS\GiftCard\Model\GiftCards;
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
        $giftCards->method('create')->willReturnCallback(function (): string {
            return 'CODE' . (++$this->created);
        });
        $transportBuilder = $this->createStub(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo'] as $m) {
            $transportBuilder->method($m)->willReturnSelf();
        }
        $transportBuilder->method('getTransport')->willReturn($this->createStub(TransportInterface::class));
        return new Issue(
            $giftCards,
            $transportBuilder,
            $this->createStub(LoggerInterface::class),
            $this->createStub(OrderStatusHistoryRepositoryInterface::class)
        );
    }

    /**
     * Invoice with one gift card line of the given quantity.
     *
     * @param int $qty
     * @return DataObject
     */
    private function invoice(int $qty): DataObject
    {
        $currency = $this->createStub(Currency::class);
        $currency->method('formatTxt')->willReturn('€25.00');
        $order = $this->createStub(Order::class);
        $order->method('getBaseCurrency')->willReturn($currency);
        $order->method('addCommentToStatusHistory')->willReturn($this->createStub(History::class));
        $orderItem = new DataObject(['product' => new DataObject(['is_giftcard' => 1])]);
        $item = new DataObject(['order_item' => $orderItem, 'qty' => $qty, 'base_price_incl_tax' => 25.0]);
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
